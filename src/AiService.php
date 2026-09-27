<?php

declare(strict_types=1);

namespace AiWorkflow;

use AiWorkflow\Enums\FinishReason;
use AiWorkflow\Events\AiWorkflowRequestCompleted;
use AiWorkflow\Events\AiWorkflowRequestFailed;
use AiWorkflow\Exceptions\AiWorkflowException;
use AiWorkflow\Exceptions\HttpErrorDetails;
use AiWorkflow\Exceptions\StructuredDataRequestException;
use AiWorkflow\Exceptions\StructuredDecodingException;
use AiWorkflow\Exceptions\StructuredValidationException;
use AiWorkflow\Exceptions\UnexpectedFinishReasonException;
use AiWorkflow\Gateway\LlmClient;
use AiWorkflow\Gateway\ProviderFactory;
use AiWorkflow\Gateway\StructuredCall;
use AiWorkflow\Gateway\TextCall;
use AiWorkflow\Messages\AssistantMessage;
use AiWorkflow\Messages\Message;
use AiWorkflow\Messages\UserMessage;
use AiWorkflow\Middleware\AiWorkflowContext;
use AiWorkflow\Middleware\AiWorkflowMiddleware;
use AiWorkflow\Models\AiWorkflowExecution;
use AiWorkflow\Models\AiWorkflowRequest;
use AiWorkflow\Responses\ResponseMeta;
use AiWorkflow\Responses\StructuredResponse;
use AiWorkflow\Responses\TextResponse;
use AiWorkflow\Responses\Usage;
use AiWorkflow\Schema\ResponseSchema;
use AiWorkflow\Streaming\StreamEnd;
use AiWorkflow\Streaming\StreamEvent;
use AiWorkflow\Tools\Tool;
use Closure;
use Exception;
use Generator;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Integrations\CircuitBreaker;
use Integrations\Models\Integration;
use Integrations\Support\FailureClassifier;
use Spatie\LaravelData\Data;
use Throwable;

class AiService
{
    /** @var array<string, mixed> */
    private array $context = [];

    /** @var list<string> */
    private array $tags = [];

    /** @var list<AiWorkflowMiddleware> */
    private array $middleware = [];

    /** @var (Closure(array<string, mixed>): list<Tool>)|null */
    private ?Closure $toolResolver = null;

    private ?AiWorkflowExecution $currentExecution = null;

    public function __construct(
        private readonly AiWorkflowCache $cache,
        private readonly LlmClient $client,
        private readonly ProviderFactory $providers,
    ) {}

    /**
     * Set arbitrary context data that tool resolvers can access.
     *
     * @param  array<string, mixed>  $context
     */
    public function setContext(array $context): void
    {
        $this->context = $context;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Set tags to attach to subsequent AI requests.
     *
     * @param  list<string>  $tags
     */
    public function setTags(array $tags): void
    {
        $this->tags = $tags;
    }

    /**
     * @return list<string>
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * Add a middleware to the instance pipeline.
     */
    public function addMiddleware(AiWorkflowMiddleware $middleware): void
    {
        $this->middleware[] = $middleware;
    }

    /**
     * Remove all instance-level middleware.
     */
    public function clearMiddleware(): void
    {
        $this->middleware = [];
    }

    /**
     * Register a callback that returns the tools available for text requests.
     *
     * @param  Closure(array<string, mixed>): list<Tool>  $resolver
     */
    public function resolveToolsUsing(Closure $resolver): void
    {
        $this->toolResolver = $resolver;
    }

    /**
     * @return list<Tool>
     */
    public function getTools(): array
    {
        if ($this->toolResolver === null) {
            return [];
        }

        return ($this->toolResolver)($this->context);
    }

    /**
     * Start a named execution to group subsequent AI calls.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function startExecution(string $name, array $metadata = []): void
    {
        if ($this->isLoggingEnabled()) {
            $this->currentExecution = AiWorkflowExecution::create([
                'name' => $name,
                'metadata' => $metadata !== [] ? $metadata : null,
            ]);
        }
    }

    /**
     * The execution currently grouping AI calls, if one is active.
     */
    public function currentExecution(): ?AiWorkflowExecution
    {
        return $this->currentExecution;
    }

    /**
     * End the current execution and return it.
     */
    public function endExecution(): ?AiWorkflowExecution
    {
        $execution = $this->currentExecution;
        $this->currentExecution = null;

        return $execution;
    }

    /**
     * Reset all mutable state on the service instance.
     */
    public function flush(): void
    {
        $this->context = [];
        $this->tags = [];
        $this->middleware = [];
        $this->toolResolver = null;
        $this->currentExecution = null;
    }

    /**
     * Send messages to the AI with conversation history and tools.
     *
     * @param  Collection<int, Message>  $messages
     */
    public function sendMessages(
        Collection $messages,
        PromptData $prompt,
        ?PromptData $extraContext = null,
        ?int $steps = null,
    ): TextResponse {
        [$provider, $model] = PromptData::parseModelIdentifier($prompt->model);
        $extraPrompt = $extraContext !== null ? $extraContext->prompt : '';
        $systemPrompt = trim($extraPrompt."\n\n".$prompt->prompt);
        $steps ??= $this->maxSteps();

        $context = new AiWorkflowContext(
            messages: array_values($messages->all()),
            prompt: $prompt,
            systemPrompt: $systemPrompt,
            method: 'sendMessages',
        );

        $startTime = microtime(true);
        $providerResponse = null;

        try {
            // Resolve before the cache check so a managed-provider
            // misconfiguration (missing or duplicate Integration row) fails
            // loudly even on a cache hit, and inside the try so the failure is
            // logged and dispatched like any other.
            $integration = $this->providers->integration($provider);

            $cached = $this->getCachedTextResponse($provider, $model, $systemPrompt, $messages->all(), $prompt);
            if ($cached !== null) {
                return $cached;
            }

            $maxTokens = $prompt->maxTokens ?? $this->maxTokens()['text'];

            $context = $this->runThroughMiddleware($context, function (AiWorkflowContext $ctx) use ($prompt, $provider, $model, $steps, $maxTokens, $integration, &$providerResponse): AiWorkflowContext {
                $call = new TextCall(
                    $provider,
                    $model,
                    $ctx->systemPrompt,
                    $ctx->messages,
                    $this->getTools(),
                    $steps,
                    $maxTokens,
                    $prompt->resolveReasoningOptions($provider, $maxTokens),
                );

                $ctx->response = $this->client->text($call, $integration);
                $providerResponse = $ctx->response;

                return $ctx;
            });

            /** @var TextResponse $response */
            $response = $context->response;
            $durationMs = (microtime(true) - $startTime) * 1000;

            $this->reportDegradedFinishReason($response->finishReason, $prompt, 'sendMessages');
            $this->logRequest($prompt, 'sendMessages', $provider, $model, $context->systemPrompt, $context->messages, $durationMs, textResponse: $response);
        } catch (Throwable $exception) {
            $this->recordProviderUsage($exception, $providerResponse?->usage);

            $durationMs = (microtime(true) - $startTime) * 1000;
            $this->logRequest($prompt, 'sendMessages', $provider, $model, $context->systemPrompt, $context->messages, $durationMs, textResponse: $providerResponse, error: $exception);
            $this->dispatchFailedEvent($prompt, 'sendMessages', $model, $exception, $durationMs);

            throw $exception;
        }

        $this->dispatchCompletedEvent($prompt, 'sendMessages', $model, $response->finishReason, $response->usage, $durationMs);
        $this->cacheTextResponse($provider, $model, $systemPrompt, $messages->all(), $prompt, $response);

        return $response;
    }

    /**
     * Send messages to the AI and get structured output.
     *
     * @param  Collection<int, Message>  $messages
     * @param  string|null  $modelOverride  Optional model identifier to use instead of the prompt's model (must be in provider:model format).
     */
    public function sendStructuredMessages(
        Collection $messages,
        PromptData $prompt,
        ResponseSchema $schema,
        ?string $modelOverride = null,
    ): StructuredResponse {
        return $this->requestStructuredMessages($messages, $prompt, $schema, $modelOverride);
    }

    /**
     * @param  Collection<int, Message>  $messages
     * @param  (Closure(Usage): void)|null  $onProviderUsage  Not called on a cache hit.
     */
    private function requestStructuredMessages(
        Collection $messages,
        PromptData $prompt,
        ResponseSchema $schema,
        ?string $modelOverride,
        bool $cacheResponse = true,
        ?Closure $onProviderUsage = null,
    ): StructuredResponse {
        $effectiveModelIdentifier = $modelOverride ?? $prompt->model;
        [$provider, $model] = PromptData::parseModelIdentifier($effectiveModelIdentifier);

        $context = new AiWorkflowContext(
            messages: array_values($messages->all()),
            prompt: $prompt,
            systemPrompt: $prompt->prompt,
            method: 'sendStructuredMessages',
            schema: $schema,
        );

        $startTime = microtime(true);
        $providerResponse = null;

        try {
            // Resolve before the cache check so a managed-provider
            // misconfiguration fails loudly even on a cache hit, and inside the
            // try so the failure is logged and dispatched like any other.
            $integration = $this->providers->integration($provider);

            $cached = $this->getCachedStructuredResponse($provider, $model, $prompt->prompt, $messages->all(), $prompt, $schema);
            if ($cached !== null) {
                return $cached;
            }

            $context = $this->runThroughMiddleware($context, function (AiWorkflowContext $ctx) use ($schema, $effectiveModelIdentifier, $integration, &$providerResponse): AiWorkflowContext {
                $ctx->response = $this->executeStructuredRequest(
                    $ctx->messages,
                    $ctx->prompt,
                    $schema,
                    $effectiveModelIdentifier,
                    $ctx->systemPrompt,
                    $integration,
                );
                $providerResponse = $ctx->response;

                return $ctx;
            });

            /** @var StructuredResponse $response */
            $response = $context->response;
            $durationMs = (microtime(true) - $startTime) * 1000;

            $this->reportDegradedFinishReason($response->finishReason, $prompt, 'sendStructuredMessages');
            $this->logRequest($prompt, 'sendStructuredMessages', $provider, $model, $context->systemPrompt, $context->messages, $durationMs, structuredResponse: $response, schema: $schema);
        } catch (StructuredDecodingException $decodingException) {
            $durationMs = (microtime(true) - $startTime) * 1000;
            $this->logRequest($prompt, 'sendStructuredMessages', $provider, $model, $context->systemPrompt, $context->messages, $durationMs, error: $decodingException, schema: $schema);
            $this->dispatchFailedEvent($prompt, 'sendStructuredMessages', $model, $decodingException, $durationMs);

            if ($modelOverride === null && $prompt->fallbackModel !== null) {
                $this->logFallback($prompt);

                return $this->requestStructuredMessages($messages, $prompt, $schema, $prompt->fallbackModel, $cacheResponse, $onProviderUsage);
            }

            throw $decodingException;
        } catch (Throwable $exception) {
            $spent = $this->spentUsage($providerResponse, $exception);

            if ($spent !== null && $onProviderUsage !== null) {
                $onProviderUsage($spent);
            }

            $this->recordProviderUsage($exception, $providerResponse?->usage);

            $durationMs = (microtime(true) - $startTime) * 1000;
            $this->logRequest($prompt, 'sendStructuredMessages', $provider, $model, $context->systemPrompt, $context->messages, $durationMs, structuredResponse: $providerResponse, error: $exception, schema: $schema);
            $this->dispatchFailedEvent($prompt, 'sendStructuredMessages', $model, $exception, $durationMs);

            throw $exception;
        }

        if ($onProviderUsage !== null) {
            $onProviderUsage($response->usage);
        }

        $this->dispatchCompletedEvent($prompt, 'sendStructuredMessages', $model, $response->finishReason, $response->usage, $durationMs);

        if ($cacheResponse) {
            $this->cacheStructuredResponse($provider, $model, $prompt->prompt, $messages->all(), $prompt, $schema, $response);
        }

        return $response;
    }

    /**
     * Send messages with tool-calling first, then extract structured output.
     *
     * The text step is logged via sendMessages(). This method only logs the structured extraction step.
     *
     * @param  Collection<int, Message>  $messages
     */
    public function sendStructuredMessagesWithTools(
        Collection $messages,
        PromptData $prompt,
        ResponseSchema $schema,
    ): StructuredResponse {
        $schemaJson = json_encode($schema->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $message = "Look at the following structure to see what's expected to be retrieved:\n\n".$schemaJson;

        /** @var Collection<int, Message> $tempMessages */
        $tempMessages = collect($messages->all());
        $tempMessages->push(new UserMessage($message));
        $textResponse = $this->sendMessages($tempMessages, $prompt);

        $lastMessage = array_slice($textResponse->messages, -1)[0] ?? null;
        if (! $lastMessage instanceof AssistantMessage || $lastMessage->content === '') {
            throw new \RuntimeException('sendStructuredMessagesWithTools: text step did not produce an assistant message');
        }

        $newMessages = [
            new UserMessage(
                $lastMessage->content.
                "\n\n---------\nLook at the above response and then use that as context for you to generate your response in the provided JSON schema."
            ),
        ];

        [$provider, $model] = PromptData::parseModelIdentifier($prompt->model);
        $startTime = microtime(true);
        $providerResponse = null;

        try {
            $response = $this->executeStructuredRequest($newMessages, $prompt, $schema, $prompt->model, null);
            $providerResponse = $response;
            $durationMs = (microtime(true) - $startTime) * 1000;

            $this->reportDegradedFinishReason($response->finishReason, $prompt, 'sendStructuredMessagesWithTools');
            $this->logRequest($prompt, 'sendStructuredMessagesWithTools', $provider, $model, '', $newMessages, $durationMs, structuredResponse: $response, schema: $schema);
        } catch (StructuredDecodingException $decodingException) {
            $durationMs = (microtime(true) - $startTime) * 1000;
            $this->logRequest($prompt, 'sendStructuredMessagesWithTools', $provider, $model, '', $newMessages, $durationMs, error: $decodingException, schema: $schema);
            $this->dispatchFailedEvent($prompt, 'sendStructuredMessagesWithTools', $model, $decodingException, $durationMs);

            if ($prompt->fallbackModel !== null) {
                $this->logFallback($prompt);

                return $this->handleStructuredFallback($prompt, $schema, $newMessages, 'sendStructuredMessagesWithTools', null);
            }

            throw $decodingException;
        } catch (Throwable $exception) {
            $this->recordProviderUsage($exception, $providerResponse?->usage);

            $durationMs = (microtime(true) - $startTime) * 1000;
            $this->logRequest($prompt, 'sendStructuredMessagesWithTools', $provider, $model, '', $newMessages, $durationMs, structuredResponse: $providerResponse, error: $exception, schema: $schema);
            $this->dispatchFailedEvent($prompt, 'sendStructuredMessagesWithTools', $model, $exception, $durationMs);

            throw $exception;
        }

        $this->dispatchCompletedEvent($prompt, 'sendStructuredMessagesWithTools', $model, $response->finishReason, $response->usage, $durationMs);

        return $response;
    }

    /**
     * Send structured messages and return a validated Laravel Data instance with the full response.
     *
     * Generates the schema from the Data class, sends the request, validates
     * the response, and retries with feedback on validation failure.
     *
     * @template T of \Spatie\LaravelData\Data
     *
     * @param  Collection<int, Message>  $messages
     * @param  class-string<T>  $dataClass
     */
    public function sendStructuredData(
        Collection $messages,
        PromptData $prompt,
        string $dataClass,
        int $maxAttempts = 3,
    ): StructuredDataResult {
        if (! class_exists(Data::class)) {
            throw new \RuntimeException('spatie/laravel-data is required to use sendStructuredData(). Install it with: composer require spatie/laravel-data');
        }

        $schema = SchemaBuilder::fromDataClass($dataClass);
        [$provider, $model] = PromptData::parseModelIdentifier($prompt->model);

        /** @var Collection<int, Message> $attemptMessages */
        $attemptMessages = new Collection($messages->all());
        $usage = Usage::zero();

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $providerUsage = null;
            $recordProviderUsage = function (Usage $attemptUsage) use (&$providerUsage): void {
                $providerUsage = $attemptUsage;
            };

            try {
                $response = $this->requestStructuredMessages($attemptMessages, $prompt, $schema, null, false, $recordProviderUsage);
            } catch (AiWorkflowException $e) {
                $e->recordUsage($usage->add($e->usage() ?? Usage::zero()));

                throw $e;
            } catch (Exception $e) {
                throw new StructuredDataRequestException($e->getMessage(), $attempt, $usage->add($providerUsage ?? Usage::zero()), $e);
            }

            $usage = $usage->add($providerUsage ?? Usage::zero());

            try {
                $payload = SchemaBuilder::stripNullsForDefaultedProperties($dataClass, $response->structured);

                if ($payload === null) {
                    throw new \RuntimeException('The response carried no structured payload.');
                }

                // validateAndCreate() rather than from(), so a Data class can state its own rules
                // (ranges, enums, formats) and have them enforced. from() ignores them. A failure
                // lands in the catch below and is fed back to the model on the next attempt.
                /** @var T */
                $data = $dataClass::validateAndCreate($payload);
            } catch (Throwable $e) {
                if ($attempt === $maxAttempts) {
                    throw new StructuredValidationException($e->getMessage(), $attempt, $e, $usage);
                }

                $attemptMessages = new Collection([
                    ...$messages->all(),
                    new AssistantMessage(json_encode($response->structured, JSON_THROW_ON_ERROR)),
                    new UserMessage("The previous response failed validation: {$e->getMessage()}. Please fix the response and try again."),
                ]);

                continue;
            }

            if ($providerUsage !== null) {
                $this->cacheStructuredResponse($provider, $model, $prompt->prompt, $messages->all(), $prompt, $schema, $response);
            }

            return new StructuredDataResult($data, $response, $usage);
        }

        throw new StructuredValidationException('Max attempts reached', $maxAttempts, usage: $usage);
    }

    /**
     * Add a provider response's token usage to an AiWorkflowException, on top of any usage already recorded on it.
     */
    private function recordProviderUsage(Throwable $exception, ?Usage $providerUsage): void
    {
        if ($providerUsage === null || ! $exception instanceof AiWorkflowException) {
            return;
        }

        $recorded = $exception->usage();
        $exception->recordUsage($recorded === null ? $providerUsage : $recorded->add($providerUsage));
    }

    /**
     * The tokens billed for a request: those of its response or, when there
     * is no response, those of the responses rejected for their finish reason.
     */
    private function spentUsage(TextResponse|StructuredResponse|null $response, ?Throwable $error): ?Usage
    {
        if ($response !== null) {
            return $response->usage;
        }

        return $error instanceof UnexpectedFinishReasonException ? $error->usage : null;
    }

    /**
     * Stream messages from the AI as a generator of events.
     *
     * Unlike sendMessages(), streaming does not support automatic retries.
     *
     * @param  Collection<int, Message>  $messages
     * @return Generator<int, StreamEvent, mixed, void>
     */
    public function streamMessages(
        Collection $messages,
        PromptData $prompt,
        ?PromptData $extraContext = null,
        ?int $steps = null,
    ): Generator {
        [$provider, $model] = PromptData::parseModelIdentifier($prompt->model);
        $extraPrompt = $extraContext !== null ? $extraContext->prompt : '';
        $systemPrompt = trim($extraPrompt."\n\n".$prompt->prompt);
        $steps ??= $this->maxSteps();

        $startTime = microtime(true);
        $maxTokens = $prompt->maxTokens ?? $this->maxTokens()['text'];

        $integration = null;
        $breaker = null;
        $streamBegan = false;
        $endEvent = null;
        $durationMs = 0.0;

        try {
            // Resolve inside the try so a managed-provider misconfiguration is
            // logged and dispatched, matching sendMessages/sendStructuredMessages.
            $integration = $this->providers->integration($provider);

            $call = new TextCall(
                $provider,
                $model,
                $systemPrompt,
                array_values($messages->all()),
                $this->getTools(),
                $steps,
                $maxTokens,
                $prompt->resolveReasoningOptions($provider, $maxTokens),
            );

            // Streaming bypasses the request executor — a Generator can't flow
            // through its response wrapping/retry — but we still honour the
            // circuit breaker so a known-down OpenRouter fails fast.
            if ($integration !== null) {
                $breaker = new CircuitBreaker($integration);
                $breaker->enforce();
            }

            // Failures past this point are the stream's own outcome; a breaker
            // rejection above is not evidence about OpenRouter's health.
            $streamBegan = true;

            foreach ($this->client->stream($call, $integration) as $event) {
                yield $event;

                if ($event instanceof StreamEnd) {
                    $endEvent = $event;
                    $durationMs = (microtime(true) - $startTime) * 1000;

                    $this->reportDegradedFinishReason($event->finishReason, $prompt, 'streamMessages');
                    $this->logStreamRequest($prompt, $provider, $model, $systemPrompt, $messages->all(), $event, $durationMs);
                }
            }

            // The executor normally settles breaker and health after each
            // request; streaming bypassed it, so settle them here — otherwise a
            // stream claiming the half-open probe slot would leave the circuit
            // stuck until the slot's TTL expires.
            $breaker?->recordSuccess();
            $integration?->recordSuccess();
        } catch (Throwable $exception) {
            if ($streamBegan && $integration !== null && $breaker !== null) {
                $failureClass = FailureClassifier::classify($exception, $integration->provider());
                $breaker->recordFailure($failureClass);
                $integration->recordFailure($failureClass);
            }

            $durationMs = (microtime(true) - $startTime) * 1000;
            $this->logRequest($prompt, 'streamMessages', $provider, $model, $systemPrompt, $messages->all(), $durationMs, error: $exception);
            $this->dispatchFailedEvent($prompt, 'streamMessages', $model, $exception, $durationMs);

            throw $exception;
        }

        if ($endEvent !== null) {
            $this->dispatchCompletedEvent($prompt, 'streamMessages', $model, $endEvent->finishReason, $endEvent->usage, $durationMs);
        }
    }

    /**
     * Send a structured request, through the integration executor when the
     * provider is managed by laravel-integrations.
     *
     * When $systemPrompt is non-null, it is applied to the request.
     * When null, no system prompt is set (used by sendStructuredMessagesWithTools
     * where the second step is purely "parse this text into JSON").
     *
     * Callers that already resolved the integration (e.g. to validate before a
     * cache check) pass it in; when null it is resolved from the model.
     *
     * @param  list<Message>  $messages
     */
    private function executeStructuredRequest(
        array $messages,
        PromptData $prompt,
        ResponseSchema $schema,
        string $modelIdentifier,
        ?string $systemPrompt = null,
        ?Integration $integration = null,
    ): StructuredResponse {
        [$provider, $model] = PromptData::parseModelIdentifier($modelIdentifier);

        $maxTokens = $prompt->maxTokens ?? $this->maxTokens()['structured'];

        $call = new StructuredCall(
            $provider,
            $model,
            $systemPrompt ?? '',
            $messages,
            $schema,
            $maxTokens,
            $prompt->resolveReasoningOptions($provider, $maxTokens),
        );

        return $this->client->structured($call, $integration ?? $this->providers->integration($provider));
    }

    /**
     * Run the context through the middleware pipeline, with the core handler as the innermost step.
     *
     * @param  Closure(AiWorkflowContext): AiWorkflowContext  $core
     */
    private function runThroughMiddleware(AiWorkflowContext $context, Closure $core): AiWorkflowContext
    {
        $middleware = $this->resolveMiddleware();

        if ($middleware === []) {
            return $core($context);
        }

        /** @var AiWorkflowContext */
        return app(Pipeline::class)
            ->send($context)
            ->through($middleware)
            ->then($core);
    }

    /**
     * Build the ordered middleware list from global config + instance middleware.
     *
     * @return list<AiWorkflowMiddleware>
     */
    private function resolveMiddleware(): array
    {
        $configMiddleware = config('ai-workflow.middleware', []);
        $global = is_array($configMiddleware) ? $configMiddleware : [];

        $resolved = [];
        foreach ($global as $className) {
            if (is_string($className)) {
                $instance = app($className);
                if ($instance instanceof AiWorkflowMiddleware) {
                    $resolved[] = $instance;
                }
            }
        }

        return [...$resolved, ...$this->middleware];
    }

    /**
     * @return array{text: int, structured: int}
     */
    private function maxTokens(): array
    {
        $config = config('ai-workflow.max_tokens');
        if (! is_array($config)) {
            return ['text' => 16_384, 'structured' => 32_768];
        }

        return [
            'text' => is_int($config['text'] ?? null) ? $config['text'] : 16_384,
            'structured' => is_int($config['structured'] ?? null) ? $config['structured'] : 32_768,
        ];
    }

    private function maxSteps(): int
    {
        $steps = config('ai-workflow.max_steps', 15);

        return is_int($steps) ? $steps : 15;
    }

    /**
     * Report a finish reason that leaves the response usable but worth
     * monitoring.
     */
    private function reportDegradedFinishReason(FinishReason $finishReason, PromptData $prompt, string $method): void
    {
        if ($finishReason === FinishReason::Length || $finishReason === FinishReason::ContentFilter) {
            report(new \RuntimeException("Unexpected AI finish reason: {$finishReason->value} in {$method} using prompt {$prompt->id}"));
        }
    }

    private function isLoggingEnabled(): bool
    {
        return (bool) config('ai-workflow.logging.enabled');
    }

    /**
     * Merge prompt-level tags with service-level tags, deduplicated.
     *
     * @return list<string>|null
     */
    private function resolveTags(PromptData $prompt): ?array
    {
        $merged = array_values(array_unique([...$prompt->tags, ...$this->tags]));

        return $merged !== [] ? $merged : null;
    }

    /**
     * Log a request to the database if logging is enabled.
     *
     * @param  array<int, Message>  $messages
     */
    private function logRequest(
        PromptData $prompt,
        string $method,
        string $provider,
        string $model,
        string $systemPrompt,
        array $messages,
        float $durationMs,
        ?TextResponse $textResponse = null,
        ?StructuredResponse $structuredResponse = null,
        ?ResponseSchema $schema = null,
        ?Throwable $error = null,
    ): void {
        if (! $this->isLoggingEnabled()) {
            return;
        }

        $httpDetails = $this->extractHttpDetails($error);
        $usage = $this->spentUsage($textResponse ?? $structuredResponse, $error);

        AiWorkflowRequest::create([
            'execution_id' => $this->currentExecution?->id,
            'prompt_id' => $prompt->id,
            'method' => $method,
            'provider' => $provider,
            'model' => $model,
            'system_prompt' => $systemPrompt,
            'messages' => MessageSerializer::serialize($messages),
            'response_text' => $textResponse?->text,
            'structured_response' => $structuredResponse?->structured,
            'finish_reason' => $textResponse?->finishReason->value ?? $structuredResponse?->finishReason->value,
            'input_tokens' => $usage?->inputTokens,
            'output_tokens' => $usage?->outputTokens,
            'thought_tokens' => $usage?->thoughtTokens,
            'cache_read_tokens' => $usage?->cacheReadTokens,
            'cache_write_tokens' => $usage?->cacheWriteTokens,
            'duration_ms' => (int) $durationMs,
            'schema' => $schema?->toArray(),
            'schema_name' => $schema?->name(),
            'error' => $error?->getMessage(),
            'error_class' => $error !== null ? $error::class : null,
            'http_status' => $httpDetails['status'],
            'response_body' => $httpDetails['body'],
            'tags' => $this->resolveTags($prompt),
            'template_variables' => $prompt->variables !== [] ? $prompt->variables : null,
        ]);
    }

    /**
     * Extract HTTP status and (sanitized) response body from the exception
     * chain, if any.
     *
     * @return array{status: ?int, body: ?string}
     */
    private function extractHttpDetails(?Throwable $error): array
    {
        $details = HttpErrorDetails::extract($error);

        return [
            'status' => $details['status'],
            'body' => $this->sanitizeResponseBody($details['body']),
        ];
    }

    /**
     * Ensure the response body is safe to store: valid UTF-8 and capped at $limit bytes.
     */
    private function sanitizeResponseBody(?string $body, int $limit = 65536): ?string
    {
        if ($body === null || $body === '') {
            return $body;
        }

        if (! mb_check_encoding($body, 'UTF-8')) {
            $body = mb_convert_encoding($body, 'UTF-8', 'UTF-8');
        }

        if (strlen($body) <= $limit) {
            return $body;
        }

        // Reserve room for the suffix so the stored string stays within the byte cap.
        $suffix = '…[truncated]';

        return mb_strcut($body, 0, max(0, $limit - strlen($suffix)), 'UTF-8').$suffix;
    }

    /**
     * Log a streaming request using the StreamEnd event data.
     *
     * @param  array<int, Message>  $messages
     */
    private function logStreamRequest(
        PromptData $prompt,
        string $provider,
        string $model,
        string $systemPrompt,
        array $messages,
        StreamEnd $endEvent,
        float $durationMs,
    ): void {
        if (! $this->isLoggingEnabled()) {
            return;
        }

        AiWorkflowRequest::create([
            'execution_id' => $this->currentExecution?->id,
            'prompt_id' => $prompt->id,
            'method' => 'streamMessages',
            'provider' => $provider,
            'model' => $model,
            'system_prompt' => $systemPrompt,
            'messages' => MessageSerializer::serialize($messages),
            'response_text' => null,
            'finish_reason' => $endEvent->finishReason->value,
            'input_tokens' => $endEvent->usage->inputTokens,
            'output_tokens' => $endEvent->usage->outputTokens,
            'thought_tokens' => $endEvent->usage->thoughtTokens,
            'cache_read_tokens' => $endEvent->usage->cacheReadTokens,
            'cache_write_tokens' => $endEvent->usage->cacheWriteTokens,
            'duration_ms' => (int) $durationMs,
            'tags' => $this->resolveTags($prompt),
            'template_variables' => $prompt->variables !== [] ? $prompt->variables : null,
        ]);
    }

    /**
     * @param  array<int, Message>  $messages
     */
    private function getCachedTextResponse(string $provider, string $model, string $systemPrompt, array $messages, PromptData $prompt): ?TextResponse
    {
        if ($prompt->cacheTtl === null || ! $this->cache->isEnabled()) {
            return null;
        }

        $data = $this->cache->get($this->cache->generateKey($provider, $model, $systemPrompt, $messages));
        if ($data === null) {
            return null;
        }

        return new TextResponse(
            text: is_string($data['text'] ?? null) ? $data['text'] : '',
            finishReason: $this->cachedFinishReason($data),
            usage: $this->cachedUsage($data),
            meta: $this->cachedMeta($data, $model),
        );
    }

    /**
     * @param  array<int, Message>  $messages
     */
    private function cacheTextResponse(string $provider, string $model, string $systemPrompt, array $messages, PromptData $prompt, TextResponse $response): void
    {
        if ($prompt->cacheTtl === null || ! $this->cache->isEnabled()) {
            return;
        }

        $key = $this->cache->generateKey($provider, $model, $systemPrompt, $messages);
        $this->writeCache($key, [
            'text' => $response->text,
            ...$this->cachePayload($response->finishReason, $response->usage, $response->meta),
        ], $prompt->cacheTtl);
    }

    /**
     * @param  array<int, Message>  $messages
     */
    private function getCachedStructuredResponse(string $provider, string $model, string $systemPrompt, array $messages, PromptData $prompt, ResponseSchema $schema): ?StructuredResponse
    {
        if ($prompt->cacheTtl === null || ! $this->cache->isEnabled()) {
            return null;
        }

        $data = $this->cache->get($this->cache->generateKey($provider, $model, $systemPrompt, $messages, $schema));
        if ($data === null) {
            return null;
        }

        $structured = is_array($data['structured'] ?? null) ? $data['structured'] : [];

        return new StructuredResponse(
            structured: $structured,
            text: json_encode($structured, JSON_THROW_ON_ERROR),
            finishReason: $this->cachedFinishReason($data),
            usage: $this->cachedUsage($data),
            meta: $this->cachedMeta($data, $model),
        );
    }

    /**
     * @param  array<int, Message>  $messages
     */
    private function cacheStructuredResponse(string $provider, string $model, string $systemPrompt, array $messages, PromptData $prompt, ResponseSchema $schema, StructuredResponse $response): void
    {
        if ($prompt->cacheTtl === null || ! $this->cache->isEnabled()) {
            return;
        }

        $key = $this->cache->generateKey($provider, $model, $systemPrompt, $messages, $schema);
        $this->writeCache($key, [
            'structured' => $response->structured,
            ...$this->cachePayload($response->finishReason, $response->usage, $response->meta),
        ], $prompt->cacheTtl);
    }

    /**
     * The cached fields shared by text and structured responses. The key
     * names must match the entries that 6.x cached, which
     * AiWorkflowCacheTest::test_an_entry_cached_by_6x_still_reads checks.
     *
     * @return array<string, mixed>
     */
    private function cachePayload(FinishReason $finishReason, Usage $usage, ResponseMeta $meta): array
    {
        return [
            'finish_reason' => $finishReason->value,
            'usage' => [
                'prompt_tokens' => $usage->inputTokens,
                'completion_tokens' => $usage->outputTokens,
                'thought_tokens' => $usage->thoughtTokens,
            ],
            'meta' => [
                'id' => $meta->id,
                'model' => $meta->model,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function cachedFinishReason(array $data): FinishReason
    {
        $value = $data['finish_reason'] ?? null;

        return (is_string($value) ? FinishReason::tryFrom($value) : null) ?? FinishReason::Stop;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function cachedUsage(array $data): Usage
    {
        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];

        return new Usage(
            inputTokens: is_int($usage['prompt_tokens'] ?? null) ? $usage['prompt_tokens'] : 0,
            outputTokens: is_int($usage['completion_tokens'] ?? null) ? $usage['completion_tokens'] : 0,
            thoughtTokens: is_int($usage['thought_tokens'] ?? null) ? $usage['thought_tokens'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function cachedMeta(array $data, string $model): ResponseMeta
    {
        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];

        return new ResponseMeta(
            id: is_string($meta['id'] ?? null) ? $meta['id'] : '',
            model: is_string($meta['model'] ?? null) ? $meta['model'] : $model,
        );
    }

    /**
     * @param  array<string, mixed>  $responseData
     */
    private function writeCache(string $key, array $responseData, int $ttlSeconds): void
    {
        try {
            $this->cache->put($key, $responseData, $ttlSeconds);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Handle fallback to an alternate model after a structured decoding failure.
     *
     * @param  list<Message>  $messages
     */
    private function handleStructuredFallback(
        PromptData $prompt,
        ResponseSchema $schema,
        array $messages,
        string $method,
        ?string $systemPrompt,
    ): StructuredResponse {
        $fallbackModelIdentifier = $prompt->fallbackModel ?? throw new \LogicException('handleStructuredFallback called without a fallback model');
        [$fallbackProvider, $fallbackModel] = PromptData::parseModelIdentifier($fallbackModelIdentifier);

        $fallbackStartTime = microtime(true);
        $providerResponse = null;

        try {
            $response = $this->executeStructuredRequest($messages, $prompt, $schema, $fallbackModelIdentifier, $systemPrompt);
            $providerResponse = $response;
            $fallbackDurationMs = (microtime(true) - $fallbackStartTime) * 1000;

            $this->reportDegradedFinishReason($response->finishReason, $prompt, $method);
            $this->logRequest($prompt, $method, $fallbackProvider, $fallbackModel, $systemPrompt ?? '', $messages, $fallbackDurationMs, structuredResponse: $response, schema: $schema);
        } catch (Throwable $exception) {
            $this->recordProviderUsage($exception, $providerResponse?->usage);

            $fallbackDurationMs = (microtime(true) - $fallbackStartTime) * 1000;
            $this->logRequest($prompt, $method, $fallbackProvider, $fallbackModel, $systemPrompt ?? '', $messages, $fallbackDurationMs, structuredResponse: $providerResponse, error: $exception, schema: $schema);
            $this->dispatchFailedEvent($prompt, $method, $fallbackModel, $exception, $fallbackDurationMs);

            throw $exception;
        }

        $this->dispatchCompletedEvent($prompt, $method, $fallbackModel, $response->finishReason, $response->usage, $fallbackDurationMs);

        return $response;
    }

    private function logFallback(PromptData $prompt): void
    {
        [, $primaryModel] = PromptData::parseModelIdentifier($prompt->model);

        Log::warning('AiWorkflow: Structured decoding failed, switching to fallback model', [
            'prompt_id' => $prompt->id,
            'primary_model' => $primaryModel,
            'fallback_model' => $prompt->fallbackModel,
        ]);
    }

    private function dispatchCompletedEvent(
        PromptData $prompt,
        string $method,
        string $model,
        FinishReason $finishReason,
        Usage $usage,
        float $durationMs,
    ): void {
        AiWorkflowRequestCompleted::dispatch(
            $prompt,
            $method,
            $model,
            $finishReason,
            $usage,
            $durationMs,
            $this->currentExecution?->id,
        );
    }

    private function dispatchFailedEvent(
        PromptData $prompt,
        string $method,
        string $model,
        Throwable $exception,
        float $durationMs,
    ): void {
        AiWorkflowRequestFailed::dispatch(
            $prompt,
            $method,
            $model,
            $exception,
            $durationMs,
            $this->currentExecution?->id,
        );
    }
}

<?php

declare(strict_types=1);

namespace AiWorkflow\Gateway;

use AiWorkflow\Enums\FinishReason;
use AiWorkflow\Exceptions\UnexpectedFinishReasonException;
use AiWorkflow\Exceptions\UnknownToolException;
use AiWorkflow\Exceptions\UpstreamErrorException;
use AiWorkflow\Messages\Message;
use AiWorkflow\Messages\ToolCall;
use AiWorkflow\Messages\ToolResult;
use AiWorkflow\Messages\ToolResultMessage;
use AiWorkflow\Responses\StructuredResponse;
use AiWorkflow\Responses\TextResponse;
use AiWorkflow\Responses\Usage;
use AiWorkflow\Streaming\ReasoningDelta;
use AiWorkflow\Streaming\StreamEnd;
use AiWorkflow\Streaming\StreamEvent;
use AiWorkflow\Streaming\StreamStart;
use AiWorkflow\Streaming\TextDelta;
use AiWorkflow\Streaming\ToolCallEvent;
use AiWorkflow\Streaming\ToolResultEvent;
use AiWorkflow\Tools\Tool;
use Closure;
use Generator;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use Integrations\Models\Integration;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Streaming\Events\Error as LaravelError;
use Laravel\Ai\Streaming\Events\ReasoningDelta as LaravelReasoningDelta;
use Laravel\Ai\Streaming\Events\StreamStart as LaravelStreamStart;
use Laravel\Ai\Streaming\Events\TextDelta as LaravelTextDelta;
use LogicException;
use Throwable;

/**
 * Runs text, structured and streamed calls on laravel/ai's gateways.
 *
 * It runs its own tool loop, one gateway step per HTTP request, instead of
 * laravel/ai's, so that:
 *
 * - the integration executor retries only the failed step, and the tools of
 *   earlier steps do not run again;
 * - OpenRouter reasoning goes back on the assistant turn it came from;
 * - tool errors, unknown tools and the step limit behave as in 6.x.
 *
 * @internal
 */
class LlmClient
{
    /**
     * The endpoint recorded on integration_requests, for both text and
     * structured calls.
     */
    private const ENDPOINT = 'chat/completions';

    public function __construct(
        private readonly ProviderFactory $providers,
    ) {}

    /**
     * @param  bool  $viaExecutor  When false, each request is sent directly, and only the Integration row's API key is used.
     */
    public function text(TextCall $call, ?Integration $integration, bool $viaExecutor = true): TextResponse
    {
        [$provider, $gateway] = $this->providers->resolve($call->provider, $integration);
        $executor = $viaExecutor ? $integration : null;
        $tools = self::toolsByName($call->tools);
        $options = self::options($call->maxTokens, $call->providerOptions);
        $maxSteps = max(1, $call->maxSteps);

        /** @var list<Message> $added */
        $added = [];
        $toolCalls = [];
        $toolResults = [];
        $usage = Usage::zero();
        $stepNumber = 0;

        while (true) {
            $step = $this->send($executor, $call->provider, $this->textStep($call, $provider, $gateway, $tools, $options, $added, $stepNumber, $maxSteps), spent: $usage);

            $usage = $usage->add(ResponseMapper::usage($step->usage));
            $finishReason = ResponseMapper::finishReason($step->finishReason);
            $assistant = MessageMapper::fromLaravelAssistant($call->provider, $step->text, array_values($step->toolCalls), $step->replayBlocks);
            $added[] = $assistant;

            if ($finishReason === FinishReason::ToolCalls && $assistant->toolCalls !== []) {
                $results = self::runTools($tools, $assistant->toolCalls);
                $added[] = new ToolResultMessage($results);
                array_push($toolCalls, ...$assistant->toolCalls);
                array_push($toolResults, ...$results);

                if (++$stepNumber < $maxSteps) {
                    continue;
                }
            }

            return new TextResponse($step->text, $finishReason, $usage, ResponseMapper::meta($step, $gateway), $added, $toolCalls, $toolResults, $step->reasoning);
        }
    }

    /**
     * @param  bool  $viaExecutor  When false, the request is sent directly, and only the Integration row's API key is used.
     */
    public function structured(StructuredCall $call, ?Integration $integration, bool $viaExecutor = true): StructuredResponse
    {
        [$provider, $gateway] = $this->providers->resolve($call->provider, $integration);
        [$instructions, $messages] = MessageMapper::toLaravel($call->provider, $call->systemPrompt, $call->messages);

        // laravel/ai's structured output is not strict, so OpenRouter gets the
        // JSON Schema itself.
        if ($gateway instanceof OpenRouterGateway) {
            $schema = null;
            $options = self::options($call->maxTokens, [
                ...$call->providerOptions,
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => ['name' => $call->schema->name(), 'strict' => true, 'schema' => $call->schema->toArray()],
                ],
                'structured_outputs' => true,
            ]);
        } else {
            $schema = SchemaConverter::toProperties($call->schema->toArray());
            $options = self::options($call->maxTokens, $call->providerOptions);
        }

        $structured = [];

        $step = $this->send(
            $viaExecutor ? $integration : null,
            $call->provider,
            fn (): StepResponse => $gateway->generateTextStep($provider, $call->model, $instructions, $messages, [], $schema, $options, $this->providers->timeout(), new StepContext(0, true)),
            function (StepResponse $step) use ($call, &$structured): void {
                $structured = StructuredOutputDecoder::decode($step->text, $call->provider);
            },
        );

        return new StructuredResponse(
            $structured,
            $step->text,
            ResponseMapper::finishReason($step->finishReason),
            ResponseMapper::usage($step->usage),
            ResponseMapper::meta($step, $gateway),
            $step->reasoning,
        );
    }

    /**
     * A stream bypasses the integration executor, so the caller must record
     * the outcome on the circuit breaker.
     *
     * @return Generator<int, StreamEvent, mixed, void>
     */
    public function stream(TextCall $call, ?Integration $integration): Generator
    {
        [$provider, $gateway] = $this->providers->resolve($call->provider, $integration);
        $tools = self::toolsByName($call->tools);
        $adapters = self::adapters($tools);
        $options = self::options($call->maxTokens, $call->providerOptions);
        $maxSteps = max(1, $call->maxSteps);
        $invocationId = Str::uuid7()->toString();

        /** @var list<Message> $added */
        $added = [];
        $usage = Usage::zero();
        $started = false;
        $stepNumber = 0;

        while (true) {
            [$instructions, $messages] = MessageMapper::toLaravel($call->provider, $call->systemPrompt, [...$call->messages, ...$added]);
            $context = new StepContext($stepNumber, $stepNumber + 1 >= $maxSteps);

            try {
                $events = $gateway->generateStreamStep($invocationId, $provider, $call->model, $instructions, $messages, $adapters, null, $options, $this->providers->timeout(), $context);

                foreach ($events as $event) {
                    if ($event instanceof LaravelError) {
                        throw self::streamError($event, $call->provider);
                    }

                    if ($event instanceof LaravelStreamStart && ! $started) {
                        $started = true;

                        yield new StreamStart($event->model, $event->provider);
                    } elseif ($event instanceof LaravelTextDelta) {
                        yield new TextDelta($event->delta);
                    } elseif ($event instanceof LaravelReasoningDelta) {
                        yield new ReasoningDelta($event->delta);
                    }
                }

                $step = $events->getReturn();
            } catch (Throwable $e) {
                throw ExceptionMapper::map($e, $call->provider);
            }

            if (! $step instanceof StepResponse) {
                throw new UpstreamErrorException("The {$call->provider} stream ended without a response.", $call->provider);
            }

            if ($gateway instanceof OpenRouterGateway) {
                $step->replayBlocks = $gateway->streamedReplayBlocks();
            }

            $unexpected = self::unexpectedFinish($step, $call->provider, $usage);

            if ($unexpected !== null) {
                throw $unexpected;
            }

            $usage = $usage->add(ResponseMapper::usage($step->usage));
            $finishReason = ResponseMapper::finishReason($step->finishReason);
            $assistant = MessageMapper::fromLaravelAssistant($call->provider, $step->text, array_values($step->toolCalls), $step->replayBlocks);
            $added[] = $assistant;

            if ($finishReason === FinishReason::ToolCalls && $assistant->toolCalls !== []) {
                foreach ($assistant->toolCalls as $toolCall) {
                    yield new ToolCallEvent($toolCall);
                }

                $results = self::runTools($tools, $assistant->toolCalls);
                $added[] = new ToolResultMessage($results);

                foreach ($results as $result) {
                    yield new ToolResultEvent($result);
                }

                if (++$stepNumber < $maxSteps) {
                    continue;
                }
            }

            yield new StreamEnd($finishReason, $usage, ResponseMapper::meta($step, $gateway));

            return;
        }
    }

    /**
     * @param  array<string, Tool>  $tools
     * @param  list<Message>  $added
     * @return Closure(): StepResponse
     */
    private function textStep(
        TextCall $call,
        TextProvider $provider,
        StepTextGateway $gateway,
        array $tools,
        TextGenerationOptions $options,
        array $added,
        int $stepNumber,
        int $maxSteps,
    ): Closure {
        [$instructions, $messages] = MessageMapper::toLaravel($call->provider, $call->systemPrompt, [...$call->messages, ...$added]);
        $adapters = self::adapters($tools);
        $context = new StepContext($stepNumber, $stepNumber + 1 >= $maxSteps);

        return fn (): StepResponse => $gateway->generateTextStep($provider, $call->model, $instructions, $messages, $adapters, null, $options, $this->providers->timeout(), $context);
    }

    /**
     * Run one step, through the integration executor when $integration is set.
     * A failure finish reason and any exception from $check are thrown inside
     * the executor, so that it retries the step.
     *
     * @param  Closure(): StepResponse  $request
     * @param  (Closure(StepResponse): void)|null  $check
     * @param  Usage  $spent  The tokens of the call's earlier steps.
     */
    private function send(?Integration $integration, string $provider, Closure $request, ?Closure $check = null, Usage $spent = new Usage): StepResponse
    {
        $step = null;

        $attempt = function () use ($provider, $request, $check, &$step, &$spent): ?Response {
            try {
                $step = $request();
            } catch (Throwable $e) {
                throw ExceptionMapper::map($e, $provider);
            }

            // Every rejected attempt is billed, so the usage accumulates
            // across retries.
            $unexpected = self::unexpectedFinish($step, $provider, $spent);

            if ($unexpected !== null) {
                $spent = $unexpected->usage;

                throw $unexpected;
            }

            if ($check !== null) {
                $check($step);
            }

            return $step->raw;
        };

        if ($integration === null) {
            $attempt();
        } else {
            $integration->request(self::ENDPOINT, 'POST', $attempt, maxAttempts: $this->maxAttempts());
        }

        if (! $step instanceof StepResponse) {
            throw new LogicException('The integration executor returned without running the request.');
        }

        return $step;
    }

    /**
     * The exception for a finish reason that means the provider failed, or
     * null when the step finished normally.
     */
    private static function unexpectedFinish(StepResponse $step, string $provider, Usage $spent): ?UnexpectedFinishReasonException
    {
        $finishReason = ResponseMapper::finishReason($step->finishReason);

        if (! in_array($finishReason, [FinishReason::Unknown, FinishReason::Error, FinishReason::Other], true)) {
            return null;
        }

        return new UnexpectedFinishReasonException($finishReason, $provider, $step->raw?->body(), $spent->add(ResponseMapper::usage($step->usage)));
    }

    private static function streamError(LaravelError $event, string $provider): UpstreamErrorException
    {
        $body = json_encode(['error' => ['code' => $event->type, 'message' => $event->message]]);

        return new UpstreamErrorException(
            "The {$provider} stream failed: {$event->message}",
            $provider,
            $body === false ? null : $body,
            ctype_digit($event->type) ? (int) $event->type : null,
        );
    }

    /**
     * @param  array<string, Tool>  $tools
     * @param  list<ToolCall>  $toolCalls
     * @return list<ToolResult>
     */
    private static function runTools(array $tools, array $toolCalls): array
    {
        return array_map(function (ToolCall $toolCall) use ($tools): ToolResult {
            $tool = $tools[$toolCall->name] ?? throw new UnknownToolException($toolCall->name);

            return new ToolResult($toolCall->id, $toolCall->name, $toolCall->arguments, $tool->handle($toolCall->arguments));
        }, $toolCalls);
    }

    /**
     * @param  list<Tool>  $tools
     * @return array<string, Tool>
     */
    private static function toolsByName(array $tools): array
    {
        $byName = [];

        foreach ($tools as $tool) {
            $byName[$tool->name] = $tool;
        }

        return $byName;
    }

    /**
     * @param  array<string, Tool>  $tools
     * @return list<ToolAdapter>
     */
    private static function adapters(array $tools): array
    {
        return array_values(array_map(fn (Tool $tool): ToolAdapter => new ToolAdapter($tool), $tools));
    }

    /**
     * @param  array<string, mixed>  $providerOptions
     */
    private static function options(?int $maxTokens, array $providerOptions): TextGenerationOptions
    {
        return new TextGenerationOptions(maxTokens: $maxTokens, providerOptions: $providerOptions !== [] ? $providerOptions : null);
    }

    private function maxAttempts(): int
    {
        $times = config('ai-workflow.retry.times');

        return is_int($times) && $times >= 1 ? $times : 3;
    }
}

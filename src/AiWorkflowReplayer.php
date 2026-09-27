<?php

declare(strict_types=1);

namespace AiWorkflow;

use AiWorkflow\Gateway\LlmClient;
use AiWorkflow\Gateway\ProviderFactory;
use AiWorkflow\Gateway\StructuredCall;
use AiWorkflow\Gateway\TextCall;
use AiWorkflow\Messages\Message;
use AiWorkflow\Models\AiWorkflowExecution;
use AiWorkflow\Models\AiWorkflowRequest;
use AiWorkflow\Responses\StructuredResponse;
use AiWorkflow\Responses\TextResponse;
use AiWorkflow\Schema\ResponseSchema;
use AiWorkflow\Schema\SchemaPropertyOrder;
use RuntimeException;

/**
 * Replays recorded requests. Replays bypass the integration executor because
 * AiWorkflowEvalRunner retries them with its own policy.
 */
class AiWorkflowReplayer
{
    public function __construct(
        private readonly PromptService $promptService,
        private readonly LlmClient $client,
        private readonly ProviderFactory $providers,
    ) {}

    /**
     * Replay a recorded request with optional overrides.
     *
     * @param  string|null  $model  Model override in provider:model format.
     */
    public function replay(
        AiWorkflowRequest $request,
        bool $useCurrentPrompts = false,
        ?string $model = null,
    ): TextResponse|StructuredResponse {
        $systemPrompt = $request->system_prompt;
        $replayProvider = $request->provider;
        $replayModel = $request->model;

        // The property is annotated as an array, but Laravel's array cast is
        // json_decode with no type check, so a scalar in this column stays a scalar.
        $storedVariables = $request->template_variables;
        $templateVariables = is_array($storedVariables) ? $storedVariables : [];

        // Loaded even when replaying the recorded text: the reasoning setting
        // lives in the prompt's front matter, not on the request, so a replay
        // without it measures the model at its default effort.
        $prompt = $this->loadPrompt($request->prompt_id, $templateVariables);

        if ($useCurrentPrompts && $prompt instanceof PromptData) {
            $systemPrompt = $prompt->prompt;

            if ($model === null) {
                [$replayProvider, $replayModel] = PromptData::parseModelIdentifier($prompt->model);
            }
        }

        if ($model !== null) {
            [$replayProvider, $replayModel] = PromptData::parseModelIdentifier($model);
        }

        /** @var list<array<string, mixed>> $storedMessages */
        $storedMessages = $request->messages;
        $messages = MessageSerializer::deserialize($storedMessages);

        return match ($request->method) {
            'sendStructuredMessages', 'sendStructuredMessagesWithTools' => $this->replayStructured(
                $replayProvider, $replayModel, $systemPrompt, $messages, $request, $prompt,
            ),
            default => $this->replayText($replayProvider, $replayModel, $systemPrompt, $messages, $prompt),
        };
    }

    /**
     * The prompt behind a recorded request, or null when it cannot be loaded.
     *
     * Prompts get renamed and deleted long after a request was recorded, and an
     * eval run is a long sequence of paid calls. Swallowing the failure costs one
     * replay its current text and reasoning setting; throwing would cost the run.
     *
     * @param  array<string, mixed>  $variables
     */
    private function loadPrompt(string $id, array $variables): ?PromptData
    {
        try {
            return $this->promptService->load($id, $variables);
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * Replay a request across multiple models for comparison.
     *
     * @param  list<string>  $models  Each model in provider:model format.
     * @return array<string, TextResponse|StructuredResponse>
     */
    public function replayAcrossModels(
        AiWorkflowRequest $request,
        array $models,
        bool $useCurrentPrompts = false,
    ): array {
        $results = [];

        foreach ($models as $model) {
            $results[$model] = $this->replay($request, $useCurrentPrompts, $model);
        }

        return $results;
    }

    /**
     * Replay all requests in an execution, in order.
     *
     * @param  string|null  $model  Model override in provider:model format.
     * @return list<TextResponse|StructuredResponse>
     */
    public function replayExecution(
        AiWorkflowExecution $execution,
        bool $useCurrentPrompts = false,
        ?string $model = null,
    ): array {
        /** @var list<AiWorkflowRequest> $requests */
        $requests = AiWorkflowRequest::query()
            ->where('execution_id', $execution->id)
            ->orderBy('id')
            ->get()
            ->all();
        $results = [];

        foreach ($requests as $request) {
            $results[] = $this->replay($request, $useCurrentPrompts, $model);
        }

        return $results;
    }

    /**
     * @param  list<Message>  $messages
     */
    private function replayText(string $provider, string $model, string $systemPrompt, array $messages, ?PromptData $prompt): TextResponse
    {
        $maxTokens = $this->maxTokens('text');

        $call = new TextCall(
            $provider,
            $model,
            $systemPrompt,
            $messages,
            maxTokens: $maxTokens,
            providerOptions: $prompt?->resolveReasoningOptions($provider, $maxTokens) ?? [],
        );

        return $this->client->text($call, $this->providers->integration($provider), viaExecutor: false);
    }

    /**
     * @param  list<Message>  $messages
     */
    private function replayStructured(
        string $provider,
        string $model,
        string $systemPrompt,
        array $messages,
        AiWorkflowRequest $request,
        ?PromptData $prompt,
    ): StructuredResponse {
        $maxTokens = $this->maxTokens('structured');

        $call = new StructuredCall(
            $provider,
            $model,
            $systemPrompt,
            $messages,
            $this->recordedSchema($request),
            $maxTokens,
            $prompt?->resolveReasoningOptions($provider, $maxTokens) ?? [],
        );

        return $this->client->structured($call, $this->providers->integration($provider), viaExecutor: false);
    }

    /**
     * The schema the request was sent with, or a stand-in with a single string
     * property for a request logged without one.
     */
    private function recordedSchema(AiWorkflowRequest $request): ResponseSchema
    {
        $schema = $request->schema;

        if (! is_array($schema)) {
            return new ResponseSchema('replay', [
                'description' => 'Reconstructed schema',
                'type' => 'object',
                'properties' => ['result' => ['description' => 'The result', 'type' => 'string']],
                'required' => ['result'],
                'additionalProperties' => false,
            ]);
        }

        return new ResponseSchema(
            $request->schema_name ?? 'schema',
            $this->restoresPropertyOrder($request) ? SchemaPropertyOrder::restore($schema) : $schema,
        );
    }

    /**
     * Whether to put each object's properties back in the order of its `required` list.
     */
    protected function restoresPropertyOrder(AiWorkflowRequest $request): bool
    {
        return $request->getConnection()->getDriverName() === 'mysql';
    }

    /**
     * @param  'text'|'structured'  $kind
     */
    private function maxTokens(string $kind): int
    {
        $configured = config("ai-workflow.max_tokens.{$kind}");

        return is_int($configured) ? $configured : ($kind === 'text' ? 16_384 : 32_768);
    }
}

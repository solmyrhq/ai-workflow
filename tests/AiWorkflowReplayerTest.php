<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

use AiWorkflow\AiService;
use AiWorkflow\AiWorkflowReplayer;
use AiWorkflow\Gateway\LlmClient;
use AiWorkflow\Gateway\ProviderFactory;
use AiWorkflow\Messages\UserMessage;
use AiWorkflow\Models\AiWorkflowRequest;
use AiWorkflow\PromptData;
use AiWorkflow\PromptService;
use AiWorkflow\Responses\StructuredResponse;
use AiWorkflow\Responses\TextResponse;
use AiWorkflow\Testing\OpenRouterFake;
use AiWorkflow\Tests\Concerns\MakesTestFixtures;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class AiWorkflowReplayerTest extends DatabaseTestCase
{
    use MakesTestFixtures;

    public function test_replay_text_request(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('Original response'), OpenRouterFake::completion('Replayed response'));

        app(AiService::class)->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt());

        $recorded = AiWorkflowRequest::first();
        $this->assertNotNull($recorded);

        $result = app(AiWorkflowReplayer::class)->replay($recorded);

        $this->assertInstanceOf(TextResponse::class, $result);
        $this->assertSame('Replayed response', $result->text);

        $bodies = OpenRouterFake::sentBodies();
        $this->assertSame($bodies[0]['messages'], $bodies[1]['messages']);
    }

    public function test_replays_skip_the_executor_but_use_the_integration_key(): void
    {
        $recorded = $this->recordTextRequest();
        OpenRouterFake::respondWith(OpenRouterFake::completion('Replayed'));

        app(AiWorkflowReplayer::class)->replay($recorded);

        $this->assertDatabaseCount('integration_requests', 0);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-key'));
    }

    public function test_replay_structured_request(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::structured(['answer' => 'original']), OpenRouterFake::structured(['answer' => 'replayed']));

        app(AiService::class)->sendStructuredMessages(collect([new UserMessage('Hello')]), $this->makePrompt(), $this->makeSchema());

        $recorded = AiWorkflowRequest::first();
        $this->assertNotNull($recorded);
        $this->assertSame('sendStructuredMessages', $recorded->method);

        $result = app(AiWorkflowReplayer::class)->replay($recorded);

        $this->assertInstanceOf(StructuredResponse::class, $result);
        $this->assertSame(['answer' => 'replayed'], $result->structured);

        $bodies = OpenRouterFake::sentBodies();
        $this->assertSame($bodies[0]['response_format'], $bodies[1]['response_format']);
    }

    public function test_replay_with_model_override(): void
    {
        $recorded = $this->recordTextRequest();
        $this->assertSame('test-model', $recorded->model);

        OpenRouterFake::respondWith(OpenRouterFake::completion('From new model'));

        $result = app(AiWorkflowReplayer::class)->replay($recorded, model: 'openrouter:different-model');

        $this->assertInstanceOf(TextResponse::class, $result);
        $this->assertSame('From new model', $result->text);
        $this->assertSame('different-model', OpenRouterFake::sentBodies()[0]['model']);
    }

    public function test_replay_on_a_provider_outside_laravel_integrations(): void
    {
        $recorded = $this->recordTextRequest();
        config()->set('ai.providers.anthropic.key', 'anthropic-key');
        Http::fake(['api.anthropic.com/*' => Http::response([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-5',
            'content' => [['type' => 'text', 'text' => 'From Anthropic']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 5, 'output_tokens' => 2],
        ])]);

        $result = app(AiWorkflowReplayer::class)->replay($recorded, model: 'anthropic:claude-sonnet-5');

        $this->assertInstanceOf(TextResponse::class, $result);
        $this->assertSame('From Anthropic', $result->text);
    }

    public function test_replay_applies_the_reasoning_setting_from_the_prompt(): void
    {
        $recorded = AiWorkflowRequest::create([
            'prompt_id' => 'reasoning_effort_prompt',
            'method' => 'sendMessages',
            'provider' => 'openrouter',
            'model' => 'test/model',
            'system_prompt' => 'You are a helpful reasoning assistant.',
            'messages' => [['type' => 'user', 'content' => 'Hello']],
            'finish_reason' => 'stop',
            'duration_ms' => 100,
        ]);

        OpenRouterFake::respondWith(OpenRouterFake::completion('Replayed'));

        app(AiWorkflowReplayer::class)->replay($recorded);

        $this->assertSame(['effort' => 'high'], OpenRouterFake::sentBodies()[0]['reasoning']);
    }

    public function test_replay_applies_the_reasoning_setting_to_a_structured_request(): void
    {
        $recorded = AiWorkflowRequest::create([
            'prompt_id' => 'reasoning_effort_prompt',
            'method' => 'sendStructuredMessages',
            'provider' => 'openrouter',
            'model' => 'test/model',
            'system_prompt' => 'You are a helpful reasoning assistant.',
            'messages' => [['type' => 'user', 'content' => 'Hello']],
            'finish_reason' => 'stop',
            'duration_ms' => 100,
            'schema' => $this->makeSchema()->toArray(),
        ]);

        OpenRouterFake::respondWith(OpenRouterFake::structured(['answer' => 'replayed']));

        app(AiWorkflowReplayer::class)->replay($recorded);

        // Eval runs go through replayStructured(), which builds its request
        // separately from replayText(), so the text test above covers none of it.
        $this->assertSame(['effort' => 'high'], OpenRouterFake::sentBodies()[0]['reasoning']);
    }

    public function test_replay_falls_back_to_the_recorded_prompt_when_the_file_is_gone(): void
    {
        $recorded = AiWorkflowRequest::create([
            'prompt_id' => 'prompt_that_no_longer_exists',
            'method' => 'sendMessages',
            'provider' => 'openrouter',
            'model' => 'test/model',
            'system_prompt' => 'Recorded system prompt.',
            'messages' => [['type' => 'user', 'content' => 'Hello']],
            'finish_reason' => 'stop',
            'duration_ms' => 100,
        ]);

        OpenRouterFake::respondWith(OpenRouterFake::completion('Replayed'));

        $result = app(AiWorkflowReplayer::class)->replay($recorded);

        $this->assertSame('Replayed', $result->text);

        $body = OpenRouterFake::sentBodies()[0];
        $this->assertSame(['role' => 'system', 'content' => 'Recorded system prompt.'], $body['messages'][0]);
        $this->assertArrayNotHasKey('reasoning', $body);
    }

    public function test_replay_with_current_prompts(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('Original'), OpenRouterFake::completion('From current prompt'));

        // Use test_prompt which exists in fixtures
        app(AiService::class)->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt('test_prompt', 'openrouter:old-model'));

        $recorded = AiWorkflowRequest::first();
        $this->assertNotNull($recorded);
        $this->assertSame('old-model', $recorded->model);

        // Replay with current prompts — should load test_prompt from fixtures
        $result = app(AiWorkflowReplayer::class)->replay($recorded, useCurrentPrompts: true);

        $this->assertInstanceOf(TextResponse::class, $result);
        $this->assertSame('From current prompt', $result->text);

        $current = app(PromptService::class)->load('test_prompt');
        $replayed = OpenRouterFake::sentBodies()[1];
        $this->assertSame(PromptData::parseModelIdentifier($current->model)[1], $replayed['model']);
        $this->assertSame(['role' => 'system', 'content' => $current->prompt], $replayed['messages'][0]);
    }

    public function test_replay_with_current_prompts_and_model_override(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('Original'), OpenRouterFake::completion('Override model'));

        app(AiService::class)->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt('test_prompt', 'openrouter:old-model'));

        $recorded = AiWorkflowRequest::first();
        $this->assertNotNull($recorded);

        $result = app(AiWorkflowReplayer::class)->replay($recorded, useCurrentPrompts: true, model: 'openrouter:override-model');

        $this->assertInstanceOf(TextResponse::class, $result);
        $this->assertSame('Override model', $result->text);
        $this->assertSame('override-model', OpenRouterFake::sentBodies()[1]['model']);
    }

    public function test_replay_across_models(): void
    {
        OpenRouterFake::respondWith(
            OpenRouterFake::completion('Original'),
            OpenRouterFake::completion('Model A response'),
            OpenRouterFake::completion('Model B response'),
            OpenRouterFake::completion('Model C response'),
        );

        app(AiService::class)->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt());

        $recorded = AiWorkflowRequest::first();
        $this->assertNotNull($recorded);

        $results = app(AiWorkflowReplayer::class)->replayAcrossModels($recorded, [
            'openrouter:model-a',
            'openrouter:model-b',
            'openrouter:model-c',
        ]);

        $this->assertCount(3, $results);
        $this->assertSame('Model A response', $results['openrouter:model-a']->text);
        $this->assertSame('Model B response', $results['openrouter:model-b']->text);
        $this->assertSame('Model C response', $results['openrouter:model-c']->text);
        $this->assertSame(['test-model', 'model-a', 'model-b', 'model-c'], array_column(OpenRouterFake::sentBodies(), 'model'));
    }

    public function test_replay_execution(): void
    {
        OpenRouterFake::respondWith(
            OpenRouterFake::completion('First'),
            OpenRouterFake::completion('Second'),
            OpenRouterFake::completion('Replay 1'),
            OpenRouterFake::completion('Replay 2'),
        );

        $service = app(AiService::class);
        $service->startExecution('test_workflow');
        $service->sendMessages(collect([new UserMessage('First')]), $this->makePrompt('test_prompt'));
        $service->sendMessages(collect([new UserMessage('Second')]), $this->makePrompt('fallback_prompt', 'openrouter:test/primary-model'));
        $execution = $service->endExecution();

        $this->assertNotNull($execution);
        $this->assertSame(2, $execution->requests()->count());

        $results = app(AiWorkflowReplayer::class)->replayExecution($execution);

        $this->assertCount(2, $results);
        $this->assertSame('Replay 1', $results[0]->text);
        $this->assertSame('Replay 2', $results[1]->text);
    }

    // --- Recorded schemas ---

    public function test_replay_structured_sends_the_recorded_schema_unchanged(): void
    {
        $schema = $this->ticketTriageSchema();

        $sent = $this->replayStructuredWithSchema($schema, 'TicketTriage');

        $this->assertSame(['name' => 'TicketTriage', 'strict' => true, 'schema' => $schema], $sent);
    }

    public function test_replay_structured_falls_back_to_the_name_schema_when_the_row_has_no_schema_name(): void
    {
        $sent = $this->replayStructuredWithSchema($this->ticketTriageSchema());

        $this->assertSame('schema', $sent['name']);
    }

    public function test_replay_structured_restores_property_order_from_required_where_the_database_sorts_keys(): void
    {
        $this->app->instance(AiWorkflowReplayer::class, new class(app(PromptService::class), app(LlmClient::class), app(ProviderFactory::class)) extends AiWorkflowReplayer
        {
            protected function restoresPropertyOrder(AiWorkflowRequest $request): bool
            {
                return true;
            }
        });

        $schema = $this->ticketTriageSchema();

        $sent = $this->replayStructuredWithSchema($this->sortKeysRecursively($schema), 'TicketTriage');

        $this->assertIsArray($sent['schema']);
        $this->assertIsArray($sent['schema']['properties']);
        $this->assertSame(array_keys($schema['properties']), array_keys($sent['schema']['properties']));
        $this->assertIsArray($sent['schema']['properties']['actions']);
        $this->assertSame(['summary', 'due_in_days'], array_keys($sent['schema']['properties']['actions']['items']['properties']));
    }

    public function test_replay_structured_keeps_the_stored_order_elsewhere(): void
    {
        $sorted = $this->sortKeysRecursively($this->ticketTriageSchema());

        $sent = $this->replayStructuredWithSchema($sorted);

        $this->assertSame($sorted, $sent['schema']);
    }

    public function test_replay_structured_without_a_recorded_schema_asks_for_a_single_result(): void
    {
        $sent = $this->replayStructuredWithSchema(null);

        $this->assertSame('replay', $sent['name']);
        $this->assertIsArray($sent['schema']);
        $this->assertSame(['result'], $sent['schema']['required']);
    }

    // --- Template variables for faithful replay ---

    public function test_replay_with_current_prompts_uses_stored_template_variables(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('Original'), OpenRouterFake::completion('Replayed with vars'));

        $prompt = new PromptData(
            id: 'template_prompt',
            model: 'openrouter:old-model',
            prompt: 'You are helping Jane with their Premium subscription.',
            variables: ['customer_name' => 'Jane', 'product' => 'Premium'],
        );
        app(AiService::class)->sendMessages(collect([new UserMessage('Hello')]), $prompt);

        $recorded = AiWorkflowRequest::first();
        $this->assertNotNull($recorded);
        $this->assertSame(['customer_name' => 'Jane', 'product' => 'Premium'], $recorded->template_variables);

        // Replay with current prompts — should re-render the template with stored variables
        $result = app(AiWorkflowReplayer::class)->replay($recorded, useCurrentPrompts: true);

        $this->assertInstanceOf(TextResponse::class, $result);
        $this->assertSame('Replayed with vars', $result->text);

        $systemPrompt = OpenRouterFake::sentBodies()[1]['messages'][0]['content'];
        $this->assertIsString($systemPrompt);
        $this->assertStringContainsString('Jane', $systemPrompt);
    }

    private function recordTextRequest(): AiWorkflowRequest
    {
        return AiWorkflowRequest::create([
            'prompt_id' => 'test',
            'method' => 'sendMessages',
            'provider' => 'openrouter',
            'model' => 'test-model',
            'system_prompt' => 'You are a helpful assistant.',
            'messages' => [['type' => 'user', 'content' => 'Hello']],
            'finish_reason' => 'stop',
            'duration_ms' => 100,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function ticketTriageSchema(): array
    {
        return [
            'description' => 'A support ticket triage',
            'type' => 'object',
            'properties' => [
                'priority' => ['description' => 'How urgent the ticket is', 'enum' => ['low', 'medium', 'high'], 'type' => ['string', 'null']],
                'confidence' => ['description' => 'Confidence in the triage', 'type' => ['number', 'null'], 'maximum' => 1, 'minimum' => 0],
                'needs_human' => ['description' => 'Whether a person must reply', 'type' => 'boolean'],
                'tags' => ['description' => 'Tags that apply', 'type' => 'array', 'items' => ['description' => 'A tag', 'type' => 'string'], 'minItems' => 1],
                'actions' => [
                    'description' => 'Follow-up actions',
                    'type' => 'array',
                    'items' => [
                        'description' => 'A follow-up action',
                        'type' => 'object',
                        'properties' => [
                            'summary' => ['description' => 'What to do', 'type' => 'string'],
                            'due_in_days' => ['description' => 'Days until it is due', 'type' => ['number', 'null']],
                        ],
                        'required' => ['summary', 'due_in_days'],
                        'additionalProperties' => false,
                    ],
                ],
                'assignee' => ['anyOf' => [['description' => 'A queue name', 'type' => 'string'], ['type' => 'null']], 'description' => 'Who should pick it up'],
            ],
            'required' => ['priority', 'confidence', 'needs_human', 'tags', 'actions', 'assignee'],
            'additionalProperties' => false,
        ];
    }

    /**
     * Replay a structured request recorded with the given schema and return
     * the json_schema block that was sent to OpenRouter.
     *
     * @param  array<string, mixed>|null  $schema
     * @return array<array-key, mixed>
     */
    private function replayStructuredWithSchema(?array $schema, ?string $schemaName = null): array
    {
        $request = AiWorkflowRequest::create([
            'prompt_id' => 'test',
            'method' => 'sendStructuredMessages',
            'provider' => 'openrouter',
            'model' => 'test-model',
            'system_prompt' => 'Test.',
            'messages' => [['type' => 'user', 'content' => 'Hello']],
            'finish_reason' => 'stop',
            'duration_ms' => 100,
            'schema' => $schema,
            'schema_name' => $schemaName,
        ]);

        OpenRouterFake::respondWith(OpenRouterFake::structured([]));

        app(AiWorkflowReplayer::class)->replay($request->refresh());

        $sent = OpenRouterFake::sentBodies()[0]['response_format']['json_schema'];
        $this->assertIsArray($sent);

        return $sent;
    }

    private function sortKeysRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $sorted = array_map(fn (mixed $entry): mixed => $this->sortKeysRecursively($entry), $value);

        if (! array_is_list($sorted)) {
            ksort($sorted);
        }

        return $sorted;
    }
}

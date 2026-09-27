<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

use AiWorkflow\Enums\FinishReason;
use AiWorkflow\Events\AiWorkflowRequestCompleted;
use AiWorkflow\Events\AiWorkflowRequestFailed;
use AiWorkflow\Listeners\SentryBreadcrumbListener;
use AiWorkflow\Models\AiWorkflowExecution;
use AiWorkflow\Models\AiWorkflowRequest;
use AiWorkflow\Models\Builders\AiWorkflowExecutionBuilder;
use AiWorkflow\Models\Builders\AiWorkflowRequestBuilder;
use AiWorkflow\PromptData;
use AiWorkflow\Responses\Usage;

class ObservabilityTest extends DatabaseTestCase
{
    // --- Custom query builders ---

    public function test_request_query_returns_custom_builder(): void
    {
        $this->assertInstanceOf(AiWorkflowRequestBuilder::class, AiWorkflowRequest::query());
    }

    public function test_execution_query_returns_custom_builder(): void
    {
        $this->assertInstanceOf(AiWorkflowExecutionBuilder::class, AiWorkflowExecution::query());
    }

    // --- SentryBreadcrumbListener ---

    public function test_sentry_listener_handles_completed_event_without_sentry(): void
    {
        $listener = new SentryBreadcrumbListener;

        $event = new AiWorkflowRequestCompleted(
            prompt: new PromptData(id: 'test', model: 'openrouter:test-model', prompt: 'Test'),
            method: 'sendMessages',
            model: 'test-model',
            finishReason: FinishReason::Stop,
            usage: new Usage(100, 50),
            durationMs: 1234.5,
            executionId: null,
        );

        // Sentry is not installed in this test environment, so the guard clause should no-op.
        $this->assertFalse(function_exists('\Sentry\addBreadcrumb'), 'Sentry should not be installed in test environment');

        $listener->handleCompleted($event);

        // No exception thrown — the guard clause returned early.
        $this->addToAssertionCount(1);
    }

    public function test_sentry_listener_handles_failed_event_without_sentry(): void
    {
        $listener = new SentryBreadcrumbListener;

        $event = new AiWorkflowRequestFailed(
            prompt: new PromptData(id: 'test', model: 'openrouter:test-model', prompt: 'Test'),
            method: 'sendMessages',
            model: 'test-model',
            exception: new \RuntimeException('API error'),
            durationMs: 500.0,
            executionId: null,
        );

        $this->assertFalse(function_exists('\Sentry\addBreadcrumb'), 'Sentry should not be installed in test environment');

        $listener->handleFailed($event);

        $this->addToAssertionCount(1);
    }

    // --- AiWorkflowExecutionBuilder scopes ---

    public function test_execution_scope_recent(): void
    {
        AiWorkflowExecution::create(['name' => 'recent']);

        $old = AiWorkflowExecution::create(['name' => 'old']);
        $old->created_at = now()->subHours(48);
        $old->save();

        $recent = AiWorkflowExecution::query()->recent()->get();
        $this->assertCount(1, $recent);
        $this->assertSame('recent', $recent->first()?->name);
    }

    public function test_execution_scope_recent_with_custom_hours(): void
    {
        $withinRange = AiWorkflowExecution::create(['name' => 'within-range']);
        $withinRange->created_at = now()->subHours(36);
        $withinRange->save();

        $outOfRange = AiWorkflowExecution::create(['name' => 'out-of-range']);
        $outOfRange->created_at = now()->subHours(72);
        $outOfRange->save();

        $recent = AiWorkflowExecution::query()->recent(48)->get();
        $this->assertCount(1, $recent);
        $this->assertSame('within-range', $recent->first()?->name);
    }

    public function test_execution_scope_by_name(): void
    {
        AiWorkflowExecution::create(['name' => 'ticket:work']);
        AiWorkflowExecution::create(['name' => 'ticket:decide']);
        AiWorkflowExecution::create(['name' => 'ticket:work']);

        $results = AiWorkflowExecution::query()->byName('ticket:work')->get();
        $this->assertCount(2, $results);
    }

    // --- AiWorkflowRequestBuilder scopes ---

    public function test_request_scope_by_model(): void
    {
        $this->createRequest(model: 'claude-4');
        $this->createRequest(model: 'gemini-3');
        $this->createRequest(model: 'claude-4');

        $results = AiWorkflowRequest::query()->byModel('claude-4')->get();
        $this->assertCount(2, $results);
    }

    public function test_request_scope_by_model_with_provider_format(): void
    {
        $this->createRequest(model: 'claude-4');
        $this->createRequest(model: 'claude-4', provider: 'anthropic');
        $this->createRequest(model: 'claude-4', provider: 'openrouter');

        // Without provider prefix — matches all providers
        $results = AiWorkflowRequest::query()->byModel('claude-4')->get();
        $this->assertCount(3, $results);

        // With provider:model format — filters on both
        $results = AiWorkflowRequest::query()->byModel('anthropic:claude-4')->get();
        $this->assertCount(1, $results);

        $results = AiWorkflowRequest::query()->byModel('openrouter:claude-4')->get();
        $this->assertCount(2, $results);
    }

    public function test_request_scope_by_prompt(): void
    {
        $this->createRequest(promptId: 'classify');
        $this->createRequest(promptId: 'respond');
        $this->createRequest(promptId: 'classify');

        $results = AiWorkflowRequest::query()->byPrompt('classify')->get();
        $this->assertCount(2, $results);
    }

    public function test_request_scope_errors(): void
    {
        $this->createRequest(error: null);
        $this->createRequest(error: 'API timeout');
        $this->createRequest(error: 'Rate limited');

        $results = AiWorkflowRequest::query()->errors()->get();
        $this->assertCount(2, $results);
    }

    public function test_request_scope_successful(): void
    {
        $this->createRequest(error: null);
        $this->createRequest(error: null);
        $this->createRequest(error: 'API timeout');

        $results = AiWorkflowRequest::query()->successful()->get();
        $this->assertCount(2, $results);
    }

    public function test_request_scope_with_tag(): void
    {
        $this->createRequest(tags: ['classification']);
        $this->createRequest(tags: ['generation']);
        $this->createRequest(tags: ['classification', 'intent']);

        $results = AiWorkflowRequest::query()->withTag('classification')->get();
        $this->assertCount(2, $results);
    }

    public function test_request_scope_with_any_tag(): void
    {
        $this->createRequest(tags: ['classification']);
        $this->createRequest(tags: ['generation']);
        $this->createRequest(tags: ['unrelated']);

        $results = AiWorkflowRequest::query()->withAnyTag(['classification', 'generation'])->get();
        $this->assertCount(2, $results);
    }

    public function test_request_scopes_chain(): void
    {
        $this->createRequest(model: 'claude-4', promptId: 'classify', error: null);
        $this->createRequest(model: 'claude-4', promptId: 'classify', error: 'timeout');
        $this->createRequest(model: 'gemini-3', promptId: 'classify', error: null);

        $results = AiWorkflowRequest::query()
            ->byModel('claude-4')
            ->byPrompt('classify')
            ->successful()
            ->get();

        $this->assertCount(1, $results);
    }

    // --- Helpers ---

    /**
     * @param  list<string>|null  $tags
     */
    private function createRequest(
        string $model = 'test-model',
        string $provider = 'openrouter',
        string $promptId = 'test',
        ?string $error = null,
        ?array $tags = null,
    ): AiWorkflowRequest {
        return AiWorkflowRequest::create([
            'prompt_id' => $promptId,
            'method' => 'sendMessages',
            'provider' => $provider,
            'model' => $model,
            'system_prompt' => 'Test.',
            'messages' => [['type' => 'user', 'content' => 'Hello']],
            'finish_reason' => 'stop',
            'duration_ms' => 100,
            'error' => $error,
            'tags' => $tags,
        ]);
    }
}

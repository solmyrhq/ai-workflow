<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

use AiWorkflow\AiService;
use AiWorkflow\Events\AiWorkflowRequestFailed;
use AiWorkflow\Exceptions\InsufficientCreditsException;
use AiWorkflow\Exceptions\ProviderOverloadedException;
use AiWorkflow\Exceptions\UpstreamErrorException;
use AiWorkflow\Integrations\OpenRouterProvider;
use AiWorkflow\Messages\UserMessage;
use AiWorkflow\Models\AiWorkflowRequest;
use AiWorkflow\PromptData;
use AiWorkflow\Testing\OpenRouterFake;
use AiWorkflow\Tests\Concerns\MakesTestFixtures;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Integrations\Enums\FailureClass;
use Integrations\Models\Integration;
use RuntimeException;

/**
 * End-to-end tests of how OpenRouter calls go through the laravel-integrations
 * executor, and of how their failures are classified.
 */
class IntegrationRoutingTest extends DatabaseTestCase
{
    use MakesTestFixtures;

    private function openRouterIntegration(): Integration
    {
        return Integration::query()->where('provider', 'openrouter')->firstOrFail();
    }

    public function test_billing_error_is_not_retried_and_keeps_breaker_closed(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::error(402, 'Insufficient credits'));

        try {
            app(AiService::class)->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt());
            $this->fail('Expected the 402 to surface.');
        } catch (InsufficientCreditsException $e) {
            $this->assertSame(402, $e->getStatusCode());
        }

        $this->assertCount(1, OpenRouterFake::sentBodies());

        $integration = $this->openRouterIntegration();
        $this->assertSame(0, $integration->consecutive_failures);
        $this->assertSame('healthy', $integration->health_status->value);

        // The AI-domain record still captures the transport detail.
        $request = AiWorkflowRequest::first();
        $this->assertNotNull($request);
        $this->assertSame(402, $request->http_status);
        $this->assertSame(InsufficientCreditsException::class, $request->error_class);
        $this->assertSame('{"error":{"code":402,"message":"Insufficient credits"}}', $request->response_body);
    }

    public function test_server_error_is_retried_up_to_max_attempts(): void
    {
        config()->set('ai-workflow.retry.times', 3);

        OpenRouterFake::respondWith(...array_fill(0, 3, OpenRouterFake::error(503, 'Unavailable')));

        try {
            app(AiService::class)->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt());
            $this->fail('Expected the 503 to surface after exhausting retries.');
        } catch (ProviderOverloadedException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }

        // An Upstream fault is retryable, so it runs maxAttempts times and each
        // failure counts toward the breaker (threshold is higher, so it stays
        // closed here).
        $this->assertCount(3, OpenRouterFake::sentBodies());
        $this->assertSame(3, $this->openRouterIntegration()->consecutive_failures);
    }

    public function test_duplicate_integrations_for_a_provider_fail_loudly(): void
    {
        // The base TestCase already seeds one openrouter integration; a second
        // makes resolution ambiguous, which must throw rather than silently
        // pick one account's credentials and breaker.
        $this->createIntegration(
            providerKey: 'openrouter',
            providerClass: OpenRouterProvider::class,
            credentials: ['api_key' => 'second-key'],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Multiple Integration rows exist for provider 'openrouter'");

        app(AiService::class)->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt());
    }

    public function test_integration_misconfiguration_is_logged_and_dispatched(): void
    {
        Event::fake([AiWorkflowRequestFailed::class]);

        $this->createIntegration(
            providerKey: 'openrouter',
            providerClass: OpenRouterProvider::class,
            credentials: ['api_key' => 'second-key'],
        );

        try {
            app(AiService::class)->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt());
            $this->fail('Expected the duplicate integration to throw.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Multiple Integration rows exist', $e->getMessage());
        }

        // The misconfiguration flows through the normal failure path: a logged
        // request and a dispatched failure event, not a silent throw.
        $request = AiWorkflowRequest::first();
        $this->assertNotNull($request);
        $this->assertStringContainsString('Multiple Integration rows exist', (string) $request->error);
        Event::assertDispatched(AiWorkflowRequestFailed::class);
    }

    public function test_stream_misconfiguration_is_logged_and_dispatched(): void
    {
        Event::fake([AiWorkflowRequestFailed::class]);

        $this->createIntegration(
            providerKey: 'openrouter',
            providerClass: OpenRouterProvider::class,
            credentials: ['api_key' => 'second-key'],
        );

        try {
            foreach (app(AiService::class)->streamMessages(collect([new UserMessage('Hello')]), $this->makePrompt()) as $event) {
                // Drain the generator; resolution throws on first iteration.
            }
            $this->fail('Expected the duplicate integration to throw.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Multiple Integration rows exist', $e->getMessage());
        }

        $request = AiWorkflowRequest::first();
        $this->assertNotNull($request);
        $this->assertStringContainsString('Multiple Integration rows exist', (string) $request->error);
        Event::assertDispatched(AiWorkflowRequestFailed::class);
    }

    public function test_stream_failure_counts_toward_breaker_and_health(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::stream([
            OpenRouterFake::chunk(['role' => 'assistant', 'content' => 'Partial']),
            ['error' => ['code' => 503, 'message' => 'Upstream unavailable']],
        ]));

        try {
            foreach (app(AiService::class)->streamMessages(collect([new UserMessage('Hello')]), $this->makePrompt()) as $event) {
                // Iterate until the stream error is thrown.
            }
            $this->fail('Expected the 503 to surface.');
        } catch (UpstreamErrorException $e) {
            $this->assertSame(503, $e->errorCode);
        }

        // Streaming bypasses the executor, so the service itself must feed
        // the outcome into the shared health/breaker accounting.
        $this->assertSame(1, $this->openRouterIntegration()->consecutive_failures);
    }

    public function test_stream_success_resets_health(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::textStream(['Streamed']));

        $this->openRouterIntegration()->recordFailure(FailureClass::Upstream);
        $this->assertSame(1, $this->openRouterIntegration()->consecutive_failures);

        foreach (app(AiService::class)->streamMessages(collect([new UserMessage('Hello')]), $this->makePrompt()) as $event) {
            // Drain to completion.
        }

        $this->assertSame(0, $this->openRouterIntegration()->consecutive_failures);
    }

    public function test_cache_hit_still_validates_the_managed_integration(): void
    {
        config()->set('ai-workflow.cache.enabled', true);
        config()->set('ai-workflow.cache.store', 'array');

        $prompt = new PromptData(
            id: 'cached',
            model: 'openrouter:test-model',
            prompt: 'You are helpful.',
            cacheTtl: 3600,
        );

        OpenRouterFake::respondWith(OpenRouterFake::completion('Cached'));

        $service = app(AiService::class);

        // Warm the cache with a valid single integration.
        $service->sendMessages(collect([new UserMessage('Hello')]), $prompt);

        // A duplicate introduced while the cache is warm must still be caught:
        // resolution runs before the cache short-circuit.
        $this->createIntegration(
            providerKey: 'openrouter',
            providerClass: OpenRouterProvider::class,
            credentials: ['api_key' => 'second-key'],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Multiple Integration rows exist for provider 'openrouter'");

        $service->sendMessages(collect([new UserMessage('Hello')]), $prompt);
    }

    public function test_unmanaged_provider_skips_the_executor(): void
    {
        config()->set('ai.providers.anthropic.key', 'anthropic-key');
        Http::fake(['api.anthropic.com/*' => Http::response([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-4',
            'content' => [['type' => 'text', 'text' => 'Direct response']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 5, 'output_tokens' => 2],
        ])]);

        $prompt = new PromptData(
            id: 'direct',
            model: 'anthropic:claude-4',
            prompt: 'You are helpful.',
        );

        $response = app(AiService::class)->sendMessages(collect([new UserMessage('Hello')]), $prompt);

        $this->assertSame('Direct response', $response->text);
        $this->assertDatabaseCount('integration_requests', 0);
    }
}

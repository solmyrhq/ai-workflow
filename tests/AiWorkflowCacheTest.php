<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

use AiWorkflow\AiService;
use AiWorkflow\AiWorkflowCache;
use AiWorkflow\Enums\FinishReason;
use AiWorkflow\Messages\UserMessage;
use AiWorkflow\Middleware\AiWorkflowContext;
use AiWorkflow\Middleware\AiWorkflowMiddleware;
use AiWorkflow\Responses\Usage;
use AiWorkflow\Testing\OpenRouterFake;
use AiWorkflow\Tests\Concerns\MakesTestFixtures;
use AiWorkflow\Tests\Fixtures\Data\SentimentData;
use Closure;
use Illuminate\Support\Facades\Exceptions;
use RuntimeException;

class AiWorkflowCacheTest extends TestCase
{
    use MakesTestFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai-workflow.cache.enabled', true);
        config()->set('ai-workflow.cache.store', 'array');
    }

    public function test_deterministic_key_generation(): void
    {
        $cache = app(AiWorkflowCache::class);

        $key1 = $cache->generateKey('openrouter', 'test', 'system', [new UserMessage('Hello')]);
        $key2 = $cache->generateKey('openrouter', 'test', 'system', [new UserMessage('Hello')]);
        $key3 = $cache->generateKey('openrouter', 'test', 'system', [new UserMessage('Different')]);

        $this->assertSame($key1, $key2);
        $this->assertNotSame($key1, $key3);
        $this->assertStringStartsWith('ai_workflow:', $key1);
    }

    public function test_schema_affects_cache_key(): void
    {
        $cache = app(AiWorkflowCache::class);

        $withoutSchema = $cache->generateKey('openrouter', 'test', 'system', [new UserMessage('Hello')]);
        $withSchema = $cache->generateKey('openrouter', 'test', 'system', [new UserMessage('Hello')], $this->makeSchema());

        $this->assertNotSame($withoutSchema, $withSchema);
    }

    public function test_cache_hit_returns_cached_text_response(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('Original response'));

        $service = app(AiService::class);
        $prompt = $this->makePrompt(cacheTtl: 3600);

        $response1 = $service->sendMessages(collect([new UserMessage('Hello')]), $prompt);
        $this->assertSame('Original response', $response1->text);

        $response2 = $service->sendMessages(collect([new UserMessage('Hello')]), $prompt);
        $this->assertSame('Original response', $response2->text);
        $this->assertCount(1, OpenRouterFake::sentBodies());
    }

    public function test_cache_hit_returns_cached_structured_response(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::structured(['answer' => 'cached']));

        $service = app(AiService::class);
        $prompt = $this->makePrompt(cacheTtl: 3600);

        $response1 = $service->sendStructuredMessages(collect([new UserMessage('Hello')]), $prompt, $this->makeSchema());
        $this->assertSame(['answer' => 'cached'], $response1->structured);

        // Cache hit.
        $response2 = $service->sendStructuredMessages(collect([new UserMessage('Hello')]), $prompt, $this->makeSchema());
        $this->assertSame(['answer' => 'cached'], $response2->structured);
        $this->assertCount(1, OpenRouterFake::sentBodies());
    }

    public function test_cache_miss_when_different_messages(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('First'), OpenRouterFake::completion('Second'));

        $service = app(AiService::class);
        $prompt = $this->makePrompt(cacheTtl: 3600);

        $response1 = $service->sendMessages(collect([new UserMessage('Hello')]), $prompt);
        $response2 = $service->sendMessages(collect([new UserMessage('Goodbye')]), $prompt);

        $this->assertSame('First', $response1->text);
        $this->assertSame('Second', $response2->text);
    }

    public function test_cache_skipped_when_no_ttl(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('First'), OpenRouterFake::completion('Second'));

        $service = app(AiService::class);
        $prompt = $this->makePrompt(cacheTtl: null);

        $response1 = $service->sendMessages(collect([new UserMessage('Hello')]), $prompt);
        $response2 = $service->sendMessages(collect([new UserMessage('Hello')]), $prompt);

        $this->assertSame('First', $response1->text);
        $this->assertSame('Second', $response2->text);
    }

    public function test_cache_skipped_when_globally_disabled(): void
    {
        config()->set('ai-workflow.cache.enabled', false);

        OpenRouterFake::respondWith(OpenRouterFake::completion('First'), OpenRouterFake::completion('Second'));

        $service = app(AiService::class);
        $prompt = $this->makePrompt(cacheTtl: 3600);

        $response1 = $service->sendMessages(collect([new UserMessage('Hello')]), $prompt);
        $response2 = $service->sendMessages(collect([new UserMessage('Hello')]), $prompt);

        $this->assertSame('First', $response1->text);
        $this->assertSame('Second', $response2->text);
    }

    public function test_cache_hit_preserves_text_usage_and_meta(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('Response with meta', usage: ['prompt_tokens' => 100, 'completion_tokens' => 50]));

        $service = app(AiService::class);
        $prompt = $this->makePrompt(cacheTtl: 3600);

        $response1 = $service->sendMessages(collect([new UserMessage('Hello')]), $prompt);
        $this->assertSame(100, $response1->usage->inputTokens);
        $this->assertSame(50, $response1->usage->outputTokens);
        $this->assertSame('gen-fake', $response1->meta->id);
        $this->assertSame(OpenRouterFake::MODEL, $response1->meta->model);

        // Cache hit should preserve usage and meta.
        $response2 = $service->sendMessages(collect([new UserMessage('Hello')]), $prompt);
        $this->assertSame('Response with meta', $response2->text);
        $this->assertSame(100, $response2->usage->inputTokens);
        $this->assertSame(50, $response2->usage->outputTokens);
        $this->assertSame('gen-fake', $response2->meta->id);
        $this->assertSame(OpenRouterFake::MODEL, $response2->meta->model);
    }

    public function test_cache_hit_preserves_structured_usage_and_meta(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::structured(['answer' => 'cached'], ['prompt_tokens' => 200, 'completion_tokens' => 100]));

        $service = app(AiService::class);
        $prompt = $this->makePrompt(cacheTtl: 3600);

        $response1 = $service->sendStructuredMessages(collect([new UserMessage('Hello')]), $prompt, $this->makeSchema());
        $this->assertSame(200, $response1->usage->inputTokens);
        $this->assertSame(100, $response1->usage->outputTokens);
        $this->assertSame('gen-fake', $response1->meta->id);

        // Cache hit should preserve usage and meta.
        $response2 = $service->sendStructuredMessages(collect([new UserMessage('Hello')]), $prompt, $this->makeSchema());
        $this->assertSame(['answer' => 'cached'], $response2->structured);
        $this->assertSame(200, $response2->usage->inputTokens);
        $this->assertSame(100, $response2->usage->outputTokens);
        $this->assertSame('gen-fake', $response2->meta->id);
        $this->assertSame(OpenRouterFake::MODEL, $response2->meta->model);
    }

    public function test_an_entry_cached_by_6x_still_reads(): void
    {
        $cache = app(AiWorkflowCache::class);
        $messages = [new UserMessage('Hello')];
        $key = $cache->generateKey('openrouter', 'test-model', 'You are a helpful assistant.', $messages);

        $cache->put($key, [
            'text' => 'Cached before the upgrade',
            'finish_reason' => 'length',
            'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 12, 'thought_tokens' => 4],
            'meta' => ['id' => 'gen-old', 'model' => 'anthropic/claude-sonnet-4'],
        ], 3600);

        $response = app(AiService::class)->sendMessages(collect($messages), $this->makePrompt(cacheTtl: 3600));

        $this->assertSame('Cached before the upgrade', $response->text);
        $this->assertSame(FinishReason::Length, $response->finishReason);
        $this->assertEquals(new Usage(30, 12, thoughtTokens: 4), $response->usage);
        $this->assertSame('gen-old', $response->meta->id);
    }

    public function test_send_structured_data_caches_only_the_validated_answer(): void
    {
        OpenRouterFake::respondWith(
            OpenRouterFake::structured(['confidence' => 0.5], ['prompt_tokens' => 100, 'completion_tokens' => 50]),
            OpenRouterFake::structured(['sentiment' => 'positive', 'confidence' => 0.9], ['prompt_tokens' => 120, 'completion_tokens' => 60]),
        );

        $service = app(AiService::class);
        $prompt = $this->makePrompt(cacheTtl: 3600);

        $first = $service->sendStructuredData(collect([new UserMessage('Analyze')]), $prompt, SentimentData::class);
        $this->assertEquals(new Usage(220, 110), $first->usage);

        $second = $service->sendStructuredData(collect([new UserMessage('Analyze')]), $prompt, SentimentData::class);
        $this->assertInstanceOf(SentimentData::class, $second->data);
        $this->assertSame('positive', $second->data->sentiment);
        $this->assertEquals(new Usage, $second->usage);
    }

    public function test_send_structured_data_reports_a_cache_write_failure_and_returns_the_result(): void
    {
        $this->failCacheWrites();
        Exceptions::fake();

        OpenRouterFake::respondWith(OpenRouterFake::structured(['sentiment' => 'positive', 'confidence' => 0.9]));

        $result = app(AiService::class)->sendStructuredData(collect([new UserMessage('Analyze')]), $this->makePrompt(cacheTtl: 3600), SentimentData::class);

        $this->assertSame('positive', $result->data->sentiment);
        $this->assertCount(1, OpenRouterFake::sentBodies());
        Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'Cache store is down');
    }

    public function test_send_messages_reports_a_cache_write_failure_and_returns_the_response(): void
    {
        $this->failCacheWrites();
        Exceptions::fake();

        OpenRouterFake::respondWith(OpenRouterFake::completion('Fine'));

        $response = app(AiService::class)->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt(cacheTtl: 3600));

        $this->assertSame('Fine', $response->text);
        Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'Cache store is down');
    }

    public function test_cache_hits_when_middleware_rewrites_the_messages(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('Original response'));

        $service = app(AiService::class);
        $service->addMiddleware(new class implements AiWorkflowMiddleware
        {
            public function handle(AiWorkflowContext $context, Closure $next): AiWorkflowContext
            {
                $context->messages = [new UserMessage('[redacted]')];

                return $next($context);
            }
        });
        $prompt = $this->makePrompt(cacheTtl: 3600);

        $service->sendMessages(collect([new UserMessage('Secret')]), $prompt);
        $response = $service->sendMessages(collect([new UserMessage('Secret')]), $prompt);

        $this->assertSame('Original response', $response->text);
    }

    private function failCacheWrites(): void
    {
        $this->app->instance(AiWorkflowCache::class, new class extends AiWorkflowCache
        {
            #[\Override]
            public function put(string $key, array $responseData, int $ttlSeconds): void
            {
                throw new RuntimeException('Cache store is down');
            }
        });
    }
}

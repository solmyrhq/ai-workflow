<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

use AiWorkflow\Enums\FinishReason;
use AiWorkflow\Exceptions\InsufficientCreditsException;
use AiWorkflow\Exceptions\ProviderConnectionException;
use AiWorkflow\Exceptions\ProviderOverloadedException;
use AiWorkflow\Exceptions\ProviderRequestException;
use AiWorkflow\Exceptions\RateLimitedException;
use AiWorkflow\Exceptions\StructuredDecodingException;
use AiWorkflow\Exceptions\UnexpectedFinishReasonException;
use AiWorkflow\Exceptions\UpstreamErrorException;
use AiWorkflow\Integrations\OpenRouterCredentials;
use AiWorkflow\Integrations\OpenRouterProvider;
use Illuminate\Http\Client\ConnectionException;
use Integrations\Contracts\ClassifiesFailures;
use Integrations\Contracts\CustomizesRetry;
use Integrations\Contracts\DeclaresRateLimit;
use Integrations\Contracts\IntegrationProvider;
use Integrations\Enums\FailureClass;
use Integrations\Enums\RateLimitWindow;
use RuntimeException;

class OpenRouterProviderTest extends TestCase
{
    private function providerResponse(string $message, int $status): ProviderRequestException
    {
        return new ProviderRequestException($message, 'openrouter', $status);
    }

    public function test_implements_required_contracts(): void
    {
        $provider = new OpenRouterProvider;

        $this->assertInstanceOf(IntegrationProvider::class, $provider);
        $this->assertInstanceOf(ClassifiesFailures::class, $provider);
        $this->assertInstanceOf(CustomizesRetry::class, $provider);
        $this->assertInstanceOf(DeclaresRateLimit::class, $provider);
    }

    // --- Failure classification ---

    public function test_billing_and_auth_4xx_classify_as_client(): void
    {
        $provider = new OpenRouterProvider;

        // 402/403 are the storm trigger: Client means non-retryable and the
        // breaker never trips.
        $this->assertSame(FailureClass::Client, $provider->classifyFailure(new InsufficientCreditsException('payment required', 'openrouter', 402)));
        $this->assertSame(FailureClass::Client, $provider->classifyFailure($this->providerResponse('forbidden', 403)));
        $this->assertSame(FailureClass::Client, $provider->classifyFailure($this->providerResponse('bad request', 400)));
        $this->assertSame(FailureClass::Client, $provider->classifyFailure($this->providerResponse('too large', 413)));
    }

    public function test_429_classifies_as_throttle(): void
    {
        $provider = new OpenRouterProvider;

        $this->assertSame(FailureClass::Throttle, $provider->classifyFailure($this->providerResponse('slow down', 429)));
        $this->assertSame(FailureClass::Throttle, $provider->classifyFailure(new RateLimitedException('slow down', 'openrouter')));
    }

    public function test_5xx_classifies_as_upstream(): void
    {
        $provider = new OpenRouterProvider;

        $this->assertSame(FailureClass::Upstream, $provider->classifyFailure($this->providerResponse('server error', 500)));
        $this->assertSame(FailureClass::Upstream, $provider->classifyFailure(new ProviderOverloadedException('unavailable', 'openrouter', 503)));
    }

    public function test_connection_failures_and_unexpected_finish_reasons_classify_as_upstream(): void
    {
        $provider = new OpenRouterProvider;

        $this->assertSame(FailureClass::Upstream, $provider->classifyFailure(new ProviderConnectionException('network down', 'openrouter')));
        $this->assertSame(FailureClass::Upstream, $provider->classifyFailure(new ConnectionException('network down')));
        $this->assertSame(FailureClass::Upstream, $provider->classifyFailure(new UnexpectedFinishReasonException(FinishReason::Unknown, 'openrouter')));
    }

    public function test_an_error_envelope_classifies_by_its_error_code(): void
    {
        $provider = new OpenRouterProvider;

        $this->assertSame(FailureClass::Client, $provider->classifyFailure(new UpstreamErrorException('bad', 'openrouter', errorCode: 400)));
        $this->assertSame(FailureClass::Throttle, $provider->classifyFailure(new UpstreamErrorException('slow', 'openrouter', errorCode: 429)));
        $this->assertSame(FailureClass::Upstream, $provider->classifyFailure(new UpstreamErrorException('down', 'openrouter', errorCode: 502)));
        $this->assertSame(FailureClass::Upstream, $provider->classifyFailure(new UpstreamErrorException('unknown', 'openrouter')));
        $this->assertSame(FailureClass::Upstream, $provider->classifyFailure(new UpstreamErrorException('odd', 'openrouter', errorCode: 200)));
    }

    public function test_status_is_read_through_a_wrapper_without_one(): void
    {
        $wrapped = new RuntimeException('OpenRouter request failed', previous: $this->providerResponse('payment required', 402));

        $this->assertSame(FailureClass::Client, (new OpenRouterProvider)->classifyFailure($wrapped));
    }

    public function test_structured_decoding_defers_so_fallback_handles_it(): void
    {
        $provider = new OpenRouterProvider;

        $this->assertNull($provider->classifyFailure(new StructuredDecodingException('invalid json', 'openrouter', 'not json')));
    }

    public function test_unrelated_exception_defers_to_core_classifier(): void
    {
        $provider = new OpenRouterProvider;

        $this->assertNull($provider->classifyFailure(new RuntimeException('mystery')));
    }

    // --- Retry decision + backoff ---

    public function test_is_retryable_defers_to_classification(): void
    {
        $this->assertNull((new OpenRouterProvider)->isRetryable(new RuntimeException('x')));
    }

    public function test_retry_delay_uses_rate_limit_delay_for_429(): void
    {
        config()->set('ai-workflow.retry.jitter', false);
        config()->set('ai-workflow.retry.rate_limit_delay_ms', 30_000);

        $provider = new OpenRouterProvider;

        $this->assertSame(30_000, $provider->retryDelayMs($this->providerResponse('slow', 429), 1, null));
        $this->assertSame(30_000, $provider->retryDelayMs(new RateLimitedException('slow', 'openrouter', 429), 1, null));
        $this->assertSame(30_000, $provider->retryDelayMs(new UpstreamErrorException('slow', 'openrouter', errorCode: 429), 1, null));
    }

    public function test_retry_delay_honors_retry_after(): void
    {
        config()->set('ai-workflow.retry.jitter', false);

        $provider = new OpenRouterProvider;

        $this->assertSame(10_000, $provider->retryDelayMs(new RateLimitedException('slow', 'openrouter', 429, retryAfter: 10), 1, null));
    }

    public function test_retry_delay_grows_linearly_for_upstream_faults(): void
    {
        config()->set('ai-workflow.retry.jitter', false);
        config()->set('ai-workflow.retry.server_error_multiplier_ms', 2_000);

        $provider = new OpenRouterProvider;

        $this->assertSame(4_000, $provider->retryDelayMs($this->providerResponse('boom', 500), 2, null));
        $this->assertSame(6_000, $provider->retryDelayMs(new ProviderOverloadedException('overloaded', 'openrouter', 503), 3, null));
        $this->assertSame(2_000, $provider->retryDelayMs(new UnexpectedFinishReasonException(FinishReason::Error, 'openrouter'), 1, null));
        $this->assertSame(4_000, $provider->retryDelayMs(new UpstreamErrorException('down', 'openrouter', errorCode: 502), 2, null));
    }

    public function test_retry_delay_defers_for_non_retryable_status(): void
    {
        config()->set('ai-workflow.retry.jitter', false);

        $provider = new OpenRouterProvider;

        $this->assertNull($provider->retryDelayMs($this->providerResponse('bad', 400), 1, null));
    }

    public function test_negative_retry_delays_fall_back_to_defaults(): void
    {
        config()->set('ai-workflow.retry.jitter', false);
        config()->set('ai-workflow.retry.rate_limit_delay_ms', -5_000);
        config()->set('ai-workflow.retry.server_error_multiplier_ms', -1_000);

        $provider = new OpenRouterProvider;

        $this->assertSame(30_000, $provider->retryDelayMs($this->providerResponse('slow', 429), 1, null));
        $this->assertSame(2_000, $provider->retryDelayMs($this->providerResponse('boom', 500), 1, null));
    }

    public function test_retry_delay_applies_jitter(): void
    {
        config()->set('ai-workflow.retry.jitter', true);
        config()->set('ai-workflow.retry.server_error_multiplier_ms', 2_000);

        $provider = new OpenRouterProvider;
        $exception = $this->providerResponse('boom', 500);

        $delays = [];
        for ($i = 0; $i < 20; $i++) {
            $delay = $provider->retryDelayMs($exception, 2, null);
            $this->assertNotNull($delay);
            $delays[] = $delay;
        }

        // Base 4000 ± 25% = 3000..5000.
        foreach ($delays as $delay) {
            $this->assertGreaterThanOrEqual(3_000, $delay);
            $this->assertLessThanOrEqual(5_000, $delay);
        }

        $this->assertGreaterThan(1, count(array_unique($delays)));
    }

    // --- Rate limit + metadata ---

    public function test_default_rate_limit_is_null_unless_configured(): void
    {
        $this->assertNull((new OpenRouterProvider)->defaultRateLimit());

        config()->set('ai-workflow.openrouter.rate_limit_per_minute', 120);

        $limit = (new OpenRouterProvider)->defaultRateLimit();
        $this->assertNotNull($limit);
        $this->assertSame(120, $limit->limit);
        $this->assertSame(60, $limit->windowSeconds);
        $this->assertSame(RateLimitWindow::Fixed, $limit->window);
    }

    public function test_metadata(): void
    {
        $provider = new OpenRouterProvider;

        $this->assertSame('OpenRouter', $provider->name());
        $this->assertArrayHasKey('api_key', $provider->credentialRules());
        $this->assertSame([], $provider->metadataRules());
        $this->assertSame(OpenRouterCredentials::class, $provider->credentialDataClass());
        $this->assertNull($provider->metadataDataClass());
    }
}

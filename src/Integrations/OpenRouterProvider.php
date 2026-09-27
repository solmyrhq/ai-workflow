<?php

declare(strict_types=1);

namespace AiWorkflow\Integrations;

use AiWorkflow\Exceptions\HttpErrorDetails;
use AiWorkflow\Exceptions\ProviderConnectionException;
use AiWorkflow\Exceptions\ProviderOverloadedException;
use AiWorkflow\Exceptions\RateLimitedException;
use AiWorkflow\Exceptions\StructuredDecodingException;
use AiWorkflow\Exceptions\UnexpectedFinishReasonException;
use AiWorkflow\Exceptions\UpstreamErrorException;
use Illuminate\Http\Client\ConnectionException;
use Integrations\Contracts\ClassifiesFailures;
use Integrations\Contracts\CustomizesRetry;
use Integrations\Contracts\DeclaresRateLimit;
use Integrations\Contracts\IntegrationProvider;
use Integrations\Enums\FailureClass;
use Integrations\RateLimit;
use Throwable;

/**
 * Routes ai-workflow's OpenRouter calls through laravel-integrations so the
 * framework owns the circuit breaker, retries, rate limiting, and transport
 * audit. The classifier is the load-bearing part: mapping 402/403 to Client
 * (non-retryable, doesn't trip the breaker) is what stops a billing/access
 * outage from turning into a retry storm.
 */
class OpenRouterProvider implements ClassifiesFailures, CustomizesRetry, DeclaresRateLimit, IntegrationProvider
{
    #[\Override]
    public function classifyFailure(Throwable $e): ?FailureClass
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof RateLimitedException) {
                return FailureClass::Throttle;
            }

            if ($current instanceof ProviderOverloadedException
                || $current instanceof ProviderConnectionException
                || $current instanceof UnexpectedFinishReasonException) {
                return FailureClass::Upstream;
            }

            // A malformed structured response isn't a transport fault; AiService
            // recovers via its own fallback-model path. Defer so the breaker
            // neither trips nor retries it.
            if ($current instanceof StructuredDecodingException) {
                return null;
            }

            // For an error envelope on an HTTP 200, or a stream error,
            // OpenRouter puts the real HTTP status in the error code.
            if ($current instanceof UpstreamErrorException) {
                $class = $current->errorCode !== null ? FailureClass::fromStatus($current->errorCode) : FailureClass::Upstream;

                return $class === FailureClass::Unknown ? FailureClass::Upstream : $class;
            }

            if ($current instanceof ConnectionException) {
                return FailureClass::Upstream;
            }
        }

        $status = HttpErrorDetails::status($e);
        if ($status !== null) {
            return FailureClass::fromStatus($status);
        }

        return null;
    }

    #[\Override]
    public function isRetryable(Throwable $e): ?bool
    {
        // Retryability follows classification: the core falls back to
        // FailureClass::isRetryable() (Upstream/Throttle) when this returns
        // null. We only implement CustomizesRetry for retryDelayMs() below.
        return null;
    }

    #[\Override]
    public function retryDelayMs(Throwable $e, int $attempt, ?int $statusCode): ?int
    {
        $config = $this->retryConfig();
        $status = $statusCode ?? HttpErrorDetails::status($e);

        $delay = $this->baseDelayMs($e, $attempt, $status, $config);
        if ($delay === null) {
            // Defer connection/unknown errors to the framework's default backoff.
            return null;
        }

        return $config['jitter'] ? self::applyJitter($delay) : $delay;
    }

    #[\Override]
    public function defaultRateLimit(): ?RateLimit
    {
        $perMinute = config('ai-workflow.openrouter.rate_limit_per_minute');

        return is_int($perMinute) && $perMinute > 0 ? RateLimit::perMinute($perMinute) : null;
    }

    #[\Override]
    public function name(): string
    {
        return 'OpenRouter';
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function credentialRules(): array
    {
        return [
            'api_key' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function metadataRules(): array
    {
        return [];
    }

    /**
     * @return class-string<OpenRouterCredentials>
     */
    #[\Override]
    public function credentialDataClass(): string
    {
        return OpenRouterCredentials::class;
    }

    #[\Override]
    public function metadataDataClass(): ?string
    {
        return null;
    }

    /**
     * The base retry delay (pre-jitter) for an exception: a fixed pause on rate
     * limits, linear growth on upstream faults, and deferral (null) for
     * everything else.
     *
     * @param  array{rate_limit_delay_ms: int, server_error_multiplier_ms: int, jitter: bool}  $config
     */
    private function baseDelayMs(Throwable $e, int $attempt, ?int $status, array $config): ?int
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof RateLimitedException) {
                return $current->retryAfter !== null
                    ? $current->retryAfter * 1000
                    : $config['rate_limit_delay_ms'];
            }

            if ($current instanceof UpstreamErrorException && $current->errorCode === 429) {
                return $config['rate_limit_delay_ms'];
            }

            if ($current instanceof ProviderOverloadedException
                || $current instanceof UnexpectedFinishReasonException
                || $current instanceof UpstreamErrorException) {
                return $attempt * $config['server_error_multiplier_ms'];
            }
        }

        if ($status === 429) {
            return $config['rate_limit_delay_ms'];
        }

        if ($status !== null && $status >= 500) {
            return $attempt * $config['server_error_multiplier_ms'];
        }

        return null;
    }

    /**
     * @return array{rate_limit_delay_ms: int, server_error_multiplier_ms: int, jitter: bool}
     */
    private function retryConfig(): array
    {
        $config = config('ai-workflow.retry');
        if (! is_array($config)) {
            return ['rate_limit_delay_ms' => 30_000, 'server_error_multiplier_ms' => 2_000, 'jitter' => true];
        }

        // Delays must be non-negative: a negative value would reach the
        // framework's usleep() and crash the retry loop it was meant to pace.
        return [
            'rate_limit_delay_ms' => is_int($config['rate_limit_delay_ms'] ?? null) && $config['rate_limit_delay_ms'] >= 0 ? $config['rate_limit_delay_ms'] : 30_000,
            'server_error_multiplier_ms' => is_int($config['server_error_multiplier_ms'] ?? null) && $config['server_error_multiplier_ms'] >= 0 ? $config['server_error_multiplier_ms'] : 2_000,
            'jitter' => is_bool($config['jitter'] ?? null) ? $config['jitter'] : true,
        ];
    }

    /**
     * Apply ±25% random jitter to a delay value.
     */
    private static function applyJitter(int $delay): int
    {
        if ($delay <= 0) {
            return 0;
        }

        $jitter = (int) ($delay * 0.25);

        return max(0, $delay + random_int(-$jitter, $jitter));
    }
}

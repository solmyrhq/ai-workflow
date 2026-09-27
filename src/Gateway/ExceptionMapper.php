<?php

declare(strict_types=1);

namespace AiWorkflow\Gateway;

use AiWorkflow\Exceptions\InsufficientCreditsException;
use AiWorkflow\Exceptions\ProviderConnectionException;
use AiWorkflow\Exceptions\ProviderException;
use AiWorkflow\Exceptions\ProviderOverloadedException;
use AiWorkflow\Exceptions\ProviderRequestException;
use AiWorkflow\Exceptions\RateLimitedException;
use AiWorkflow\Exceptions\UpstreamErrorException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\InsufficientCreditsException as LaravelInsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderConnectionException as LaravelProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException as LaravelProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException as LaravelRateLimitedException;
use Throwable;

/**
 * Turns laravel/ai and HTTP client failures into package exceptions. Other
 * exceptions are returned unchanged.
 *
 * @internal
 */
final class ExceptionMapper
{
    public static function map(Throwable $e, string $provider): Throwable
    {
        if ($e instanceof ProviderException) {
            return $e;
        }

        $response = self::response($e);
        $status = $response?->status();
        $body = $response?->body();

        return match (true) {
            $e instanceof LaravelRateLimitedException => new RateLimitedException(self::message($provider, $response), $provider, $status, $body, self::retryAfter($response), $e),
            $e instanceof LaravelInsufficientCreditsException => new InsufficientCreditsException(self::message($provider, $response), $provider, $status, $body, $e),
            $e instanceof LaravelProviderOverloadedException => new ProviderOverloadedException(self::message($provider, $response), $provider, $status, $body, $e),
            $e instanceof LaravelProviderConnectionException, $e instanceof ConnectionException => new ProviderConnectionException(self::connectionMessage($provider, $e), $provider, previous: $e),
            $e instanceof RequestException => new ProviderRequestException(self::message($provider, $response), $provider, $status, $body, $e),
            $e instanceof AiException => new UpstreamErrorException($e->getMessage(), $provider, previous: $e),
            default => $e,
        };
    }

    private static function response(Throwable $e): ?Response
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof RequestException) {
                return $current->response;
            }
        }

        return null;
    }

    private static function message(string $provider, ?Response $response): string
    {
        if ($response === null) {
            return "The {$provider} request failed.";
        }

        $detail = $response->json('error.message');

        return is_string($detail) && $detail !== ''
            ? "The {$provider} request failed with HTTP {$response->status()}: {$detail}"
            : "The {$provider} request failed with HTTP {$response->status()}.";
    }

    private static function connectionMessage(string $provider, Throwable $e): string
    {
        $cause = $e instanceof ConnectionException ? $e : $e->getPrevious();

        return $cause !== null
            ? "Could not connect to {$provider}: {$cause->getMessage()}"
            : "Could not connect to {$provider}.";
    }

    /**
     * The Retry-After delay in seconds, or null when the header is missing or
     * cannot be parsed.
     */
    private static function retryAfter(?Response $response): ?int
    {
        $header = $response?->header('Retry-After') ?? '';

        if ($header === '') {
            return null;
        }

        if (ctype_digit($header)) {
            return (int) $header;
        }

        $timestamp = strtotime($header);

        return $timestamp === false ? null : max(0, $timestamp - time());
    }
}

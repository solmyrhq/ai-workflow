<?php

declare(strict_types=1);

namespace AiWorkflow\Exceptions;

use Throwable;

/**
 * The provider returned an error, or no usable response, without an HTTP
 * error status: an error envelope or empty body on an HTTP 200, an error
 * chunk in a stream, a stream that ended without a response, or another
 * laravel/ai failure.
 */
class UpstreamErrorException extends ProviderException
{
    public function __construct(
        string $message,
        string $provider,
        ?string $responseBody = null,
        public readonly ?int $errorCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $provider, null, $responseBody, $previous);
    }
}

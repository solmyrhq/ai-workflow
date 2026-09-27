<?php

declare(strict_types=1);

namespace AiWorkflow\Exceptions;

use Throwable;

class RateLimitedException extends ProviderException
{
    public function __construct(
        string $message,
        string $provider,
        ?int $status = null,
        ?string $responseBody = null,
        public readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $provider, $status, $responseBody, $previous);
    }
}

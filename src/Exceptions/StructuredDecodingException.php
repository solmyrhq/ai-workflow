<?php

declare(strict_types=1);

namespace AiWorkflow\Exceptions;

use Throwable;

class StructuredDecodingException extends ProviderException
{
    public function __construct(
        string $message,
        string $provider,
        public readonly string $text,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $provider, previous: $previous);
    }
}

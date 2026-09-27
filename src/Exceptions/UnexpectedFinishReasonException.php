<?php

declare(strict_types=1);

namespace AiWorkflow\Exceptions;

use AiWorkflow\Enums\FinishReason;
use AiWorkflow\Responses\Usage;

class UnexpectedFinishReasonException extends ProviderException
{
    /**
     * @param  Usage  $usage  The tokens of every response rejected for its finish reason, retries included.
     */
    public function __construct(
        public readonly FinishReason $finishReason,
        string $provider,
        ?string $responseBody = null,
        public readonly Usage $usage = new Usage,
    ) {
        parent::__construct("Unexpected AI finish reason: {$finishReason->value}", $provider, responseBody: $responseBody);
    }
}

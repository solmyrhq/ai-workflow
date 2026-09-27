<?php

declare(strict_types=1);

namespace AiWorkflow\Exceptions;

use AiWorkflow\Responses\Usage;
use Throwable;

class StructuredValidationException extends AiWorkflowException
{
    public function __construct(
        string $message,
        public readonly int $attempts,
        ?Throwable $previous = null,
        ?Usage $usage = null,
    ) {
        parent::__construct($message, 0, $previous);

        if ($usage !== null) {
            $this->recordUsage($usage);
        }
    }
}

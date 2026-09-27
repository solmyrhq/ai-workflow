<?php

declare(strict_types=1);

namespace AiWorkflow\Exceptions;

use AiWorkflow\Responses\Usage;
use Throwable;

class StructuredDataRequestException extends AiWorkflowException
{
    public function __construct(
        string $message,
        public readonly int $attempts,
        Usage $usage,
        Throwable $previous,
    ) {
        $code = $previous->getCode();

        parent::__construct($message, is_int($code) ? $code : 0, $previous);

        $this->recordUsage($usage);
    }
}

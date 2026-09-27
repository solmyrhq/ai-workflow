<?php

declare(strict_types=1);

namespace AiWorkflow\Exceptions;

class UnknownToolException extends AiWorkflowException
{
    public function __construct(
        public readonly string $toolName,
    ) {
        parent::__construct("The model called a tool that was not provided: [{$toolName}].");
    }
}

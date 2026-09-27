<?php

declare(strict_types=1);

namespace AiWorkflow\Exceptions;

use AiWorkflow\Responses\Usage;
use RuntimeException;

class AiWorkflowException extends RuntimeException
{
    private ?Usage $usage = null;

    /**
     * Token usage of the responses received before this exception was thrown, or null if none was recorded.
     */
    public function usage(): ?Usage
    {
        return $this->usage;
    }

    /**
     * @internal
     */
    public function recordUsage(Usage $usage): void
    {
        $this->usage = $usage;
    }
}

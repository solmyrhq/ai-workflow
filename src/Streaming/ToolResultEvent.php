<?php

declare(strict_types=1);

namespace AiWorkflow\Streaming;

use AiWorkflow\Messages\ToolResult;

class ToolResultEvent implements StreamEvent
{
    public function __construct(
        public readonly ToolResult $toolResult,
    ) {}
}

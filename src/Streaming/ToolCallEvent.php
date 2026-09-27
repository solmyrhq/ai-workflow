<?php

declare(strict_types=1);

namespace AiWorkflow\Streaming;

use AiWorkflow\Messages\ToolCall;

class ToolCallEvent implements StreamEvent
{
    public function __construct(
        public readonly ToolCall $toolCall,
    ) {}
}

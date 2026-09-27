<?php

declare(strict_types=1);

namespace AiWorkflow\Messages;

class ToolResultMessage implements Message
{
    /**
     * @param  list<ToolResult>  $toolResults
     */
    public function __construct(
        public readonly array $toolResults,
    ) {}
}

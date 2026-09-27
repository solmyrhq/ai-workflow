<?php

declare(strict_types=1);

namespace AiWorkflow\Messages;

class ToolResult
{
    /**
     * @param  array<string, mixed>  $args
     */
    public function __construct(
        public readonly string $toolCallId,
        public readonly string $toolName,
        public readonly array $args,
        public readonly mixed $result,
    ) {}
}

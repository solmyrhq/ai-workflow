<?php

declare(strict_types=1);

namespace AiWorkflow\Messages;

class ToolCall
{
    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $providerState
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $arguments = [],
        public readonly array $providerState = [],
    ) {}
}

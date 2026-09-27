<?php

declare(strict_types=1);

namespace AiWorkflow\Messages;

class AssistantMessage implements Message
{
    /**
     * @param  list<ToolCall>  $toolCalls
     * @param  array<string, mixed>  $providerState
     */
    public function __construct(
        public readonly string $content = '',
        public readonly array $toolCalls = [],
        public readonly array $providerState = [],
    ) {}
}

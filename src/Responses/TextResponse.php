<?php

declare(strict_types=1);

namespace AiWorkflow\Responses;

use AiWorkflow\Enums\FinishReason;
use AiWorkflow\Messages\Message;
use AiWorkflow\Messages\ToolCall;
use AiWorkflow\Messages\ToolResult;

class TextResponse
{
    /**
     * @param  list<Message>  $messages  The assistant and tool-result messages from this call, to append to the conversation.
     * @param  list<ToolCall>  $toolCalls
     * @param  list<ToolResult>  $toolResults
     */
    public function __construct(
        public readonly string $text,
        public readonly FinishReason $finishReason,
        public readonly Usage $usage,
        public readonly ResponseMeta $meta,
        public readonly array $messages = [],
        public readonly array $toolCalls = [],
        public readonly array $toolResults = [],
        public readonly string $reasoning = '',
    ) {}
}

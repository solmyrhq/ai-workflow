<?php

declare(strict_types=1);

namespace AiWorkflow\Middleware;

use AiWorkflow\Messages\Message;
use AiWorkflow\PromptData;
use AiWorkflow\Responses\StructuredResponse;
use AiWorkflow\Responses\TextResponse;
use AiWorkflow\Schema\ResponseSchema;

class AiWorkflowContext
{
    /**
     * @param  list<Message>  $messages
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public array $messages,
        public PromptData $prompt,
        public string $systemPrompt,
        public readonly string $method,
        public ?ResponseSchema $schema = null,
        public TextResponse|StructuredResponse|null $response = null,
        public array $metadata = [],
    ) {}
}

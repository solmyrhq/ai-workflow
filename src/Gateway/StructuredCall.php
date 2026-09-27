<?php

declare(strict_types=1);

namespace AiWorkflow\Gateway;

use AiWorkflow\Messages\Message;
use AiWorkflow\Schema\ResponseSchema;

/**
 * @internal
 */
final class StructuredCall
{
    /**
     * @param  string  $systemPrompt  Empty for none.
     * @param  list<Message>  $messages
     * @param  array<string, mixed>  $providerOptions  Merged into the request body last.
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly string $systemPrompt,
        public readonly array $messages,
        public readonly ResponseSchema $schema,
        public readonly ?int $maxTokens = null,
        public readonly array $providerOptions = [],
    ) {}
}

<?php

declare(strict_types=1);

namespace AiWorkflow\Gateway;

use AiWorkflow\Messages\Message;
use AiWorkflow\Tools\Tool;

/**
 * @internal
 */
final class TextCall
{
    /**
     * @param  string  $systemPrompt  Empty for none.
     * @param  list<Message>  $messages
     * @param  list<Tool>  $tools
     * @param  int  $maxSteps  The maximum number of HTTP requests in the tool loop.
     * @param  array<string, mixed>  $providerOptions  Merged into the request body last.
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly string $systemPrompt,
        public readonly array $messages,
        public readonly array $tools = [],
        public readonly int $maxSteps = 1,
        public readonly ?int $maxTokens = null,
        public readonly array $providerOptions = [],
    ) {}
}

<?php

declare(strict_types=1);

namespace AiWorkflow\Messages;

class UserMessage implements Message
{
    /**
     * @param  list<Attachment>  $attachments
     */
    public function __construct(
        public readonly string $content,
        public readonly array $attachments = [],
    ) {}
}

<?php

declare(strict_types=1);

namespace AiWorkflow\Messages;

class SystemMessage implements Message
{
    public function __construct(
        public readonly string $content,
    ) {}
}

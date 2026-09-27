<?php

declare(strict_types=1);

namespace AiWorkflow\Streaming;

class StreamStart implements StreamEvent
{
    public function __construct(
        public readonly string $model,
        public readonly ?string $provider = null,
    ) {}
}

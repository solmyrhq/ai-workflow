<?php

declare(strict_types=1);

namespace AiWorkflow\Streaming;

class ReasoningDelta implements StreamEvent
{
    public function __construct(
        public readonly string $delta,
    ) {}
}

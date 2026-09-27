<?php

declare(strict_types=1);

namespace AiWorkflow\Streaming;

use AiWorkflow\Enums\FinishReason;
use AiWorkflow\Responses\ResponseMeta;
use AiWorkflow\Responses\Usage;

class StreamEnd implements StreamEvent
{
    public function __construct(
        public readonly FinishReason $finishReason,
        public readonly Usage $usage,
        public readonly ResponseMeta $meta,
    ) {}
}

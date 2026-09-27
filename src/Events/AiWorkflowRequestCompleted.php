<?php

declare(strict_types=1);

namespace AiWorkflow\Events;

use AiWorkflow\Enums\FinishReason;
use AiWorkflow\PromptData;
use AiWorkflow\Responses\Usage;
use Illuminate\Foundation\Events\Dispatchable;

class AiWorkflowRequestCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly PromptData $prompt,
        public readonly string $method,
        public readonly string $model,
        public readonly FinishReason $finishReason,
        public readonly Usage $usage,
        public readonly float $durationMs,
        public readonly ?string $executionId,
    ) {}
}

<?php

declare(strict_types=1);

namespace AiWorkflow\Listeners;

use AiWorkflow\Events\AiWorkflowRequestCompleted;
use AiWorkflow\Events\AiWorkflowRequestFailed;

class SentryBreadcrumbListener
{
    public function handleCompleted(AiWorkflowRequestCompleted $event): void
    {
        if (! function_exists('\Sentry\addBreadcrumb')) {
            return;
        }

        \Sentry\addBreadcrumb(
            category: 'ai_workflow',
            message: 'AI request completed',
            metadata: [
                'prompt_id' => $event->prompt->id,
                'method' => $event->method,
                'model' => $event->model,
                'finish_reason' => $event->finishReason->value,
                'input_tokens' => $event->usage->inputTokens,
                'output_tokens' => $event->usage->outputTokens,
                'thought_tokens' => $event->usage->thoughtTokens,
                'duration_ms' => round($event->durationMs, 2),
                'execution_id' => $event->executionId,
            ],
            level: 'info',
        );
    }

    public function handleFailed(AiWorkflowRequestFailed $event): void
    {
        if (! function_exists('\Sentry\addBreadcrumb')) {
            return;
        }

        \Sentry\addBreadcrumb(
            category: 'ai_workflow',
            message: 'AI request failed',
            metadata: [
                'prompt_id' => $event->prompt->id,
                'method' => $event->method,
                'model' => $event->model,
                'error' => $event->exception->getMessage(),
                'duration_ms' => round($event->durationMs, 2),
                'execution_id' => $event->executionId,
            ],
            level: 'error',
        );
    }
}

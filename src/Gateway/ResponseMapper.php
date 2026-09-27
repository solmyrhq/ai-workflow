<?php

declare(strict_types=1);

namespace AiWorkflow\Gateway;

use AiWorkflow\Enums\FinishReason;
use AiWorkflow\Responses\ResponseMeta;
use AiWorkflow\Responses\Usage;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Responses\Data\FinishReason as LaravelFinishReason;
use Laravel\Ai\Responses\Data\TextUsage;

/**
 * Reads package values out of a laravel/ai step response.
 *
 * @internal
 */
final class ResponseMapper
{
    public static function finishReason(LaravelFinishReason $finishReason): FinishReason
    {
        return match ($finishReason) {
            LaravelFinishReason::Stop => FinishReason::Stop,
            LaravelFinishReason::ToolCalls => FinishReason::ToolCalls,
            LaravelFinishReason::Length => FinishReason::Length,
            LaravelFinishReason::ContentFilter => FinishReason::ContentFilter,
            LaravelFinishReason::Error => FinishReason::Error,
            LaravelFinishReason::Unknown => FinishReason::Unknown,
            LaravelFinishReason::Continue => FinishReason::Other,
        };
    }

    public static function usage(TextUsage $usage): Usage
    {
        return new Usage(
            inputTokens: $usage->inputTokens,
            outputTokens: $usage->outputTokens,
            cacheReadTokens: $usage->cacheReadInputTokens,
            cacheWriteTokens: $usage->cacheWriteInputTokens,
            thoughtTokens: $usage->reasoningTokens,
        );
    }

    /**
     * The response id and model, plus the upstream provider and cost for
     * OpenRouter.
     */
    public static function meta(StepResponse $step, StepTextGateway $gateway): ResponseMeta
    {
        if ($gateway instanceof OpenRouterGateway) {
            $envelope = $gateway->envelope();

            return new ResponseMeta($envelope['id'] ?? '', $step->meta->model ?? '', $envelope['provider'], $envelope['cost']);
        }

        $id = $step->raw?->json('id');

        return new ResponseMeta(is_string($id) ? $id : '', $step->meta->model ?? '');
    }
}

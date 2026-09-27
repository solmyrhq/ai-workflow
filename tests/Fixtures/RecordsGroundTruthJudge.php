<?php

declare(strict_types=1);

namespace AiWorkflow\Tests\Fixtures;

use AiWorkflow\Eval\AiWorkflowEvalJudge;
use AiWorkflow\Eval\AiWorkflowEvalResult;
use AiWorkflow\Models\AiWorkflowRequest;
use AiWorkflow\Responses\StructuredResponse;
use AiWorkflow\Responses\TextResponse;

/**
 * Reports whichever key the replayed response returned, and scores it against
 * the injected ground truth — a miniature stand-in for a real domain judge.
 */
class RecordsGroundTruthJudge implements AiWorkflowEvalJudge
{
    public function judge(AiWorkflowRequest $originalRequest, TextResponse|StructuredResponse $response): AiWorkflowEvalResult
    {
        $structured = $response instanceof StructuredResponse ? $response->structured : [];
        $predicted = array_key_first($structured);
        $groundTruth = $originalRequest->getAttribute(AiWorkflowRequest::GROUND_TRUTH_ATTRIBUTE);

        return new AiWorkflowEvalResult(
            score: is_string($predicted) && $predicted === $groundTruth ? 1.0 : 0.0,
            details: ['ground_truth' => $groundTruth],
            predicted: is_string($predicted) ? $predicted : null,
        );
    }
}

<?php

declare(strict_types=1);

namespace AiWorkflow\Eval;

use AiWorkflow\Models\AiWorkflowRequest;
use AiWorkflow\Responses\StructuredResponse;
use AiWorkflow\Responses\TextResponse;

interface AiWorkflowEvalJudge
{
    /**
     * Judge an AI response against the original recorded request.
     */
    public function judge(AiWorkflowRequest $originalRequest, TextResponse|StructuredResponse $response): AiWorkflowEvalResult;
}

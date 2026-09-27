<?php

declare(strict_types=1);

namespace AiWorkflow\Eval;

use AiWorkflow\AiService;
use AiWorkflow\Messages\Message;
use AiWorkflow\Messages\UserMessage;
use AiWorkflow\Models\AiWorkflowRequest;
use AiWorkflow\PromptData;
use AiWorkflow\Responses\StructuredResponse;
use AiWorkflow\Responses\TextResponse;
use AiWorkflow\Schema\ResponseSchema;
use Illuminate\Support\Collection;
use Override;

/**
 * Example AI-powered judge that compares an original response with a new one.
 *
 * Uses a configurable AI model to semantically compare both responses,
 * scoring equivalence from 0.0 (completely different) to 1.0 (semantically identical).
 * Minor differences like capitalization or formatting score high.
 *
 * Extend this class or implement AiWorkflowEvalJudge directly for domain-specific judges.
 */
class AiJudge implements AiWorkflowEvalJudge
{
    private const string DEFAULT_JUDGE_PROMPT = <<<'PROMPT'
You are an AI evaluation judge. You will be given:
1. The original system prompt (context for what was requested)
2. The original response (the baseline/expected output)
3. A new response (what we're evaluating)

Compare the new response against the original. Score their semantic equivalence from 0.0 to 1.0:
- 1.0: Semantically identical — same meaning, same key information
- 0.8-0.9: Minor cosmetic differences (capitalization, punctuation, formatting, rewording)
- 0.5-0.7: Partially equivalent — some information matches but notable differences
- 0.1-0.4: Mostly different — different conclusions, missing key information
- 0.0: Completely different or contradictory

For structured/JSON responses, compare field values semantically rather than as exact strings.
For example, "John Smith" vs "john smith" should score very high.

Provide a brief reasoning explaining the score.
PROMPT;

    public function __construct(
        private readonly string $model,
        private readonly ?string $judgePrompt = null,
    ) {}

    #[Override]
    public function judge(AiWorkflowRequest $originalRequest, TextResponse|StructuredResponse $response): AiWorkflowEvalResult
    {
        $originalResponseText = $this->formatOriginalResponse($originalRequest);
        $newResponseText = $this->formatNewResponse($response);

        $userContent = "## Original System Prompt\n{$originalRequest->system_prompt}\n\n"
            ."## Original Response\n{$originalResponseText}\n\n"
            ."## New Response\n{$newResponseText}";

        $prompt = new PromptData(
            id: 'ai-workflow:eval-judge',
            model: $this->model,
            prompt: $this->judgePrompt ?? self::DEFAULT_JUDGE_PROMPT,
        );

        $schema = new ResponseSchema('JudgeResult', [
            'description' => 'AI judge evaluation result',
            'type' => 'object',
            'properties' => [
                'score' => ['description' => 'Semantic equivalence score from 0.0 to 1.0', 'type' => 'number'],
                'reasoning' => ['description' => 'Brief explanation of the score', 'type' => 'string'],
            ],
            'required' => ['score', 'reasoning'],
            'additionalProperties' => false,
        ]);

        $aiService = app(AiService::class);

        /** @var Collection<int, Message> $messages */
        $messages = new Collection([new UserMessage($userContent)]);

        $judgeResponse = $aiService->sendStructuredMessages(
            $messages,
            $prompt,
            $schema,
        );

        $structured = $judgeResponse->structured;

        $score = is_numeric($structured['score'] ?? null) ? (float) $structured['score'] : 0.0;
        $score = max(0.0, min(1.0, $score));
        $reasoning = is_string($structured['reasoning'] ?? null) ? $structured['reasoning'] : '';

        return new AiWorkflowEvalResult(
            score: $score,
            details: [
                'reasoning' => $reasoning,
                'judge_model' => $this->model,
                'judge_finish_reason' => $judgeResponse->finishReason->value,
            ],
        );
    }

    private function formatOriginalResponse(AiWorkflowRequest $request): string
    {
        if ($request->structured_response !== null) {
            return json_encode($request->structured_response, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        }

        return $request->response_text ?? '(no response recorded)';
    }

    private function formatNewResponse(TextResponse|StructuredResponse $response): string
    {
        if ($response instanceof StructuredResponse) {
            return json_encode($response->structured, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        }

        return $response->text;
    }
}

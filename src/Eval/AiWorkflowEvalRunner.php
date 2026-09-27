<?php

declare(strict_types=1);

namespace AiWorkflow\Eval;

use AiWorkflow\AiWorkflowReplayer;
use AiWorkflow\Exceptions\HttpErrorDetails;
use AiWorkflow\Models\AiWorkflowEvalRun;
use AiWorkflow\Models\AiWorkflowEvalScore;
use AiWorkflow\Models\AiWorkflowRequest;
use AiWorkflow\Responses\StructuredResponse;
use AiWorkflow\Responses\TextResponse;
use Illuminate\Support\Facades\Log;
use Integrations\Contracts\CustomizesRetry;
use Integrations\Contracts\IntegrationProvider;
use Integrations\Enums\FailureClass;
use Integrations\IntegrationManager;
use Integrations\RetryHandler;
use Integrations\Support\FailureClassifier;
use Integrations\Support\ResponseHelper;
use Throwable;

class AiWorkflowEvalRunner
{
    /** Kept low: every attempt is a paid call, across every item in a run. */
    private const int DEFAULT_REPLAY_TRIES = 2;

    public function __construct(
        private readonly AiWorkflowReplayer $replayer,
    ) {}

    /**
     * Run an evaluation across one or more models using a judge.
     *
     * @param  list<AiWorkflowRequest>  $requests
     * @param  list<string>  $models  Each in provider:model format.
     * @param  array<string, mixed>  $config  Optional config metadata to store on the run.
     */
    public function run(
        string $name,
        array $requests,
        array $models,
        AiWorkflowEvalJudge $judge,
        array $config = [],
    ): AiWorkflowEvalRun {
        // Deliberately not wrapped in a transaction. A run is a long sequence of
        // paid network calls — hours, for a real golden set — and each score is
        // a result someone has already been billed for. Holding one transaction
        // across all of it means a timeout, a deploy or a crash discards work
        // that cannot be recovered without paying for it again, and keeps a
        // write transaction open on the database for the duration. Committing
        // as we go costs nothing and means an interrupted run keeps whatever it
        // finished.
        $evalRun = AiWorkflowEvalRun::create([
            'name' => $name,
            'models' => $models,
            'config' => $config !== [] ? $config : null,
        ]);

        foreach ($requests as $request) {
            foreach ($models as $model) {
                $response = null;
                $durationMs = null;

                try {
                    $startedAt = hrtime(true);
                    // Today's prompt, not the one recorded alongside the answer:
                    // editing a prompt and re-running the golden set is the
                    // regression test this framework exists for.
                    $response = $this->replay($request, $model);
                    $durationMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);
                    $result = $judge->judge($request, $response);
                } catch (Throwable $e) {
                    Log::warning('AiWorkflow: Eval replay/judge failed', [
                        'request_id' => $request->id,
                        'model' => $model,
                        'error' => $e->getMessage(),
                    ]);

                    // When the judge failed after a successful replay, the
                    // replay's tokens were still paid for: keep its usage on
                    // the error row, or the cost report undercounts the run by
                    // exactly the responses that were hardest to judge.
                    AiWorkflowEvalScore::create([
                        'eval_run_id' => $evalRun->id,
                        'request_id' => $request->id,
                        'model' => $model,
                        'score' => 0.0,
                        'details' => ['error' => $e->getMessage()],
                        'input_tokens' => $response?->usage->inputTokens,
                        'output_tokens' => $response?->usage->outputTokens,
                        'thought_tokens' => $response?->usage->thoughtTokens,
                        'cache_read_tokens' => $response?->usage->cacheReadTokens,
                        'cache_write_tokens' => $response?->usage->cacheWriteTokens,
                        'duration_ms' => $durationMs,
                        'ground_truth' => $this->groundTruthFor($request),
                    ]);

                    continue;
                }

                // Persisted outside the try: only replay/judge failures are a
                // pair's own zero-score outcome. If saving a good (paid-for)
                // result fails, that must surface, not be rewritten as one.
                AiWorkflowEvalScore::create([
                    'eval_run_id' => $evalRun->id,
                    'request_id' => $request->id,
                    'model' => $model,
                    'score' => $result->score,
                    'details' => $result->details !== [] ? $result->details : null,
                    'response_text' => $response instanceof StructuredResponse ? null : $response->text,
                    'structured_response' => $response instanceof StructuredResponse ? $response->structured : null,
                    'input_tokens' => $response->usage->inputTokens,
                    'output_tokens' => $response->usage->outputTokens,
                    'thought_tokens' => $response->usage->thoughtTokens,
                    'cache_read_tokens' => $response->usage->cacheReadTokens,
                    'cache_write_tokens' => $response->usage->cacheWriteTokens,
                    'duration_ms' => $durationMs,
                    'ground_truth' => $this->groundTruthFor($request),
                    'predicted' => $result->predicted,
                ]);
            }
        }

        return $evalRun->load('scores');
    }

    /**
     * Replay one request with the failure classification and retry delay used
     * for ordinary AI requests.
     */
    private function replay(AiWorkflowRequest $request, string $model): TextResponse|StructuredResponse
    {
        $attempts = config('ai-workflow.eval.replay_tries');
        $attempts = is_int($attempts) && $attempts > 0 ? $attempts : self::DEFAULT_REPLAY_TRIES;
        $provider = $this->providerForModel($model);

        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->replayer->replay($request, useCurrentPrompts: true, model: $model);
            } catch (Throwable $error) {
                if ($attempt >= $attempts || ! $this->isRetryable($error, $provider)) {
                    throw $error;
                }

                $delayMs = $this->retryDelayMs($error, $attempt, $provider);

                Log::warning('AiWorkflow: Eval replay failed, retrying', [
                    'request_id' => $request->id,
                    'model' => $model,
                    'attempt' => $attempt,
                    'retry_delay_ms' => $delayMs,
                    'error' => $error->getMessage(),
                ]);

                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                }
            }
        }
    }

    private function isRetryable(Throwable $error, ?IntegrationProvider $provider): bool
    {
        if ($provider instanceof CustomizesRetry) {
            $retryable = $provider->isRetryable($error);

            if ($retryable !== null) {
                return $retryable;
            }
        }

        if (RetryHandler::isRetryable($error)) {
            return true;
        }

        $failureClass = FailureClassifier::classify($error, $provider);
        if ($failureClass !== FailureClass::Unknown) {
            return $failureClass->isRetryable();
        }

        $status = HttpErrorDetails::status($error);

        return $status !== null && FailureClass::fromStatus($status)->isRetryable();
    }

    private function retryDelayMs(Throwable $error, int $attempt, ?IntegrationProvider $provider): int
    {
        $status = ResponseHelper::extractStatusCode($error) ?? HttpErrorDetails::status($error);

        if ($provider instanceof CustomizesRetry) {
            $delayMs = $provider->retryDelayMs($error, $attempt, $status);

            if ($delayMs !== null) {
                return $delayMs;
            }
        }

        return RetryHandler::calculateDelayMs($error, $attempt);
    }

    /**
     * Return the registered integration provider for a valid `provider:model`
     * identifier. Return null if the identifier is invalid or the provider is
     * not registered.
     */
    private function providerForModel(string $model): ?IntegrationProvider
    {
        $parts = explode(':', $model, 2);
        if (count($parts) !== 2 || $parts[0] === '') {
            return null;
        }

        $manager = app(IntegrationManager::class);

        return $manager->has($parts[0]) ? $manager->provider($parts[0]) : null;
    }

    /**
     * The human-approved answer attached to this request by the golden-set
     * assembly, if any. Transient — it is never persisted on the request itself.
     */
    private function groundTruthFor(AiWorkflowRequest $request): ?string
    {
        $groundTruth = $request->getAttribute(AiWorkflowRequest::GROUND_TRUTH_ATTRIBUTE);

        return is_string($groundTruth) && $groundTruth !== '' ? $groundTruth : null;
    }
}

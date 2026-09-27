<?php

declare(strict_types=1);

namespace AiWorkflow\Eval;

use AiWorkflow\Models\AiWorkflowEvalRun;
use AiWorkflow\Models\AiWorkflowEvalScore;
use AiWorkflow\Models\AiWorkflowRequest;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Turns an eval run's raw scores into classification metrics.
 *
 * Treats the run as a multi-class classification problem: each golden request
 * has one human-approved label, and each model predicts one. That framing gives
 * accuracy, a confusion matrix, per-class F1 and chance-corrected agreement,
 * which say far more than an average score alone.
 */
class EvalReportMetrics
{
    /**
     * Stands in for a model that produced no answer (a replay or judge failure).
     * Kept as a visible class rather than dropped, so failures cannot quietly
     * inflate accuracy by shrinking the denominator.
     */
    public const string NO_PREDICTION = '(no answer)';

    public function __construct(
        private readonly ReviewContextLookup $context,
    ) {}

    public function compute(AiWorkflowEvalRun $run, ?string $baseline = null, int $maxDecisions = 200): EvalReport
    {
        $scores = $run->scores()->with('request')->get();

        $byModel = $scores->groupBy('model');

        $modelNames = [];
        foreach ($byModel->keys()->all() as $model) {
            $modelNames[] = (string) $model;
        }

        $resolvedBaseline = $this->resolveBaseline($run, $modelNames, $baseline);

        $summaries = [];
        foreach ($byModel as $model => $modelScores) {
            $summaries[] = $this->summarise((string) $model, $modelScores);
        }

        $summaries = $this->applyBaselineComparison($summaries, $resolvedBaseline, $this->correctnessByModel($scores));
        usort($summaries, fn (EvalReportModelSummary $a, EvalReportModelSummary $b): int => ($b->accuracy ?? -1.0) <=> ($a->accuracy ?? -1.0));

        $requestIds = $scores->pluck('request_id')->unique();
        $labelled = $scores->whereNotNull('ground_truth')->pluck('request_id')->unique()->count();

        return new EvalReport(
            runId: $run->id,
            runName: $run->name,
            runCreatedAt: $run->created_at,
            requestCount: $requestIds->count(),
            labelledCount: $labelled,
            classes: $this->collectClasses($scores),
            baseline: $resolvedBaseline,
            models: $summaries,
            decisions: $this->buildDecisions($scores, $maxDecisions),
            decisionsTotal: $requestIds->count(),
            modelsMissingPricing: $this->modelsMissingPricing($modelNames),
        );
    }

    /**
     * @param  Collection<int, AiWorkflowEvalScore>  $scores
     */
    private function summarise(string $model, Collection $scores): EvalReportModelSummary
    {
        /** @var list<array{truth: string, predicted: string}> $pairs */
        $pairs = [];
        $correct = 0;
        $errors = 0;
        $inputTokens = 0;
        $outputTokens = 0;
        $thoughtTokens = 0;
        $cacheReadTokens = 0;
        $cacheWriteTokens = 0;
        /** @var list<int> $latencies */
        $latencies = [];

        foreach ($scores as $score) {
            $inputTokens += $score->input_tokens ?? 0;
            $outputTokens += $score->output_tokens ?? 0;
            $thoughtTokens += $score->thought_tokens ?? 0;
            $cacheReadTokens += $score->cache_read_tokens ?? 0;
            $cacheWriteTokens += $score->cache_write_tokens ?? 0;

            if ($score->duration_ms !== null) {
                $latencies[] = $score->duration_ms;
            }

            if (array_key_exists('error', $score->details ?? [])) {
                $errors++;
            }

            $truth = $score->ground_truth;
            if ($truth === null || $truth === '') {
                continue;
            }

            $predicted = $this->canonicalPrediction($score->predicted);
            $pairs[] = ['truth' => $truth, 'predicted' => $predicted];

            if ($truth === $predicted) {
                $correct++;
            }
        }

        $labelled = count($pairs);
        $accuracy = $labelled > 0 ? $correct / $labelled : null;
        $confusion = $this->buildConfusion($pairs);
        $perClass = $this->perClassMetrics($confusion);

        return new EvalReportModelSummary(
            model: $model,
            scored: $scores->count(),
            labelled: $labelled,
            errors: $errors,
            correct: $correct,
            accuracy: $accuracy,
            accuracyInterval: Statistics::wilsonInterval($correct, $labelled),
            blendedScore: (float) $scores->avg('score'),
            kappa: Statistics::cohensKappa($pairs),
            macroF1: $this->macroF1($perClass),
            perClass: $perClass,
            confusion: $confusion,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            thoughtTokens: $thoughtTokens,
            cacheReadTokens: $cacheReadTokens,
            cacheWriteTokens: $cacheWriteTokens,
            cost: $this->costFor($model, $inputTokens, $cacheReadTokens, $cacheWriteTokens, $outputTokens),
            medianLatencyMs: Statistics::percentile($latencies, 0.5),
            p95LatencyMs: Statistics::percentile($latencies, 0.95),
        );
    }

    /**
     * The visible class name for a prediction. A model that answered nothing
     * (null or empty) gets the NO_PREDICTION class rather than vanishing.
     */
    private function canonicalPrediction(?string $predicted): string
    {
        return $predicted === null || $predicted === '' ? self::NO_PREDICTION : $predicted;
    }

    /**
     * @param  list<array{truth: string, predicted: string}>  $pairs
     * @return array<string, array<string, int>>
     */
    private function buildConfusion(array $pairs): array
    {
        $confusion = [];

        foreach ($pairs as $pair) {
            $confusion[$pair['truth']][$pair['predicted']] = ($confusion[$pair['truth']][$pair['predicted']] ?? 0) + 1;
        }

        ksort($confusion);

        return $confusion;
    }

    /**
     * @param  array<string, array<string, int>>  $confusion
     * @return array<string, array{support: int, precision: float, recall: float, f1: float}>
     */
    private function perClassMetrics(array $confusion): array
    {
        $labels = [];
        foreach ($confusion as $truth => $row) {
            $labels[$truth] = true;
            foreach (array_keys($row) as $predicted) {
                $labels[$predicted] = true;
            }
        }

        $metrics = [];

        foreach (array_keys($labels) as $label) {
            $truePositives = $confusion[$label][$label] ?? 0;

            $actual = array_sum($confusion[$label] ?? []);

            $predictedTotal = 0;
            foreach ($confusion as $row) {
                $predictedTotal += $row[$label] ?? 0;
            }

            $precision = $predictedTotal > 0 ? $truePositives / $predictedTotal : 0.0;
            $recall = $actual > 0 ? $truePositives / $actual : 0.0;

            $metrics[$label] = [
                'support' => $actual,
                'precision' => $precision,
                'recall' => $recall,
                'f1' => Statistics::f1($precision, $recall),
            ];
        }

        ksort($metrics);

        return $metrics;
    }

    /**
     * Macro-F1 over the classes that actually occur in the ground truth, so a
     * label the model invented cannot dilute the average.
     *
     * @param  array<string, array{support: int, precision: float, recall: float, f1: float}>  $perClass
     */
    private function macroF1(array $perClass): ?float
    {
        $scores = [];

        foreach ($perClass as $metrics) {
            if ($metrics['support'] > 0) {
                $scores[] = $metrics['f1'];
            }
        }

        if ($scores === []) {
            return null;
        }

        return array_sum($scores) / count($scores);
    }

    /**
     * Per-model map of request id to "was this labelled request answered
     * correctly", used to pair each model against the baseline
     * request-by-request for McNemar's test.
     *
     * @param  Collection<int, AiWorkflowEvalScore>  $scores
     * @return array<string, array<int, bool>>
     */
    private function correctnessByModel(Collection $scores): array
    {
        $correctness = [];

        foreach ($scores as $score) {
            $truth = $score->ground_truth;

            if ($truth === null || $truth === '') {
                continue;
            }

            $correctness[$score->model][$score->request_id] = $truth === $this->canonicalPrediction($score->predicted);
        }

        return $correctness;
    }

    /**
     * @param  list<EvalReportModelSummary>  $summaries
     * @param  array<string, array<int, bool>>  $correctness
     * @return list<EvalReportModelSummary>
     */
    private function applyBaselineComparison(array $summaries, ?string $baseline, array $correctness): array
    {
        if ($baseline === null) {
            return $summaries;
        }

        $baselineSummary = null;
        foreach ($summaries as $summary) {
            if ($summary->model === $baseline) {
                $baselineSummary = $summary;
                break;
            }
        }

        if ($baselineSummary === null) {
            return $summaries;
        }

        return array_map(
            fn (EvalReportModelSummary $summary): EvalReportModelSummary => $this->compareToBaseline($summary, $baselineSummary, $correctness),
            $summaries,
        );
    }

    /**
     * @param  array<string, array<int, bool>>  $correctness
     */
    private function compareToBaseline(EvalReportModelSummary $summary, EvalReportModelSummary $baseline, array $correctness): EvalReportModelSummary
    {
        $isBaseline = $summary->model === $baseline->model;

        $delta = null;
        if (! $isBaseline && $summary->accuracy !== null && $baseline->accuracy !== null) {
            $delta = $summary->accuracy - $baseline->accuracy;
        }

        $overlaps = null;
        if (! $isBaseline && $summary->accuracyInterval !== null && $baseline->accuracyInterval !== null) {
            $overlaps = Statistics::intervalsOverlap($summary->accuracyInterval, $baseline->accuracyInterval);
        }

        $wins = null;
        $losses = null;
        $mcNemarP = null;
        if (! $isBaseline) {
            [$wins, $losses] = $this->discordantCounts(
                $correctness[$summary->model] ?? [],
                $correctness[$baseline->model] ?? [],
            );

            if ($wins !== null && $losses !== null) {
                $mcNemarP = Statistics::mcNemarExactP($wins, $losses);
            }
        }

        return new EvalReportModelSummary(
            model: $summary->model,
            scored: $summary->scored,
            labelled: $summary->labelled,
            errors: $summary->errors,
            correct: $summary->correct,
            accuracy: $summary->accuracy,
            accuracyInterval: $summary->accuracyInterval,
            blendedScore: $summary->blendedScore,
            kappa: $summary->kappa,
            macroF1: $summary->macroF1,
            perClass: $summary->perClass,
            confusion: $summary->confusion,
            inputTokens: $summary->inputTokens,
            outputTokens: $summary->outputTokens,
            thoughtTokens: $summary->thoughtTokens,
            cacheReadTokens: $summary->cacheReadTokens,
            cacheWriteTokens: $summary->cacheWriteTokens,
            cost: $summary->cost,
            medianLatencyMs: $summary->medianLatencyMs,
            p95LatencyMs: $summary->p95LatencyMs,
            isBaseline: $isBaseline,
            accuracyDelta: $delta,
            overlapsBaselineInterval: $overlaps,
            winsVsBaseline: $wins,
            lossesVsBaseline: $losses,
            mcNemarP: $mcNemarP,
        );
    }

    /**
     * Discordant counts for McNemar's test: over the labelled requests both
     * models answered, how often exactly one of them was right.
     *
     * @param  array<int, bool>  $model
     * @param  array<int, bool>  $baseline
     * @return array{int|null, int|null} [wins, losses]; nulls when the two
     *                                   share no labelled requests.
     */
    private function discordantCounts(array $model, array $baseline): array
    {
        $paired = false;
        $wins = 0;
        $losses = 0;

        foreach ($model as $requestId => $modelCorrect) {
            if (! array_key_exists($requestId, $baseline)) {
                continue;
            }

            $paired = true;

            if ($modelCorrect && ! $baseline[$requestId]) {
                $wins++;
            } elseif (! $modelCorrect && $baseline[$requestId]) {
                $losses++;
            }
        }

        return $paired ? [$wins, $losses] : [null, null];
    }

    /**
     * @param  list<string>  $models
     */
    private function resolveBaseline(AiWorkflowEvalRun $run, array $models, ?string $requested): ?string
    {
        if ($requested !== null) {
            // A typo here must not silently become a default comparison the
            // user never asked for.
            if (! in_array($requested, $models, true)) {
                throw new InvalidArgumentException(
                    "Baseline '{$requested}' is not part of this run. Models: ".implode(', ', $models),
                );
            }

            return $requested;
        }

        // Default to the first model the run was configured with — by
        // convention that is the one currently in production.
        foreach ($run->models as $model) {
            if (in_array($model, $models, true)) {
                return $model;
            }
        }

        return $models[0] ?? null;
    }

    /**
     * @param  Collection<int, AiWorkflowEvalScore>  $scores
     * @return list<string>
     */
    private function collectClasses(Collection $scores): array
    {
        $classes = [];

        foreach ($scores as $score) {
            $truth = $score->ground_truth;

            if (is_string($truth) && $truth !== '') {
                $classes[$truth] = true;
                // Labelled items land in the confusion matrix, so a missing
                // prediction must surface here under the same name it has there
                // — otherwise the matrix would drop its failure column.
                $classes[$this->canonicalPrediction($score->predicted)] = true;
            } elseif (is_string($score->predicted) && $score->predicted !== '') {
                $classes[$score->predicted] = true;
            }
        }

        $names = array_keys($classes);
        sort($names);

        return $names;
    }

    /**
     * @param  Collection<int, AiWorkflowEvalScore>  $scores
     * @return list<EvalReportDecision>
     */
    private function buildDecisions(Collection $scores, int $maxDecisions): array
    {
        $decisions = [];

        foreach ($scores->groupBy('request_id') as $requestScores) {
            $first = $requestScores->first();

            if ($first === null) {
                continue;
            }

            $byModel = [];
            foreach ($requestScores as $score) {
                $byModel[$score->model] = [
                    'predicted' => $score->predicted,
                    'score' => (float) $score->score,
                    'correct' => $score->ground_truth !== null && $score->ground_truth === $score->predicted,
                ];
            }

            $decisions[] = new EvalReportDecision(
                requestId: $first->request_id,
                groundTruth: $first->ground_truth,
                input: $this->summariseInput($first),
                byModel: $byModel,
            );
        }

        // Contested items teach the most, so they survive truncation first.
        usort($decisions, static function (EvalReportDecision $a, EvalReportDecision $b): int {
            $byContest = (int) $b->isContested() <=> (int) $a->isContested();

            return $byContest !== 0 ? $byContest : $a->requestId <=> $b->requestId;
        });

        return $this->withContext(array_slice($decisions, 0, $maxDecisions));
    }

    /**
     * Attach the host app's context to each decision.
     *
     * Called after truncation, so the host app only ever resolves as many
     * records as the decision cap allows, however large the run.
     *
     * @param  list<EvalReportDecision>  $decisions
     * @return list<EvalReportDecision>
     */
    private function withContext(array $decisions): array
    {
        if ($decisions === [] || ! $this->context->isConfigured()) {
            return $decisions;
        }

        $ids = array_map(
            static fn (EvalReportDecision $decision): int => $decision->requestId,
            $decisions,
        );

        /** @var list<AiWorkflowRequest> $requests */
        $requests = AiWorkflowRequest::query()
            ->select(['id', 'execution_id', 'prompt_id', 'created_at'])
            ->whereIn('id', $ids)
            ->get()
            ->all();

        $contexts = $this->context->for($requests);

        if ($contexts === []) {
            return $decisions;
        }

        return array_map(
            static fn (EvalReportDecision $decision): EvalReportDecision => new EvalReportDecision(
                requestId: $decision->requestId,
                groundTruth: $decision->groundTruth,
                input: $decision->input,
                byModel: $decision->byModel,
                context: $contexts[$decision->requestId] ?? null,
            ),
            $decisions,
        );
    }

    private function summariseInput(AiWorkflowEvalScore $score, int $limit = 400): string
    {
        $messages = $score->request->messages;
        $text = '';

        foreach ($messages as $message) {
            if (! is_array($message)) {
                continue;
            }

            $content = $message['content'] ?? null;

            if (is_string($content) && $content !== '') {
                $text = $content;
            }
        }

        if ($text === '') {
            return '(no text content)';
        }

        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit).'…' : $text;
    }

    /**
     * The formula assumes that, for every provider, input tokens include
     * cache reads and writes and output tokens include thought tokens.
     */
    private function costFor(string $model, int $inputTokens, int $cacheReadTokens, int $cacheWriteTokens, int $outputTokens): ?float
    {
        $pricing = $this->pricingFor($model);

        if ($pricing === null) {
            return null;
        }

        $uncachedInputTokens = max(0, $inputTokens - $cacheReadTokens - $cacheWriteTokens);

        return (($uncachedInputTokens / 1_000_000) * $pricing['input'])
            + (($cacheReadTokens / 1_000_000) * ($pricing['cache_read'] ?? $pricing['input']))
            + (($cacheWriteTokens / 1_000_000) * ($pricing['cache_write'] ?? $pricing['input']))
            + (($outputTokens / 1_000_000) * $pricing['output']);
    }

    /**
     * @return array{input: float, output: float, cache_read: float|null, cache_write: float|null}|null
     */
    private function pricingFor(string $model): ?array
    {
        $configured = config('ai-workflow.model_pricing');

        if (! is_array($configured) || ! array_key_exists($model, $configured)) {
            return null;
        }

        $entry = $configured[$model];

        if (! is_array($entry)) {
            return null;
        }

        $input = $entry['input'] ?? null;
        $output = $entry['output'] ?? null;

        if (! is_numeric($input) || ! is_numeric($output)) {
            return null;
        }

        $cacheRead = $entry['cache_read'] ?? null;
        $cacheWrite = $entry['cache_write'] ?? null;

        return [
            'input' => (float) $input,
            'output' => (float) $output,
            'cache_read' => is_numeric($cacheRead) ? (float) $cacheRead : null,
            'cache_write' => is_numeric($cacheWrite) ? (float) $cacheWrite : null,
        ];
    }

    /**
     * @param  list<string>  $models
     * @return list<string>
     */
    private function modelsMissingPricing(array $models): array
    {
        return array_values(array_filter($models, fn (string $model): bool => $this->pricingFor($model) === null));
    }
}

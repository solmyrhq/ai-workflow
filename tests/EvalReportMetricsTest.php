<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

use AiWorkflow\Eval\EvalReportMetrics;
use AiWorkflow\Eval\EvalReportModelSummary;
use AiWorkflow\Models\AiWorkflowEvalRun;
use AiWorkflow\Models\AiWorkflowEvalScore;
use AiWorkflow\Models\AiWorkflowRequest;

class EvalReportMetricsTest extends DatabaseTestCase
{
    /** @var list<int> */
    private array $requestIds = [];

    public function test_it_computes_accuracy_and_ranks_models(): void
    {
        $run = $this->seedRun();

        $report = app(EvalReportMetrics::class)->compute($run);

        $this->assertSame(4, $report->requestCount);
        $this->assertSame(4, $report->labelledCount);
        $this->assertFalse($report->hasUnlabelledRequests());

        // Best accuracy sorts first.
        $best = $report->best();
        $this->assertNotNull($best);
        $this->assertSame('openrouter:a', $best->model);
        $this->assertEqualsWithDelta(1.0, $best->accuracy ?? 0.0, 0.0001);
        $this->assertSame(4, $best->correct);

        $b = $this->summaryFor($report->models, 'openrouter:b');
        $this->assertEqualsWithDelta(0.75, $b->accuracy ?? 0.0, 0.0001);
        $this->assertSame(3, $b->correct);
    }

    public function test_it_marks_the_baseline_and_reports_the_delta(): void
    {
        $run = $this->seedRun();

        $report = app(EvalReportMetrics::class)->compute($run);

        // The run's first configured model is the production baseline.
        $this->assertSame('openrouter:a', $report->baseline);

        $a = $this->summaryFor($report->models, 'openrouter:a');
        $this->assertTrue($a->isBaseline);
        $this->assertNull($a->accuracyDelta);

        $b = $this->summaryFor($report->models, 'openrouter:b');
        $this->assertFalse($b->isBaseline);
        $this->assertEqualsWithDelta(-0.25, $b->accuracyDelta ?? 0.0, 0.0001);

        // On four samples the Wilson intervals for 100% and 75% overlap, and
        // the report has to surface that overlap.
        $this->assertTrue($b->overlapsBaselineInterval);
    }

    public function test_it_pairs_models_against_the_baseline_with_mcnemar(): void
    {
        $run = $this->seedRun();

        $report = app(EvalReportMetrics::class)->compute($run);

        // The baseline is not compared against itself.
        $a = $this->summaryFor($report->models, 'openrouter:a');
        $this->assertNull($a->winsVsBaseline);
        $this->assertNull($a->mcNemarP);

        // B never beat A and lost one shared request: a single discordant
        // pair, and 2 * P(X <= 0 | n = 1) = 1.0 — one disagreement proves
        // nothing.
        $b = $this->summaryFor($report->models, 'openrouter:b');
        $this->assertSame(0, $b->winsVsBaseline);
        $this->assertSame(1, $b->lossesVsBaseline);
        $this->assertEqualsWithDelta(1.0, $b->mcNemarP ?? 0.0, 0.0001);
    }

    public function test_it_builds_a_confusion_matrix_and_per_class_scores(): void
    {
        $run = $this->seedRun();

        $report = app(EvalReportMetrics::class)->compute($run);
        $b = $this->summaryFor($report->models, 'openrouter:b');

        // Model B mistook one 'respond' for a 'close'.
        $this->assertSame(1, $b->confusion['respond']['respond'] ?? 0);
        $this->assertSame(1, $b->confusion['respond']['close'] ?? 0);

        // close: caught both real closes but over-predicted -> perfect recall, half precision.
        $this->assertEqualsWithDelta(0.5, $b->perClass['close']['precision'], 0.0001);
        $this->assertEqualsWithDelta(1.0, $b->perClass['close']['recall'], 0.0001);
        $this->assertEqualsWithDelta(2 / 3, $b->perClass['close']['f1'], 0.0001);

        // respond: never wrongly predicted, but missed one -> perfect precision, half recall.
        $this->assertEqualsWithDelta(1.0, $b->perClass['respond']['precision'], 0.0001);
        $this->assertEqualsWithDelta(0.5, $b->perClass['respond']['recall'], 0.0001);

        // macro-F1 averages the three ground-truth classes: (2/3 + 2/3 + 1) / 3.
        $this->assertEqualsWithDelta((2 / 3 + 2 / 3 + 1.0) / 3, $b->macroF1 ?? 0.0, 0.0001);
    }

    public function test_a_missing_prediction_counts_against_the_model(): void
    {
        $run = AiWorkflowEvalRun::create(['name' => 'Failure run', 'models' => ['openrouter:a']]);
        $requests = $this->makeRequests(2);

        $this->score($run, $requests[0], 'openrouter:a', 'respond', 'respond', 1.0);
        // The replay blew up: no prediction, but the item still counts.
        $this->score($run, $requests[1], 'openrouter:a', 'respond', null, 0.0, error: 'replay exploded');

        $report = app(EvalReportMetrics::class)->compute($run);
        $a = $this->summaryFor($report->models, 'openrouter:a');

        $this->assertSame(2, $a->labelled);
        $this->assertSame(1, $a->correct);
        $this->assertEqualsWithDelta(0.5, $a->accuracy ?? 0.0, 0.0001);
        $this->assertSame(1, $a->errors);

        // The failure is visible in the matrix rather than silently dropped...
        $this->assertSame(1, $a->confusion['respond'][EvalReportMetrics::NO_PREDICTION] ?? 0);

        // ...and in the class list, or the rendered matrix would drop its column.
        $this->assertContains(EvalReportMetrics::NO_PREDICTION, $report->classes);
    }

    public function test_best_is_null_when_nothing_is_labelled(): void
    {
        $run = AiWorkflowEvalRun::create(['name' => 'No labels', 'models' => ['openrouter:a']]);
        $requests = $this->makeRequests(2);

        $this->score($run, $requests[0], 'openrouter:a', null, 'respond', 1.0);
        $this->score($run, $requests[1], 'openrouter:a', null, 'respond', 1.0);

        $report = app(EvalReportMetrics::class)->compute($run);

        // No labels means no accuracy, so no model can honestly be "best".
        $this->assertNull($report->best());
    }

    public function test_it_costs_a_run_from_configured_pricing(): void
    {
        config(['ai-workflow.model_pricing' => [
            'openrouter:a' => ['input' => 1.0, 'output' => 2.0],
        ]]);

        $run = $this->seedRun();
        $report = app(EvalReportMetrics::class)->compute($run);

        // 4 replays x (100 in, 200 out) = 400 input + 800 output tokens.
        $a = $this->summaryFor($report->models, 'openrouter:a');
        $this->assertSame(400, $a->inputTokens);
        $this->assertSame(800, $a->outputTokens);
        $this->assertEqualsWithDelta((400 / 1_000_000 * 1.0) + (800 / 1_000_000 * 2.0), $a->cost ?? 0.0, 1e-9);

        // Per-thousand and per-correct-decision derive from that total.
        $this->assertEqualsWithDelta(($a->cost ?? 0.0) / 4 * 1000, $a->costPerThousand() ?? 0.0, 1e-9);
        $this->assertEqualsWithDelta(($a->cost ?? 0.0) / 4, $a->costPerCorrectDecision() ?? 0.0, 1e-9);

        // Model B has no pricing, so it reports null rather than a wrong number.
        $b = $this->summaryFor($report->models, 'openrouter:b');
        $this->assertNull($b->cost);
        $this->assertContains('openrouter:b', $report->modelsMissingPricing);
    }

    public function test_thought_tokens_already_in_the_output_count_are_not_billed_twice(): void
    {
        config(['ai-workflow.model_pricing' => [
            'openrouter:a' => ['input' => 1.0, 'output' => 2.0],
        ]]);

        $run = AiWorkflowEvalRun::create(['name' => 'Reasoning run', 'models' => ['openrouter:a']]);
        $requests = $this->makeRequests(2);

        $this->score($run, $requests[0], 'openrouter:a', 'respond', 'respond', 1.0, thoughtTokens: 150);
        $this->score($run, $requests[1], 'openrouter:a', 'respond', 'respond', 1.0, thoughtTokens: 100);

        $report = app(EvalReportMetrics::class)->compute($run);
        $a = $this->summaryFor($report->models, 'openrouter:a');

        $this->assertSame(250, $a->thoughtTokens);
        $this->assertEqualsWithDelta(
            (200 / 1_000_000 * 1.0) + (400 / 1_000_000 * 2.0),
            $a->cost ?? 0.0,
            1e-9,
        );
    }

    public function test_a_gemini_model_served_through_openrouter_is_not_billed_twice(): void
    {
        config(['ai-workflow.model_pricing' => [
            'openrouter:google/gemini-x' => ['input' => 1.0, 'output' => 2.0],
        ]]);

        $run = AiWorkflowEvalRun::create(['name' => 'Routed Gemini run', 'models' => ['openrouter:google/gemini-x']]);
        $requests = $this->makeRequests(2);

        $this->score($run, $requests[0], 'openrouter:google/gemini-x', 'respond', 'respond', 1.0, thoughtTokens: 150);
        $this->score($run, $requests[1], 'openrouter:google/gemini-x', 'respond', 'respond', 1.0, thoughtTokens: 100);

        $report = app(EvalReportMetrics::class)->compute($run);
        $a = $this->summaryFor($report->models, 'openrouter:google/gemini-x');

        $this->assertEqualsWithDelta(
            (200 / 1_000_000 * 1.0) + (400 / 1_000_000 * 2.0),
            $a->cost ?? 0.0,
            1e-9,
        );
    }

    public function test_direct_gemini_thought_tokens_are_billed_as_part_of_the_output(): void
    {
        config(['ai-workflow.model_pricing' => [
            'gemini:a' => ['input' => 1.0, 'output' => 2.0],
        ]]);

        $run = AiWorkflowEvalRun::create(['name' => 'Gemini reasoning run', 'models' => ['gemini:a']]);
        $requests = $this->makeRequests(2);

        $this->score($run, $requests[0], 'gemini:a', 'respond', 'respond', 1.0, thoughtTokens: 500);
        $this->score($run, $requests[1], 'gemini:a', 'respond', 'respond', 1.0, thoughtTokens: 300);

        $report = app(EvalReportMetrics::class)->compute($run);
        $a = $this->summaryFor($report->models, 'gemini:a');

        $this->assertSame(800, $a->thoughtTokens);
        $this->assertEqualsWithDelta(
            (200 / 1_000_000 * 1.0) + (400 / 1_000_000 * 2.0),
            $a->cost ?? 0.0,
            1e-9,
        );
    }

    public function test_openrouter_cache_tokens_are_priced_at_their_own_rates_within_the_input(): void
    {
        config(['ai-workflow.model_pricing' => [
            'openrouter:a' => ['input' => 1.0, 'output' => 2.0, 'cache_read' => 0.1, 'cache_write' => 1.25],
        ]]);

        $run = AiWorkflowEvalRun::create(['name' => 'Cached run', 'models' => ['openrouter:a']]);
        $requests = $this->makeRequests(2);

        $this->score($run, $requests[0], 'openrouter:a', 'respond', 'respond', 1.0, cacheReadTokens: 60, cacheWriteTokens: 20);
        $this->score($run, $requests[1], 'openrouter:a', 'respond', 'respond', 1.0, cacheReadTokens: 60, cacheWriteTokens: 20);

        $report = app(EvalReportMetrics::class)->compute($run);
        $a = $this->summaryFor($report->models, 'openrouter:a');

        $this->assertSame(120, $a->cacheReadTokens);
        $this->assertSame(40, $a->cacheWriteTokens);
        $this->assertEqualsWithDelta(
            (40 / 1_000_000 * 1.0) + (120 / 1_000_000 * 0.1) + (40 / 1_000_000 * 1.25) + (400 / 1_000_000 * 2.0),
            $a->cost ?? 0.0,
            1e-9,
        );
    }

    public function test_direct_anthropic_cache_tokens_are_priced_within_the_input(): void
    {
        config(['ai-workflow.model_pricing' => [
            'anthropic:a' => ['input' => 1.0, 'output' => 2.0, 'cache_read' => 0.1, 'cache_write' => 1.25],
        ]]);

        $run = AiWorkflowEvalRun::create(['name' => 'Anthropic cached run', 'models' => ['anthropic:a']]);
        $requests = $this->makeRequests(2);

        $this->score($run, $requests[0], 'anthropic:a', 'respond', 'respond', 1.0, cacheReadTokens: 60, cacheWriteTokens: 20);
        $this->score($run, $requests[1], 'anthropic:a', 'respond', 'respond', 1.0, cacheReadTokens: 60, cacheWriteTokens: 20);

        $report = app(EvalReportMetrics::class)->compute($run);
        $a = $this->summaryFor($report->models, 'anthropic:a');

        $this->assertEqualsWithDelta(
            (40 / 1_000_000 * 1.0) + (120 / 1_000_000 * 0.1) + (40 / 1_000_000 * 1.25) + (400 / 1_000_000 * 2.0),
            $a->cost ?? 0.0,
            1e-9,
        );
    }

    public function test_cache_tokens_fall_back_to_the_input_rate_without_cache_prices(): void
    {
        config(['ai-workflow.model_pricing' => [
            'openrouter:a' => ['input' => 1.0, 'output' => 2.0],
        ]]);

        $run = AiWorkflowEvalRun::create(['name' => 'Unpriced cache run', 'models' => ['openrouter:a']]);
        $requests = $this->makeRequests(1);

        $this->score($run, $requests[0], 'openrouter:a', 'respond', 'respond', 1.0, cacheReadTokens: 60, cacheWriteTokens: 20);

        $report = app(EvalReportMetrics::class)->compute($run);
        $a = $this->summaryFor($report->models, 'openrouter:a');

        $this->assertEqualsWithDelta(
            (100 / 1_000_000 * 1.0) + (200 / 1_000_000 * 2.0),
            $a->cost ?? 0.0,
            1e-9,
        );
    }

    public function test_it_reports_median_and_p95_latency(): void
    {
        $run = AiWorkflowEvalRun::create(['name' => 'Latency run', 'models' => ['openrouter:a']]);
        $requests = $this->makeRequests(4);

        foreach ([100, 100, 100, 5_000] as $index => $duration) {
            $this->score($run, $requests[$index], 'openrouter:a', 'respond', 'respond', 1.0, durationMs: $duration);
        }

        $report = app(EvalReportMetrics::class)->compute($run);
        $a = $this->summaryFor($report->models, 'openrouter:a');

        $this->assertEqualsWithDelta(100.0, $a->medianLatencyMs ?? 0.0, 0.0001);
        $this->assertGreaterThan(1_000.0, $a->p95LatencyMs ?? 0.0);
    }

    public function test_it_flags_an_unlabelled_golden_set(): void
    {
        $run = AiWorkflowEvalRun::create(['name' => 'Unlabelled', 'models' => ['openrouter:a']]);
        $requests = $this->makeRequests(2);

        $this->score($run, $requests[0], 'openrouter:a', 'respond', 'respond', 1.0);
        $this->score($run, $requests[1], 'openrouter:a', null, 'respond', 1.0);

        $report = app(EvalReportMetrics::class)->compute($run);

        $this->assertSame(2, $report->requestCount);
        $this->assertSame(1, $report->labelledCount);
        $this->assertTrue($report->hasUnlabelledRequests());
    }

    public function test_decisions_surface_disagreement_first(): void
    {
        $run = $this->seedRun();

        $report = app(EvalReportMetrics::class)->compute($run);

        $this->assertCount(4, $report->decisions);
        $this->assertFalse($report->isTruncated());

        // The one item the models disagreed on leads the drill-down.
        $this->assertTrue($report->decisions[0]->isContested());
    }

    public function test_decisions_can_be_truncated(): void
    {
        $run = $this->seedRun();

        $report = app(EvalReportMetrics::class)->compute($run, maxDecisions: 2);

        $this->assertCount(2, $report->decisions);
        $this->assertSame(4, $report->decisionsTotal);
        $this->assertTrue($report->isTruncated());
    }

    /**
     * Four labelled items; model A gets them all right, model B mistakes one
     * 'respond' for a 'close'.
     */
    private function seedRun(): AiWorkflowEvalRun
    {
        $run = AiWorkflowEvalRun::create([
            'name' => 'Comparison run',
            'models' => ['openrouter:a', 'openrouter:b'],
        ]);

        $requests = $this->makeRequests(4);
        $truths = ['respond', 'respond', 'close', 'wait'];
        $modelB = ['respond', 'close', 'close', 'wait'];

        foreach ($truths as $index => $truth) {
            $this->score($run, $requests[$index], 'openrouter:a', $truth, $truth, 1.0);
            $this->score($run, $requests[$index], 'openrouter:b', $truth, $modelB[$index], $truth === $modelB[$index] ? 1.0 : 0.0);
        }

        return $run;
    }

    /**
     * @return list<AiWorkflowRequest>
     */
    private function makeRequests(int $count): array
    {
        $requests = [];

        for ($i = 0; $i < $count; $i++) {
            $requests[] = AiWorkflowRequest::create([
                'prompt_id' => 'decide_next_action',
                'method' => 'sendStructuredMessages',
                'provider' => 'openrouter',
                'model' => 'test-model',
                'system_prompt' => 'Decide.',
                'messages' => [['type' => 'user', 'content' => "Ticket body {$i}"]],
                'finish_reason' => 'stop',
                'duration_ms' => 100,
            ]);
        }

        return $requests;
    }

    private function score(
        AiWorkflowEvalRun $run,
        AiWorkflowRequest $request,
        string $model,
        ?string $groundTruth,
        ?string $predicted,
        float $score,
        ?string $error = null,
        int $durationMs = 100,
        ?int $thoughtTokens = null,
        ?int $cacheReadTokens = null,
        ?int $cacheWriteTokens = null,
    ): void {
        AiWorkflowEvalScore::create([
            'eval_run_id' => $run->id,
            'request_id' => $request->id,
            'model' => $model,
            'score' => $score,
            'details' => $error !== null ? ['error' => $error] : null,
            'ground_truth' => $groundTruth,
            'predicted' => $predicted,
            'input_tokens' => 100,
            'output_tokens' => 200,
            'thought_tokens' => $thoughtTokens,
            'cache_read_tokens' => $cacheReadTokens,
            'cache_write_tokens' => $cacheWriteTokens,
            'duration_ms' => $durationMs,
        ]);
    }

    /**
     * @param  list<EvalReportModelSummary>  $summaries
     */
    private function summaryFor(array $summaries, string $model): EvalReportModelSummary
    {
        foreach ($summaries as $summary) {
            if ($summary->model === $model) {
                return $summary;
            }
        }

        $this->fail("No summary for model {$model}");
    }
}

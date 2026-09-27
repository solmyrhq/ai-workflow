<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

use AiWorkflow\Eval\GoldenSetAssembler;
use AiWorkflow\Models\AiWorkflowAnnotation;
use AiWorkflow\Models\AiWorkflowEvalScore;
use AiWorkflow\Models\AiWorkflowRequest;
use AiWorkflow\Testing\OpenRouterFake;
use AiWorkflow\Tests\Fixtures\RecordsGroundTruthJudge;
use InvalidArgumentException;

class GoldenSetTest extends DatabaseTestCase
{
    public function test_it_collects_answered_requests_with_their_label(): void
    {
        $request = $this->makeRequest();
        AiWorkflowAnnotation::create([
            'request_id' => $request->id,
            'label' => 'respond_to_customer',
        ]);

        $golden = app(GoldenSetAssembler::class)->assemble();

        $this->assertCount(1, $golden);
        $this->assertSame($request->id, $golden[0]->id);
        $this->assertSame(
            'respond_to_customer',
            $golden[0]->getAttribute(AiWorkflowRequest::GROUND_TRUTH_ATTRIBUTE),
        );
    }

    public function test_it_excludes_reviews_that_name_no_answer(): void
    {
        $request = $this->makeRequest();
        AiWorkflowAnnotation::create([
            'request_id' => $request->id,
            'reason' => 'Should have waited.',
        ]);

        // A review that settled on nothing gives nothing to score against.
        $this->assertSame([], app(GoldenSetAssembler::class)->assemble());
    }

    public function test_it_includes_an_answer_that_differs_from_the_pick(): void
    {
        $request = $this->makeRequest();
        AiWorkflowAnnotation::create([
            'request_id' => $request->id,
            'label' => 'wait_for_development_team',
            'reason' => 'Dev work outstanding.',
        ]);

        // A correction is the most valuable kind of entry: a case the model is
        // known to get wrong, with the answer it should have given.
        $golden = app(GoldenSetAssembler::class)->assemble();

        $this->assertCount(1, $golden);
        $this->assertSame(
            'wait_for_development_team',
            $golden[0]->getAttribute(AiWorkflowRequest::GROUND_TRUTH_ATTRIBUTE),
        );
    }

    public function test_corrections_keeps_only_answers_that_differ_from_the_pick(): void
    {
        // makeRequest records respond_to_customer as the winning action, so an
        // answer of respond_to_customer agrees with it and close_ticket does not.
        $agreed = $this->makeRequest();
        AiWorkflowAnnotation::create([
            'request_id' => $agreed->id,
            'label' => 'respond_to_customer',
        ]);

        $corrected = $this->makeRequest();
        AiWorkflowAnnotation::create([
            'request_id' => $corrected->id,
            'label' => 'close_ticket',
        ]);

        $this->assertCount(2, app(GoldenSetAssembler::class)->assemble());

        $corrections = app(GoldenSetAssembler::class)->assemble(correctionsOnly: true);

        $this->assertCount(1, $corrections);
        $this->assertSame($corrected->id, $corrections[0]->id);
    }

    public function test_corrections_are_filtered_before_the_limit_applies(): void
    {
        $corrected = $this->makeRequest();
        AiWorkflowAnnotation::create([
            'request_id' => $corrected->id,
            'label' => 'close_ticket',
        ]);

        // Newer, so it comes first, and it agrees with the pick.
        $agreed = $this->makeRequest();
        AiWorkflowAnnotation::create([
            'request_id' => $agreed->id,
            'label' => 'respond_to_customer',
        ]);

        // Limiting first would take the newest answer, drop it for agreeing,
        // and return nothing at all.
        $corrections = app(GoldenSetAssembler::class)->assemble(correctionsOnly: true, limit: 1);

        $this->assertCount(1, $corrections);
        $this->assertSame($corrected->id, $corrections[0]->id);
    }

    public function test_a_relabelled_request_uses_its_latest_answer(): void
    {
        // Answered, then re-reviewed with the answer cleared: it must leave the set.
        $request = $this->makeRequest();
        AiWorkflowAnnotation::create([
            'request_id' => $request->id,
            'label' => 'close_ticket',
        ]);
        AiWorkflowAnnotation::create([
            'request_id' => $request->id,
        ]);

        $this->assertSame([], app(GoldenSetAssembler::class)->assemble());
    }

    public function test_it_filters_by_prompt_and_respects_the_limit(): void
    {
        $decide = $this->makeRequest(promptId: 'decide_next_action');
        $other = $this->makeRequest(promptId: 'summarise');

        foreach ([$decide, $other] as $request) {
            AiWorkflowAnnotation::create([
                'request_id' => $request->id,
                'label' => 'respond_to_customer',
            ]);
        }

        $golden = app(GoldenSetAssembler::class)->assemble(promptId: 'decide_next_action');

        $this->assertCount(1, $golden);
        $this->assertSame($decide->id, $golden[0]->id);

        $extra = $this->makeRequest(promptId: 'decide_next_action');
        AiWorkflowAnnotation::create([
            'request_id' => $extra->id,
            'label' => 'close_ticket',
        ]);

        $limited = app(GoldenSetAssembler::class)->assemble(promptId: 'decide_next_action', limit: 1);
        $this->assertCount(1, $limited);
    }

    public function test_eval_run_from_annotations_scores_against_the_human_label(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::structured(['close_ticket' => ['likelihood' => 90]], OpenRouterFake::tokens(11, 22, reasoning: 7)));

        $request = $this->makeRequest(promptId: 'decide_next_action');
        AiWorkflowAnnotation::create([
            'request_id' => $request->id,
            'label' => 'respond_to_customer',
        ]);

        $this->artisan('eval:run', [
            '--from-annotations' => true,
            '--prompt' => 'decide_next_action',
            '--judge' => RecordsGroundTruthJudge::class,
            '--models' => 'openrouter:model-a',
        ])->assertSuccessful();

        $score = AiWorkflowEvalScore::query()->firstOrFail();

        // The human label reached the judge and was persisted for the report.
        $this->assertSame('respond_to_customer', $score->ground_truth);
        $this->assertSame('close_ticket', $score->predicted);
        $this->assertSame(11, $score->input_tokens);
        $this->assertSame(22, $score->output_tokens);
        $this->assertSame(7, $score->thought_tokens);
    }

    public function test_eval_run_from_annotations_reports_an_empty_set(): void
    {
        $this->artisan('eval:run', [
            '--from-annotations' => true,
            '--judge' => RecordsGroundTruthJudge::class,
            '--models' => 'openrouter:model-a',
        ])
            ->expectsOutputToContain('No requests found')
            ->assertSuccessful();
    }

    public function test_eval_run_can_narrow_to_corrections(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::structured(['close_ticket' => ['likelihood' => 90]], OpenRouterFake::tokens(1, 1)));

        $agreed = $this->makeRequest(promptId: 'decide_next_action');
        AiWorkflowAnnotation::create(['request_id' => $agreed->id, 'label' => 'respond_to_customer']);

        $corrected = $this->makeRequest(promptId: 'decide_next_action');
        AiWorkflowAnnotation::create(['request_id' => $corrected->id, 'label' => 'close_ticket']);

        $this->artisan('eval:run', [
            '--from-annotations' => true,
            '--corrections' => true,
            '--prompt' => 'decide_next_action',
            '--judge' => RecordsGroundTruthJudge::class,
            '--models' => 'openrouter:model-a',
        ])->assertSuccessful();

        // Replaying the answers the model already agrees with costs money and
        // settles nothing, so a corrections run must leave them out.
        $scores = AiWorkflowEvalScore::query()->get();

        $this->assertCount(1, $scores);
        $this->assertSame($corrected->id, $scores[0]->request_id);
    }

    public function test_it_rejects_a_limit_below_one(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be at least 1, got 0');

        app(GoldenSetAssembler::class)->assemble(limit: 0);
    }

    public function test_it_rejects_a_negative_limit_when_filtering_corrections(): void
    {
        // The corrections path limits with Collection::take(), which reads a
        // negative as a count from the end and would quietly return the oldest
        // correction instead of refusing.
        $this->expectException(InvalidArgumentException::class);

        app(GoldenSetAssembler::class)->assemble(correctionsOnly: true, limit: -1);
    }

    private function makeRequest(string $promptId = 'decide_next_action'): AiWorkflowRequest
    {
        return AiWorkflowRequest::create([
            'prompt_id' => $promptId,
            'method' => 'sendStructuredMessages',
            'provider' => 'openrouter',
            'model' => 'test-model',
            'system_prompt' => 'Decide.',
            'messages' => [['type' => 'user', 'content' => 'Ticket body']],
            // Two scored options, because StructuredResponsePresenter treats a
            // lone one as a coincidence rather than a set of choices and
            // reports no pick at all.
            'structured_response' => [
                'respond_to_customer' => ['likelihood' => 80, 'reasoning' => 'x'],
                'close_ticket' => ['likelihood' => 10, 'reasoning' => 'y'],
            ],
            'finish_reason' => 'stop',
            'duration_ms' => 100,
            'schema' => [
                'type' => 'object',
                'properties' => ['close_ticket' => ['type' => 'object', 'description' => 'Close']],
                'required' => [],
            ],
        ]);
    }
}

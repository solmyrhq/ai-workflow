<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

use AiWorkflow\Eval\AiWorkflowEvalJudge;
use AiWorkflow\Eval\AiWorkflowEvalResult;
use AiWorkflow\Models\AiWorkflowEvalDataset;
use AiWorkflow\Models\AiWorkflowEvalDatasetEntry;
use AiWorkflow\Models\AiWorkflowExecution;
use AiWorkflow\Models\AiWorkflowRequest;
use AiWorkflow\Responses\StructuredResponse;
use AiWorkflow\Responses\TextResponse;
use AiWorkflow\Testing\OpenRouterFake;

class EvalDatasetTest extends DatabaseTestCase
{
    private function createExecution(string $name = 'test-execution', int $requestCount = 1): AiWorkflowExecution
    {
        $execution = AiWorkflowExecution::create(['name' => $name]);

        for ($i = 0; $i < $requestCount; $i++) {
            AiWorkflowRequest::create([
                'execution_id' => $execution->id,
                'prompt_id' => 'test',
                'method' => 'sendMessages',
                'provider' => 'openrouter',
                'model' => 'test-model',
                'system_prompt' => 'You are a test assistant.',
                'messages' => [['type' => 'user', 'content' => 'Hello']],
                'response_text' => "Response {$i}",
                'finish_reason' => 'stop',
                'duration_ms' => 100,
            ]);
        }

        return $execution;
    }

    // --- eval:list ---

    public function test_list_shows_empty_when_no_datasets(): void
    {
        $this->artisan('eval:list')
            ->expectsOutput('No datasets found. Create one with: eval:add {name} {executionId}')
            ->assertExitCode(0);
    }

    public function test_list_shows_datasets(): void
    {
        $execution = $this->createExecution(requestCount: 2);
        $dataset = AiWorkflowEvalDataset::create(['name' => 'my-dataset']);
        AiWorkflowEvalDatasetEntry::create([
            'dataset_id' => $dataset->id,
            'execution_id' => $execution->id,
        ]);

        $this->artisan('eval:list')
            ->assertExitCode(0);

        $this->assertDatabaseCount('ai_workflow_eval_datasets', 1);
    }

    // --- eval:add ---

    public function test_add_creates_dataset_and_entry(): void
    {
        $execution = $this->createExecution(requestCount: 3);

        $this->artisan('eval:add', ['name' => 'my-dataset', 'executionId' => $execution->id])
            ->assertExitCode(0);

        $this->assertDatabaseHas('ai_workflow_eval_datasets', ['name' => 'my-dataset']);
        $this->assertDatabaseHas('ai_workflow_eval_dataset_entries', [
            'execution_id' => $execution->id,
        ]);
    }

    public function test_add_is_idempotent(): void
    {
        $execution = $this->createExecution();

        $this->artisan('eval:add', ['name' => 'my-dataset', 'executionId' => $execution->id])
            ->assertExitCode(0);

        $this->artisan('eval:add', ['name' => 'my-dataset', 'executionId' => $execution->id])
            ->assertExitCode(0);

        $this->assertDatabaseCount('ai_workflow_eval_dataset_entries', 1);
    }

    public function test_add_validates_execution_exists(): void
    {
        $this->artisan('eval:add', ['name' => 'my-dataset', 'executionId' => 'nonexistent-uuid'])
            ->expectsOutput("Execution 'nonexistent-uuid' not found.")
            ->assertExitCode(1);

        $this->assertDatabaseCount('ai_workflow_eval_datasets', 0);
    }

    public function test_add_multiple_executions_to_dataset(): void
    {
        $execution1 = $this->createExecution('exec-1');
        $execution2 = $this->createExecution('exec-2');

        $this->artisan('eval:add', ['name' => 'my-dataset', 'executionId' => $execution1->id])
            ->assertExitCode(0);

        $this->artisan('eval:add', ['name' => 'my-dataset', 'executionId' => $execution2->id])
            ->assertExitCode(0);

        $this->assertDatabaseCount('ai_workflow_eval_dataset_entries', 2);
    }

    // --- eval:show ---

    public function test_show_displays_dataset_contents(): void
    {
        $execution = $this->createExecution('decide #42', requestCount: 2);
        $dataset = AiWorkflowEvalDataset::create(['name' => 'my-dataset']);
        AiWorkflowEvalDatasetEntry::create([
            'dataset_id' => $dataset->id,
            'execution_id' => $execution->id,
        ]);

        $this->artisan('eval:show', ['name' => 'my-dataset'])
            ->assertExitCode(0);
    }

    public function test_show_fails_for_unknown_dataset(): void
    {
        $this->artisan('eval:show', ['name' => 'nonexistent'])
            ->expectsOutput("Dataset 'nonexistent' not found.")
            ->assertExitCode(1);
    }

    // --- eval:remove ---

    public function test_remove_removes_entry(): void
    {
        $execution = $this->createExecution();
        $execution2 = $this->createExecution('exec-2');
        $dataset = AiWorkflowEvalDataset::create(['name' => 'my-dataset']);
        AiWorkflowEvalDatasetEntry::create(['dataset_id' => $dataset->id, 'execution_id' => $execution->id]);
        AiWorkflowEvalDatasetEntry::create(['dataset_id' => $dataset->id, 'execution_id' => $execution2->id]);

        $this->artisan('eval:remove', ['name' => 'my-dataset', 'executionId' => $execution->id])
            ->assertExitCode(0);

        $this->assertDatabaseCount('ai_workflow_eval_dataset_entries', 1);
        $this->assertDatabaseHas('ai_workflow_eval_datasets', ['name' => 'my-dataset']);
    }

    public function test_remove_last_entry_deletes_dataset(): void
    {
        $execution = $this->createExecution();
        $dataset = AiWorkflowEvalDataset::create(['name' => 'my-dataset']);
        AiWorkflowEvalDatasetEntry::create(['dataset_id' => $dataset->id, 'execution_id' => $execution->id]);

        $this->artisan('eval:remove', ['name' => 'my-dataset', 'executionId' => $execution->id])
            ->assertExitCode(0);

        $this->assertDatabaseCount('ai_workflow_eval_datasets', 0);
    }

    public function test_remove_unknown_dataset_fails(): void
    {
        $this->artisan('eval:remove', ['name' => 'nonexistent', 'executionId' => 'some-uuid'])
            ->expectsOutput("Dataset 'nonexistent' not found.")
            ->assertExitCode(1);
    }

    // --- eval:run ---

    public function test_run_requires_judge(): void
    {
        $execution = $this->createExecution();
        $dataset = AiWorkflowEvalDataset::create(['name' => 'my-dataset']);
        AiWorkflowEvalDatasetEntry::create(['dataset_id' => $dataset->id, 'execution_id' => $execution->id]);

        $this->artisan('eval:run', ['name' => 'my-dataset', '--models' => 'openrouter:test-model'])
            ->expectsOutput('A judge class is required. Use --judge=App\\Eval\\MyJudge')
            ->assertExitCode(1);
    }

    public function test_run_requires_models(): void
    {
        $execution = $this->createExecution();
        $dataset = AiWorkflowEvalDataset::create(['name' => 'my-dataset']);
        AiWorkflowEvalDatasetEntry::create(['dataset_id' => $dataset->id, 'execution_id' => $execution->id]);

        $this->artisan('eval:run', ['name' => 'my-dataset', '--judge' => FixedScoreJudge::class])
            ->expectsOutput('At least one model is required. Use --models=provider:model')
            ->assertExitCode(1);
    }

    public function test_run_fails_for_unknown_dataset(): void
    {
        $this->artisan('eval:run', [
            'name' => 'nonexistent',
            '--models' => 'openrouter:test-model',
            '--judge' => FixedScoreJudge::class,
        ])
            ->expectsOutput("Dataset 'nonexistent' not found.")
            ->assertExitCode(1);
    }

    public function test_run_evaluates_dataset(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('Hello'));

        $execution = $this->createExecution(requestCount: 1);
        $dataset = AiWorkflowEvalDataset::create(['name' => 'my-dataset']);
        AiWorkflowEvalDatasetEntry::create(['dataset_id' => $dataset->id, 'execution_id' => $execution->id]);

        $this->artisan('eval:run', [
            'name' => 'my-dataset',
            '--models' => 'openrouter:test-model',
            '--judge' => FixedScoreJudge::class,
            '--run-name' => 'Test run',
        ])->assertExitCode(0);

        $this->assertDatabaseCount('ai_workflow_eval_runs', 1);
        $this->assertDatabaseCount('ai_workflow_eval_scores', 1);
        $this->assertDatabaseHas('ai_workflow_eval_runs', ['name' => 'Test run']);
    }

    public function test_run_evaluates_multiple_executions(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('A'), OpenRouterFake::completion('B'));

        $execution1 = $this->createExecution('exec-1', requestCount: 1);
        $execution2 = $this->createExecution('exec-2', requestCount: 1);
        $dataset = AiWorkflowEvalDataset::create(['name' => 'my-dataset']);
        AiWorkflowEvalDatasetEntry::create(['dataset_id' => $dataset->id, 'execution_id' => $execution1->id]);
        AiWorkflowEvalDatasetEntry::create(['dataset_id' => $dataset->id, 'execution_id' => $execution2->id]);

        $this->artisan('eval:run', [
            'name' => 'my-dataset',
            '--models' => 'openrouter:test-model',
            '--judge' => FixedScoreJudge::class,
        ])->assertExitCode(0);

        $this->assertDatabaseCount('ai_workflow_eval_scores', 2);
    }
}

/**
 * Simple judge fixture for command tests — resolved from container via FQCN.
 */
class FixedScoreJudge implements AiWorkflowEvalJudge
{
    public function judge(AiWorkflowRequest $originalRequest, TextResponse|StructuredResponse $response): AiWorkflowEvalResult
    {
        return new AiWorkflowEvalResult(0.5);
    }
}

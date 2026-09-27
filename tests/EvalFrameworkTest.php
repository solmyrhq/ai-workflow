<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

use AiWorkflow\AiWorkflowReplayer;
use AiWorkflow\Enums\FinishReason;
use AiWorkflow\Eval\AiJudge;
use AiWorkflow\Eval\AiWorkflowEvalJudge;
use AiWorkflow\Eval\AiWorkflowEvalResult;
use AiWorkflow\Eval\AiWorkflowEvalRunner;
use AiWorkflow\Exceptions\ProviderRequestException;
use AiWorkflow\Exceptions\RateLimitedException;
use AiWorkflow\Exceptions\UnexpectedFinishReasonException;
use AiWorkflow\Models\AiWorkflowEvalRun;
use AiWorkflow\Models\AiWorkflowEvalScore;
use AiWorkflow\Models\AiWorkflowRequest;
use AiWorkflow\Responses\ResponseMeta;
use AiWorkflow\Responses\StructuredResponse;
use AiWorkflow\Responses\TextResponse;
use AiWorkflow\Responses\Usage;
use AiWorkflow\Testing\OpenRouterFake;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Database\Eloquent\JsonEncodingException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response as HttpClientResponse;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Mockery\MockInterface;
use RuntimeException;

class EvalFrameworkTest extends DatabaseTestCase
{
    // --- AiJudge ---

    public function test_ai_judge_compares_original_and_new_response(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::structured(['score' => 0.85, 'reasoning' => 'Semantically equivalent with minor wording differences']));

        $request = $this->createTextRequest(responseText: 'The billing department handles your request.');
        $response = $this->makeTextResponse('Your request is handled by the billing team.');

        $judge = new AiJudge('openrouter:test-model');
        $result = $judge->judge($request, $response);

        $this->assertSame(0.85, $result->score);
        $this->assertSame('Semantically equivalent with minor wording differences', $result->details['reasoning']);
        $this->assertSame('openrouter:test-model', $result->details['judge_model']);

        $body = OpenRouterFake::sentBodies()[0];
        $this->assertSame('JudgeResult', $body['response_format']['json_schema']['name']);
        $this->assertStringContainsString('Your request is handled by the billing team.', $body['messages'][1]['content']);
    }

    public function test_ai_judge_handles_structured_responses(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::structured(['score' => 0.95, 'reasoning' => 'Same classification, minor case difference']));

        $request = $this->createStructuredRequest(['intent' => 'billing', 'payer' => 'John Smith']);
        $response = $this->makeStructuredResponse(['intent' => 'billing', 'payer' => 'john smith']);

        $result = (new AiJudge('openrouter:test-model'))->judge($request, $response);

        $this->assertSame(0.95, $result->score);
    }

    public function test_ai_judge_clamps_score_to_valid_range(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::structured(['score' => 1.5, 'reasoning' => 'Overscored']));

        $result = (new AiJudge('openrouter:test-model'))->judge($this->createTextRequest(), $this->makeTextResponse('Some response'));

        $this->assertSame(1.0, $result->score);
    }

    public function test_ai_judge_accepts_custom_prompt(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::structured(['score' => 0.7, 'reasoning' => 'Custom assessment']));

        $judge = new AiJudge('openrouter:test-model', judgePrompt: 'You are a strict judge.');
        $result = $judge->judge($this->createTextRequest(), $this->makeTextResponse('Some response'));

        $this->assertSame(0.7, $result->score);
        $this->assertSame(['role' => 'system', 'content' => 'You are a strict judge.'], OpenRouterFake::sentBodies()[0]['messages'][0]);
    }

    // --- AiWorkflowEvalRunner ---

    public function test_eval_runner_creates_run_and_scores(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('Hello world'), OpenRouterFake::completion('Hi world'));

        $request = $this->createTextRequest(responseText: 'Hello world');

        $evalRun = app(AiWorkflowEvalRunner::class)->run(
            name: 'Test eval',
            requests: [$request],
            models: ['openrouter:model-a', 'openrouter:model-b'],
            judge: $this->alwaysScoreJudge(0.9),
        );

        $this->assertInstanceOf(AiWorkflowEvalRun::class, $evalRun);
        $this->assertSame('Test eval', $evalRun->name);
        $this->assertSame(['openrouter:model-a', 'openrouter:model-b'], $evalRun->models);
        $this->assertCount(2, $evalRun->scores);

        $scoreA = $evalRun->scores->where('model', 'openrouter:model-a')->first();
        $this->assertNotNull($scoreA);
        $this->assertEqualsWithDelta(0.9, (float) $scoreA->score, 0.0001);
        $this->assertSame('Hello world', $scoreA->response_text);
    }

    public function test_eval_runner_replays_against_todays_prompt(): void
    {
        $request = AiWorkflowRequest::create([
            'prompt_id' => 'test_prompt',
            'method' => 'sendMessages',
            'provider' => 'openrouter',
            'model' => 'test-model',
            'system_prompt' => 'The prompt as it read months ago.',
            'messages' => [['type' => 'user', 'content' => 'Hello']],
            'finish_reason' => 'stop',
            'duration_ms' => 100,
        ]);

        OpenRouterFake::respondWith(OpenRouterFake::completion('Replayed'));

        app(AiWorkflowEvalRunner::class)->run(
            name: 'Prompt regression',
            requests: [$request],
            models: ['openrouter:model-a'],
            judge: $this->alwaysScoreJudge(1.0),
        );

        // Re-running the golden set after editing a prompt is the regression
        // test this framework exists for, so replaying the recorded text would
        // score a prompt nobody is using any more.
        $this->assertSame(
            ['role' => 'system', 'content' => 'You are a helpful test assistant.'],
            OpenRouterFake::sentBodies()[0]['messages'][0],
        );
    }

    public function test_eval_runner_stores_structured_response(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::structured(['intent' => 'billing']));

        $evalRun = app(AiWorkflowEvalRunner::class)->run(
            name: 'Structured eval',
            requests: [$this->createStructuredRequest(['intent' => 'billing'])],
            models: ['openrouter:model-a'],
            judge: $this->alwaysScoreJudge(1.0),
        );

        $score = $evalRun->scores->first();
        $this->assertNotNull($score);
        $this->assertNull($score->response_text);
        $this->assertSame(['intent' => 'billing'], $score->structured_response);
    }

    public function test_eval_runner_records_replay_usage_and_latency(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::structured(['intent' => 'billing'], OpenRouterFake::tokens(15, 25)));

        $evalRun = app(AiWorkflowEvalRunner::class)->run(
            name: 'Usage eval',
            requests: [$this->createStructuredRequest(['intent' => 'billing'])],
            models: ['openrouter:model-a'],
            judge: $this->alwaysScoreJudge(1.0),
        );

        $score = $evalRun->scores->first();
        $this->assertNotNull($score);
        $this->assertSame(15, $score->input_tokens);
        $this->assertSame(25, $score->output_tokens);
        $this->assertNotNull($score->duration_ms);
        $this->assertGreaterThanOrEqual(0, $score->duration_ms);
    }

    public function test_eval_runner_records_replay_cache_tokens(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::structured(['intent' => 'billing'], OpenRouterFake::tokens(15, 25, cacheRead: 10, cacheWrite: 5)));

        $evalRun = app(AiWorkflowEvalRunner::class)->run(
            name: 'Cache usage eval',
            requests: [$this->createStructuredRequest(['intent' => 'billing'])],
            models: ['openrouter:model-a'],
            judge: $this->alwaysScoreJudge(1.0),
        );

        $score = $evalRun->scores->first();
        $this->assertNotNull($score);
        $this->assertSame(10, $score->cache_read_tokens);
        $this->assertSame(5, $score->cache_write_tokens);
    }

    public function test_a_judge_failure_still_persists_the_replay_usage(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::structured(['intent' => 'billing'], OpenRouterFake::tokens(15, 25, reasoning: 5)));

        $judge = new class implements AiWorkflowEvalJudge
        {
            public function judge(AiWorkflowRequest $originalRequest, TextResponse|StructuredResponse $response): AiWorkflowEvalResult
            {
                throw new InvalidArgumentException('judge exploded');
            }
        };

        $evalRun = app(AiWorkflowEvalRunner::class)->run(
            name: 'Judge failure eval',
            requests: [$this->createStructuredRequest(['intent' => 'billing'])],
            models: ['openrouter:model-a'],
            judge: $judge,
        );

        // The replay succeeded and was paid for; the judge failing must not
        // erase the replay's usage from the cost accounting.
        $score = $evalRun->scores->first();
        $this->assertNotNull($score);
        $this->assertSame('judge exploded', $score->details['error'] ?? null);
        $this->assertSame(15, $score->input_tokens);
        $this->assertSame(25, $score->output_tokens);
        $this->assertSame(5, $score->thought_tokens);
        $this->assertNotNull($score->duration_ms);
    }

    public function test_eval_runner_persists_each_score_as_it_goes(): void
    {
        // A real run is hours of paid API calls. If results only landed at the
        // end, an interrupted run would throw away work already billed for — so
        // each score must be written as soon as it exists.
        OpenRouterFake::respondWith(OpenRouterFake::completion('one'), OpenRouterFake::completion('two'), OpenRouterFake::completion('three'));

        $requests = [$this->createTextRequest(), $this->createTextRequest(), $this->createTextRequest()];

        $baseLevel = DB::connection()->transactionLevel();

        $seen = [];
        $levels = [];
        $judge = new class($seen, $levels) implements AiWorkflowEvalJudge
        {
            /**
             * @param  list<int>  $seen
             * @param  list<int>  $levels
             */
            public function __construct(private array &$seen, private array &$levels) {}

            public function judge(AiWorkflowRequest $originalRequest, TextResponse|StructuredResponse $response): AiWorkflowEvalResult
            {
                // How many scores are already visible at this point, and
                // whether the runner has opened a transaction of its own.
                $this->seen[] = AiWorkflowEvalScore::query()->count();
                $this->levels[] = DB::connection()->transactionLevel();

                return new AiWorkflowEvalResult(1.0);
            }
        };

        app(AiWorkflowEvalRunner::class)->run(
            name: 'Incremental',
            requests: $requests,
            models: ['openrouter:model-a'],
            judge: $judge,
        );

        // Growing counts prove scores land one at a time, not in a final
        // flush. Counts alone can't prove commits — this connection would see
        // its own uncommitted rows through a run-wide transaction too — so the
        // unchanged transaction level closes that gap: nothing between these
        // writes and RefreshDatabase's wrapper holds them back.
        $this->assertSame([0, 1, 2], $seen);
        $this->assertSame([$baseLevel, $baseLevel, $baseLevel], $levels);
        $this->assertSame(3, AiWorkflowEvalScore::query()->count());
    }

    public function test_eval_runner_with_multiple_requests(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('Response 1'), OpenRouterFake::completion('Response 2'));

        $callCount = 0;
        $judge = new class($callCount) implements AiWorkflowEvalJudge
        {
            public function __construct(private int &$callCount) {}

            public function judge(AiWorkflowRequest $originalRequest, TextResponse|StructuredResponse $response): AiWorkflowEvalResult
            {
                $this->callCount++;

                return new AiWorkflowEvalResult($this->callCount === 1 ? 1.0 : 0.0);
            }
        };

        $evalRun = app(AiWorkflowEvalRunner::class)->run(
            name: 'Multi-request eval',
            requests: [$this->createTextRequest(responseText: 'Match 1'), $this->createTextRequest(responseText: 'Match 2')],
            models: ['openrouter:model-a'],
            judge: $judge,
        );

        $this->assertCount(2, $evalRun->scores);
        $this->assertEqualsWithDelta(0.5, $evalRun->averageScore(), 0.001);
    }

    public function test_eval_runner_stores_config(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('test'));

        $evalRun = app(AiWorkflowEvalRunner::class)->run(
            name: 'Config eval',
            requests: [$this->createTextRequest()],
            models: ['openrouter:model-a'],
            judge: $this->alwaysScoreJudge(0.5),
            config: ['tag' => 'classification'],
        );

        $this->assertSame(['tag' => 'classification'], $evalRun->config);
    }

    public function test_eval_runner_retries_a_replay_the_provider_fumbled(): void
    {
        $attempts = 0;

        $this->mock(AiWorkflowReplayer::class, function (MockInterface $mock) use (&$attempts): void {
            $mock->shouldReceive('replay')->twice()->andReturnUsing(function () use (&$attempts): TextResponse {
                $attempts++;

                if ($attempts === 1) {
                    throw new UnexpectedFinishReasonException(FinishReason::Unknown, 'openrouter');
                }

                return $this->makeTextResponse('Second time lucky');
            });
        });

        $evalRun = app(AiWorkflowEvalRunner::class)->run(
            name: 'Retry eval',
            requests: [$this->createTextRequest()],
            models: ['openrouter:model-a'],
            judge: $this->alwaysScoreJudge(1.0),
        );

        $score = $evalRun->scores->first();
        $this->assertNotNull($score);
        $this->assertSame('Second time lucky', $score->response_text);
        $this->assertNull($score->details['error'] ?? null);
    }

    public function test_eval_runner_retries_a_real_replay_that_ended_with_an_unknown_finish_reason(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('', 'weird'), OpenRouterFake::completion('Recovered'));

        $evalRun = app(AiWorkflowEvalRunner::class)->run(
            name: 'Real retry eval',
            requests: [$this->createTextRequest()],
            models: ['openrouter:model-a'],
            judge: $this->alwaysScoreJudge(1.0),
        );

        $this->assertSame('Recovered', $evalRun->scores->first()?->response_text);
        $this->assertCount(2, OpenRouterFake::sentBodies());
    }

    public function test_eval_runner_gives_up_after_the_configured_attempts(): void
    {
        $this->mock(AiWorkflowReplayer::class, function (MockInterface $mock): void {
            $mock->shouldReceive('replay')->twice()->andThrow(new UnexpectedFinishReasonException(FinishReason::Unknown, 'openrouter'));
        });

        $evalRun = app(AiWorkflowEvalRunner::class)->run(
            name: 'Exhausted eval',
            requests: [$this->createTextRequest()],
            models: ['openrouter:model-a'],
            judge: $this->alwaysScoreJudge(1.0),
        );

        $score = $evalRun->scores->first();
        $this->assertNotNull($score);
        $this->assertSame(0.0, (float) $score->score);
        $this->assertStringContainsString('Unexpected AI finish reason', (string) ($score->details['error'] ?? ''));
    }

    public function test_eval_runner_does_not_retry_a_request_the_provider_rejected(): void
    {
        // The status is on a previous exception, so the retry classifier must
        // walk the chain.
        $httpResponse = new HttpClientResponse(new PsrResponse(404, [], '{"error":"model unavailable"}'));
        $rejected = new RuntimeException('Replay failed', previous: new RequestException($httpResponse));

        $this->mock(AiWorkflowReplayer::class, function (MockInterface $mock) use ($rejected): void {
            $mock->shouldReceive('replay')->once()->andThrow($rejected);
        });

        $evalRun = app(AiWorkflowEvalRunner::class)->run(
            name: 'Rejected eval',
            requests: [$this->createTextRequest()],
            models: ['openrouter:model-a'],
            judge: $this->alwaysScoreJudge(1.0),
        );

        $this->assertSame(0.0, (float) ($evalRun->scores->first()?->score ?? -1.0));
    }

    public function test_eval_runner_does_not_retry_a_request_that_is_too_large(): void
    {
        $this->mock(AiWorkflowReplayer::class, function (MockInterface $mock): void {
            $mock->shouldReceive('replay')->once()->andThrow(new ProviderRequestException('Request too large', 'openrouter', 413));
        });

        $evalRun = app(AiWorkflowEvalRunner::class)->run(
            name: 'Oversized eval',
            requests: [$this->createTextRequest()],
            models: ['openrouter:model-a'],
            judge: $this->alwaysScoreJudge(1.0),
        );

        $this->assertSame(0.0, (float) ($evalRun->scores->first()?->score ?? -1.0));
    }

    public function test_eval_runner_does_not_retry_an_unrelated_runtime_failure(): void
    {
        $this->mock(AiWorkflowReplayer::class, function (MockInterface $mock): void {
            $mock->shouldReceive('replay')->once()->andThrow(new RuntimeException('local failure'));
        });

        $evalRun = app(AiWorkflowEvalRunner::class)->run(
            name: 'Local failure eval',
            requests: [$this->createTextRequest()],
            models: ['openrouter:model-a'],
            judge: $this->alwaysScoreJudge(1.0),
        );

        $this->assertSame(0.0, (float) ($evalRun->scores->first()?->score ?? -1.0));
    }

    public function test_eval_runner_retries_a_rate_limit_with_the_provider_delay(): void
    {
        $attempts = 0;
        $this->mock(AiWorkflowReplayer::class, function (MockInterface $mock) use (&$attempts): void {
            $mock->shouldReceive('replay')->twice()->andReturnUsing(function () use (&$attempts): TextResponse {
                $attempts++;

                if ($attempts === 1) {
                    throw new RateLimitedException('Slow down', 'openrouter', 429);
                }

                return $this->makeTextResponse('Recovered after throttling');
            });
        });

        $evalRun = app(AiWorkflowEvalRunner::class)->run(
            name: 'Rate-limited eval',
            requests: [$this->createTextRequest()],
            models: ['openrouter:model-a'],
            judge: $this->alwaysScoreJudge(1.0),
        );

        $this->assertSame('Recovered after throttling', $evalRun->scores->first()?->response_text);
    }

    public function test_eval_runner_handles_partial_failure(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('Good response'), OpenRouterFake::completion('Good response'));

        $callCount = 0;
        $judge = new class($callCount) implements AiWorkflowEvalJudge
        {
            public function __construct(private int &$callCount) {}

            public function judge(AiWorkflowRequest $originalRequest, TextResponse|StructuredResponse $response): AiWorkflowEvalResult
            {
                $this->callCount++;
                if ($this->callCount === 1) {
                    throw new RuntimeException('Judge exploded');
                }

                return new AiWorkflowEvalResult(0.8);
            }
        };

        $evalRun = app(AiWorkflowEvalRunner::class)->run(
            name: 'Partial failure eval',
            requests: [$this->createTextRequest(responseText: 'Original')],
            models: ['openrouter:model-a', 'openrouter:model-b'],
            judge: $judge,
        );

        // Both scores created — first failed with 0.0, second succeeded
        $this->assertCount(2, $evalRun->scores);

        $scoreA = $evalRun->scores->where('model', 'openrouter:model-a')->first();
        $this->assertNotNull($scoreA);
        $this->assertEqualsWithDelta(0.0, (float) $scoreA->score, 0.0001);
        $this->assertSame('Judge exploded', $scoreA->details['error'] ?? null);

        $scoreB = $evalRun->scores->where('model', 'openrouter:model-b')->first();
        $this->assertNotNull($scoreB);
        $this->assertEqualsWithDelta(0.8, (float) $scoreB->score, 0.0001);
    }

    public function test_a_replayed_answer_holding_a_non_finite_number_scores_as_a_failed_replay(): void
    {
        OpenRouterFake::respondWith(
            OpenRouterFake::completion('{"intent":{"confidence":-1e999}}'),
            OpenRouterFake::structured(['intent' => 'billing']),
        );

        $evalRun = app(AiWorkflowEvalRunner::class)->run(
            name: 'Non-finite answer',
            requests: [$this->createStructuredRequest(['intent' => 'billing'])],
            models: ['openrouter:model-a', 'openrouter:model-b'],
            judge: $this->alwaysScoreJudge(1.0),
        );

        $scoreA = $evalRun->scores->where('model', 'openrouter:model-a')->first();
        $this->assertNotNull($scoreA);
        $this->assertEqualsWithDelta(0.0, (float) $scoreA->score, 0.0001);
        $this->assertNull($scoreA->structured_response);
        $this->assertIsString($scoreA->details['error'] ?? null);
        $this->assertStringContainsString('too large to represent', $scoreA->details['error']);

        $scoreB = $evalRun->scores->where('model', 'openrouter:model-b')->first();
        $this->assertNotNull($scoreB);
        $this->assertEqualsWithDelta(1.0, (float) $scoreB->score, 0.0001);
    }

    public function test_eval_runner_surfaces_score_persistence_failures(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('Good response'));

        // INF can't be JSON-encoded, so persisting this (successful) result
        // throws — a storage fault, not a replay/judge one.
        $judge = $this->alwaysScoreJudge(1.0, ['confidence' => INF]);

        try {
            app(AiWorkflowEvalRunner::class)->run(
                name: 'Persistence failure',
                requests: [$this->createTextRequest()],
                models: ['openrouter:model-a'],
                judge: $judge,
            );
            $this->fail('Expected the persistence failure to surface.');
        } catch (JsonEncodingException) {
        }

        // The failure surfaced instead of being rewritten as a zero-score
        // "replay failed" row for a pair that actually succeeded.
        $this->assertSame(0, AiWorkflowEvalScore::query()->count());
    }

    public function test_eval_result_enforces_the_score_range(): void
    {
        foreach ([-0.1, 1.1, NAN, INF, -INF] as $score) {
            try {
                new AiWorkflowEvalResult($score);
                $this->fail("Expected score {$score} to be rejected.");
            } catch (InvalidArgumentException) {
            }
        }

        // The bounds themselves are valid scores.
        $this->assertSame(0.0, (new AiWorkflowEvalResult(0.0))->score);
        $this->assertSame(1.0, (new AiWorkflowEvalResult(1.0))->score);
    }

    public function test_eval_runner_with_custom_judge(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('anything'));

        $evalRun = app(AiWorkflowEvalRunner::class)->run(
            name: 'Custom judge eval',
            requests: [$this->createTextRequest()],
            models: ['openrouter:model-a'],
            judge: $this->alwaysScoreJudge(0.75, ['custom' => true]),
        );

        $score = $evalRun->scores->first();
        $this->assertNotNull($score);
        $this->assertEqualsWithDelta(0.75, (float) $score->score, 0.0001);
        $this->assertSame(['custom' => true], $score->details);
    }

    // --- Model helper methods ---

    public function test_eval_run_average_score_per_model(): void
    {
        $evalRun = AiWorkflowEvalRun::create([
            'name' => 'Test',
            'models' => ['model-a', 'model-b'],
        ]);

        $request = $this->createTextRequest();

        AiWorkflowEvalScore::create([
            'eval_run_id' => $evalRun->id,
            'request_id' => $request->id,
            'model' => 'model-a',
            'score' => 0.8,
        ]);

        AiWorkflowEvalScore::create([
            'eval_run_id' => $evalRun->id,
            'request_id' => $request->id,
            'model' => 'model-b',
            'score' => 0.4,
        ]);

        $this->assertEqualsWithDelta(0.6, $evalRun->averageScore(), 0.001);
        $this->assertEqualsWithDelta(0.8, $evalRun->averageScoreForModel('model-a'), 0.001);
        $this->assertEqualsWithDelta(0.4, $evalRun->averageScoreForModel('model-b'), 0.001);
    }

    // --- Helpers ---

    /**
     * @param  array<string, mixed>  $details
     */
    private function alwaysScoreJudge(float $score, array $details = []): AiWorkflowEvalJudge
    {
        return new class($score, $details) implements AiWorkflowEvalJudge
        {
            /**
             * @param  array<string, mixed>  $details
             */
            public function __construct(private readonly float $score, private readonly array $details) {}

            public function judge(AiWorkflowRequest $originalRequest, TextResponse|StructuredResponse $response): AiWorkflowEvalResult
            {
                return new AiWorkflowEvalResult($this->score, $this->details);
            }
        };
    }

    /**
     * @param  list<string>|null  $tags
     */
    private function createTextRequest(string $responseText = 'default response', ?array $tags = null): AiWorkflowRequest
    {
        return AiWorkflowRequest::create([
            'prompt_id' => 'test',
            'method' => 'sendMessages',
            'provider' => 'openrouter',
            'model' => 'test-model',
            'system_prompt' => 'You are a test assistant.',
            'messages' => [['type' => 'user', 'content' => 'Hello']],
            'response_text' => $responseText,
            'finish_reason' => 'stop',
            'duration_ms' => 100,
            'tags' => $tags,
        ]);
    }

    /**
     * @param  array<string, mixed>  $structured
     */
    private function createStructuredRequest(array $structured): AiWorkflowRequest
    {
        return AiWorkflowRequest::create([
            'prompt_id' => 'test',
            'method' => 'sendStructuredMessages',
            'provider' => 'openrouter',
            'model' => 'test-model',
            'system_prompt' => 'Classify this.',
            'messages' => [['type' => 'user', 'content' => 'Hello']],
            'structured_response' => $structured,
            'finish_reason' => 'stop',
            'duration_ms' => 100,
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'intent' => ['type' => 'string', 'description' => 'The intent'],
                ],
                'required' => ['intent'],
            ],
        ]);
    }

    private function makeTextResponse(string $text): TextResponse
    {
        return new TextResponse($text, FinishReason::Stop, new Usage(10, 20), new ResponseMeta('test', 'test-model'));
    }

    /**
     * @param  array<string, mixed>  $structured
     */
    private function makeStructuredResponse(array $structured): StructuredResponse
    {
        return new StructuredResponse(
            $structured,
            json_encode($structured, JSON_THROW_ON_ERROR),
            FinishReason::Stop,
            new Usage(10, 20),
            new ResponseMeta('test', 'test-model'),
        );
    }
}

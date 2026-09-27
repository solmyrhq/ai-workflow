<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

use AiWorkflow\AiService;
use AiWorkflow\Enums\GuardrailDirection;
use AiWorkflow\Events\AiWorkflowRequestCompleted;
use AiWorkflow\Events\AiWorkflowRequestFailed;
use AiWorkflow\Exceptions\GuardrailViolationException;
use AiWorkflow\Exceptions\ProviderRequestException;
use AiWorkflow\Exceptions\StructuredDecodingException;
use AiWorkflow\Exceptions\UnexpectedFinishReasonException;
use AiWorkflow\Messages\UserMessage;
use AiWorkflow\Middleware\AiWorkflowContext;
use AiWorkflow\Middleware\AiWorkflowMiddleware;
use AiWorkflow\Middleware\InputGuardrail;
use AiWorkflow\Middleware\OutputGuardrail;
use AiWorkflow\Models\AiWorkflowExecution;
use AiWorkflow\Models\AiWorkflowRequest;
use AiWorkflow\PromptData;
use AiWorkflow\Responses\Usage;
use AiWorkflow\Testing\OpenRouterFake;
use AiWorkflow\Tests\Concerns\MakesTestFixtures;
use Closure;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response as HttpClientResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use ReflectionMethod;
use RuntimeException;

class AiServiceLoggingTest extends DatabaseTestCase
{
    use MakesTestFixtures;

    public function test_request_is_logged_when_enabled(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('Hello from AI'));

        app(AiService::class)->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt());

        $this->assertDatabaseCount('ai_workflow_requests', 1);

        $request = AiWorkflowRequest::first();
        $this->assertNotNull($request);
        $this->assertSame('test', $request->prompt_id);
        $this->assertSame('sendMessages', $request->method);
        $this->assertSame('openrouter', $request->provider);
        $this->assertSame('test-model', $request->model);
        $this->assertSame('Hello from AI', $request->response_text);
        $this->assertSame('stop', $request->finish_reason);
        $this->assertNull($request->execution_id);
        $this->assertNull($request->error);
        $this->assertGreaterThanOrEqual(0, $request->duration_ms);
    }

    public function test_request_is_not_logged_when_disabled(): void
    {
        config()->set('ai-workflow.logging.enabled', false);

        OpenRouterFake::respondWith(OpenRouterFake::completion('Hello'));

        app(AiService::class)->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt());

        $this->assertDatabaseCount('ai_workflow_requests', 0);
    }

    public function test_execution_groups_requests(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('First'), OpenRouterFake::completion('Second'));

        $service = app(AiService::class);
        $service->startExecution('test_workflow', ['ticket_id' => 42]);

        $service->sendMessages(collect([new UserMessage('First')]), $this->makePrompt());
        $service->sendMessages(collect([new UserMessage('Second')]), $this->makePrompt());

        $execution = $service->endExecution();

        $this->assertNotNull($execution);
        $this->assertInstanceOf(AiWorkflowExecution::class, $execution);
        $this->assertSame('test_workflow', $execution->name);
        $this->assertSame(['ticket_id' => 42], $execution->metadata);

        $this->assertDatabaseCount('ai_workflow_requests', 2);

        $requests = AiWorkflowRequest::where('execution_id', $execution->id)->get();
        $this->assertCount(2, $requests);
        $this->assertSame('First', $requests[0]->response_text);
        $this->assertSame('Second', $requests[1]->response_text);
    }

    public function test_failed_request_is_logged_with_error(): void
    {
        config()->set('ai-workflow.retry.times', 1);
        OpenRouterFake::respondWith(OpenRouterFake::completion('Bad response', 'weird', OpenRouterFake::tokens(70, 10)));

        try {
            app(AiService::class)->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt());
        } catch (\Throwable) {
            // Expected — finish reason Unknown throws.
        }

        $this->assertDatabaseCount('ai_workflow_requests', 1);

        $request = AiWorkflowRequest::first();
        $this->assertNotNull($request);
        $this->assertNotNull($request->error);
        $this->assertStringContainsString('Unexpected AI finish reason', $request->error);
        $this->assertSame(UnexpectedFinishReasonException::class, $request->error_class);
        $this->assertNull($request->http_status);
        $this->assertIsString($request->response_body);
        $this->assertStringContainsString('"finish_reason":"weird"', $request->response_body);
        $this->assertSame(70, $request->input_tokens);
        $this->assertSame(10, $request->output_tokens);
    }

    public function test_a_failed_http_request_is_logged_with_its_status_and_body(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::error(400, 'Invalid model'));

        try {
            app(AiService::class)->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt());
        } catch (ProviderRequestException) {
        }

        $request = AiWorkflowRequest::query()->sole();
        $this->assertSame(400, $request->http_status);
        $this->assertSame('{"error":{"code":400,"message":"Invalid model"}}', $request->response_body);
        $this->assertSame(ProviderRequestException::class, $request->error_class);
    }

    public function test_a_structured_request_logs_its_cache_tokens(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::structured(['answer' => 'cached'], OpenRouterFake::tokens(1200, 40, cacheRead: 1000, cacheWrite: 150)));

        app(AiService::class)->sendStructuredMessages(collect([new UserMessage('Hello')]), $this->makePrompt(), $this->makeSchema());

        $request = AiWorkflowRequest::first();
        $this->assertNotNull($request);
        $this->assertSame(1000, $request->cache_read_tokens);
        $this->assertSame(150, $request->cache_write_tokens);
    }

    public function test_a_structured_answer_holding_a_non_finite_number_is_logged_as_a_decoding_failure(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('{"answer":1e999}'));

        try {
            app(AiService::class)->sendStructuredMessages(collect([new UserMessage('Hello')]), $this->makePrompt(), $this->makeSchema());
            $this->fail('Expected a structured decoding failure.');
        } catch (StructuredDecodingException) {
        }

        $request = AiWorkflowRequest::first();
        $this->assertNotNull($request);
        $this->assertNull($request->structured_response);
        $this->assertSame(StructuredDecodingException::class, $request->error_class);
    }

    public function test_extract_http_details_reads_provider_exception_fields(): void
    {
        $exception = new ProviderRequestException('Bad request', 'openrouter', 400, '{"error":{"message":"bad"}}');

        $details = $this->invokeExtractHttpDetails($exception);

        $this->assertSame(400, $details['status']);
        $this->assertSame('{"error":{"message":"bad"}}', $details['body']);
    }

    public function test_extract_http_details_walks_chain_for_request_exception(): void
    {
        $requestException = new RequestException(new HttpClientResponse(new Response(400, [], '{"error":"bad"}')));
        $wrapper = new RuntimeException('Request failed', previous: $requestException);

        $details = $this->invokeExtractHttpDetails($wrapper);

        $this->assertSame(400, $details['status']);
        $this->assertSame('{"error":"bad"}', $details['body']);
    }

    public function test_extract_http_details_returns_null_without_http_context(): void
    {
        $details = $this->invokeExtractHttpDetails(new RuntimeException('boom'));

        $this->assertNull($details['status']);
        $this->assertNull($details['body']);
    }

    public function test_sanitize_response_body_truncates_oversized_body(): void
    {
        $body = str_repeat('a', 70_000);

        $sanitized = $this->invokeSanitizeResponseBody($body);

        $this->assertNotNull($sanitized);
        $this->assertStringEndsWith('…[truncated]', $sanitized);
        $this->assertLessThanOrEqual(65_536, strlen($sanitized));
    }

    public function test_sanitize_response_body_strips_invalid_utf8(): void
    {
        $invalid = "valid prefix \xc3\x28 suffix";
        $this->assertFalse(mb_check_encoding($invalid, 'UTF-8'));

        $sanitized = $this->invokeSanitizeResponseBody($invalid);

        $this->assertNotNull($sanitized);
        $this->assertTrue(mb_check_encoding($sanitized, 'UTF-8'));
    }

    /**
     * @return array{status: ?int, body: ?string}
     */
    private function invokeExtractHttpDetails(?\Throwable $error): array
    {
        $method = new ReflectionMethod(AiService::class, 'extractHttpDetails');
        /** @var array{status: ?int, body: ?string} $result */
        $result = $method->invoke(app(AiService::class), $error);

        return $result;
    }

    private function invokeSanitizeResponseBody(?string $body, int $limit = 65536): ?string
    {
        $method = new ReflectionMethod(AiService::class, 'sanitizeResponseBody');
        /** @var ?string $result */
        $result = $method->invoke(app(AiService::class), $body, $limit);

        return $result;
    }

    public function test_start_execution_without_logging_is_noop(): void
    {
        config()->set('ai-workflow.logging.enabled', false);

        $service = app(AiService::class);
        $service->startExecution('noop_workflow');

        $this->assertNull($service->endExecution());
        $this->assertDatabaseCount('ai_workflow_executions', 0);
    }

    public function test_structured_request_is_logged_with_schema(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::structured(['answer' => 'test']));

        app(AiService::class)->sendStructuredMessages(collect([new UserMessage('Hello')]), $this->makePrompt(), $this->makeSchema());

        $this->assertDatabaseCount('ai_workflow_requests', 1);

        $request = AiWorkflowRequest::first();
        $this->assertNotNull($request);
        $this->assertSame('sendStructuredMessages', $request->method);
        $this->assertSame(['answer' => 'test'], $request->structured_response);
        $this->assertSame($this->makeSchema()->toArray(), $request->schema);
        $this->assertSame('test', $request->schema_name);
    }

    public function test_response_rejected_by_an_output_guardrail_is_logged_with_its_tokens(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('Bad content', usage: OpenRouterFake::tokens(80, 20, reasoning: 5)));

        $service = app(AiService::class);
        $service->addMiddleware(new class extends OutputGuardrail
        {
            protected function validate(AiWorkflowContext $context): void
            {
                throw new GuardrailViolationException('content-filter', GuardrailDirection::Output, 'Rejected');
            }
        });

        try {
            $service->sendMessages(collect([new UserMessage('Hi')]), $this->makePrompt());
            $this->fail('Expected GuardrailViolationException');
        } catch (GuardrailViolationException $e) {
            $this->assertEquals(new Usage(80, 20, thoughtTokens: 5), $e->usage());
        }

        $request = AiWorkflowRequest::first();
        $this->assertNotNull($request);
        $this->assertSame('Rejected', $request->error);
        $this->assertSame('Bad content', $request->response_text);
        $this->assertSame('stop', $request->finish_reason);
        $this->assertSame(80, $request->input_tokens);
        $this->assertSame(20, $request->output_tokens);
        $this->assertSame(5, $request->thought_tokens);
    }

    public function test_rejected_response_usage_is_added_to_usage_the_exception_already_carries(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('Bad content', usage: OpenRouterFake::tokens(80, 20)));

        $service = app(AiService::class);
        $service->addMiddleware(new class extends OutputGuardrail
        {
            protected function validate(AiWorkflowContext $context): void
            {
                $violation = new GuardrailViolationException('moderation', GuardrailDirection::Output, 'Rejected');
                $violation->recordUsage(new Usage(5, 1));

                throw $violation;
            }
        });

        try {
            $service->sendMessages(collect([new UserMessage('Hi')]), $this->makePrompt());
            $this->fail('Expected GuardrailViolationException');
        } catch (GuardrailViolationException $e) {
            $this->assertEquals(new Usage(85, 21), $e->usage());
        }
    }

    public function test_structured_failure_is_logged_with_the_input_middleware_sent(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::structured(['answer' => 'leaked'], OpenRouterFake::tokens(80, 20)));

        $service = app(AiService::class);
        $service->addMiddleware(new class implements AiWorkflowMiddleware
        {
            public function handle(AiWorkflowContext $context, Closure $next): AiWorkflowContext
            {
                $context->systemPrompt = 'Redacted system prompt.';
                $context->messages = [new UserMessage('[redacted]')];

                return $next($context);
            }
        });
        $service->addMiddleware(new class extends OutputGuardrail
        {
            protected function validate(AiWorkflowContext $context): void
            {
                throw new GuardrailViolationException('content-filter', GuardrailDirection::Output, 'Rejected');
            }
        });

        try {
            $service->sendStructuredMessages(collect([new UserMessage('My card is 4111 1111 1111 1111')]), $this->makePrompt(), $this->makeSchema());
            $this->fail('Expected GuardrailViolationException');
        } catch (GuardrailViolationException) {
        }

        $request = AiWorkflowRequest::query()->sole();
        $this->assertSame('Redacted system prompt.', $request->system_prompt);
        $this->assertSame([['type' => 'user', 'content' => '[redacted]']], $request->messages);
        $this->assertSame(['answer' => 'leaked'], $request->structured_response);
        $this->assertSame(80, $request->input_tokens);

        $this->assertSame([
            ['role' => 'system', 'content' => 'Redacted system prompt.'],
            ['role' => 'user', 'content' => '[redacted]'],
        ], OpenRouterFake::sentBodies()[0]['messages']);
    }

    public function test_structured_fallback_runs_through_middleware_and_logs_both_requests(): void
    {
        OpenRouterFake::respondWith(
            OpenRouterFake::completion('{"answer":{"likelihood":1e999}}'),
            OpenRouterFake::structured(['answer' => 'from fallback'], OpenRouterFake::tokens(90, 30)),
        );

        $middleware = new class implements AiWorkflowMiddleware
        {
            /** @var list<string> */
            public array $sentMessages = [];

            public function handle(AiWorkflowContext $context, Closure $next): AiWorkflowContext
            {
                $context->messages = [new UserMessage('[redacted]')];
                $context = $next($context);
                $this->sentMessages[] = $context->messages[0] instanceof UserMessage ? $context->messages[0]->content : '';

                return $context;
            }
        };

        $service = app(AiService::class);
        $service->addMiddleware($middleware);

        $response = $service->sendStructuredMessages(
            collect([new UserMessage('Secret')]),
            $this->makePrompt(fallbackModel: 'openrouter:fallback-model'),
            $this->makeSchema(),
        );

        $this->assertSame(['answer' => 'from fallback'], $response->structured);
        $this->assertSame(['[redacted]'], $middleware->sentMessages);

        $requests = AiWorkflowRequest::query()->orderBy('id')->get();
        $this->assertCount(2, $requests);
        $this->assertSame('test-model', $requests[0]->model);
        $this->assertSame(StructuredDecodingException::class, $requests[0]->error_class);
        $this->assertSame('fallback-model', $requests[1]->model);
        $this->assertNull($requests[1]->error);
        $this->assertSame([['type' => 'user', 'content' => '[redacted]']], $requests[1]->messages);
        $this->assertSame(90, $requests[1]->input_tokens);
    }

    public function test_structured_step_with_tools_logs_a_response_rejected_for_its_finish_reason(): void
    {
        config()->set('ai-workflow.retry.times', 1);
        OpenRouterFake::respondWith(
            OpenRouterFake::completion('The answer is 42'),
            OpenRouterFake::completion('{"answer":"42"}', 'weird', OpenRouterFake::tokens(90, 30)),
        );

        try {
            app(AiService::class)->sendStructuredMessagesWithTools(collect([new UserMessage('What is the answer?')]), $this->makePrompt(), $this->makeSchema());
            $this->fail('Expected UnexpectedFinishReasonException');
        } catch (UnexpectedFinishReasonException) {
        }

        $request = AiWorkflowRequest::query()->where('method', 'sendStructuredMessagesWithTools')->sole();
        $this->assertSame(UnexpectedFinishReasonException::class, $request->error_class);
        $this->assertIsString($request->response_body);
        $this->assertStringContainsString('{\"answer\":\"42\"}', $request->response_body);
        $this->assertSame(90, $request->input_tokens);
    }

    public function test_structured_step_with_tools_logs_a_failed_fallback(): void
    {
        config()->set('ai-workflow.retry.times', 1);
        OpenRouterFake::respondWith(
            OpenRouterFake::completion('The answer is 42'),
            OpenRouterFake::completion('{"answer":{"likelihood":1e999}}'),
            OpenRouterFake::completion('{"answer":"42"}', 'weird', OpenRouterFake::tokens(90, 30)),
        );

        try {
            app(AiService::class)->sendStructuredMessagesWithTools(
                collect([new UserMessage('What is the answer?')]),
                $this->makePrompt(fallbackModel: 'openrouter:fallback-model'),
                $this->makeSchema(),
            );
            $this->fail('Expected UnexpectedFinishReasonException');
        } catch (UnexpectedFinishReasonException) {
        }

        $requests = AiWorkflowRequest::query()->where('method', 'sendStructuredMessagesWithTools')->orderBy('id')->get();
        $this->assertCount(2, $requests);
        $this->assertSame(StructuredDecodingException::class, $requests[0]->error_class);
        $this->assertSame('fallback-model', $requests[1]->model);
        $this->assertNotNull($requests[1]->error);
        $this->assertSame(90, $requests[1]->input_tokens);
    }

    public function test_a_throwing_completed_listener_does_not_log_a_failed_text_request(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('Fine', usage: OpenRouterFake::tokens(80, 20)));

        $failedEvents = $this->throwFromCompletedListener();

        try {
            app(AiService::class)->sendMessages(collect([new UserMessage('Hi')]), $this->makePrompt());
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('Listener failed', $e->getMessage());
        }

        $request = AiWorkflowRequest::query()->sole();
        $this->assertNull($request->error);
        $this->assertSame(80, $request->input_tokens);
        $this->assertSame([], $failedEvents->all());
    }

    public function test_a_throwing_completed_listener_does_not_log_a_failed_structured_request(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::structured(['answer' => 'test'], OpenRouterFake::tokens(80, 20)));

        $failedEvents = $this->throwFromCompletedListener();

        try {
            app(AiService::class)->sendStructuredMessages(collect([new UserMessage('Hi')]), $this->makePrompt(), $this->makeSchema());
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('Listener failed', $e->getMessage());
        }

        $request = AiWorkflowRequest::query()->sole();
        $this->assertNull($request->error);
        $this->assertSame(80, $request->input_tokens);
        $this->assertSame([], $failedEvents->all());
    }

    public function test_a_throwing_completed_listener_does_not_log_a_failed_stream(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::textStream(['Fine'], usage: OpenRouterFake::tokens(80, 20)));

        $failedEvents = $this->throwFromCompletedListener();

        try {
            foreach (app(AiService::class)->streamMessages(collect([new UserMessage('Hi')]), $this->makePrompt()) as $event) {
                $this->assertNotNull($event);
            }

            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('Listener failed', $e->getMessage());
        }

        $request = AiWorkflowRequest::query()->sole();
        $this->assertNull($request->error);
        $this->assertSame(80, $request->input_tokens);
        $this->assertSame([], $failedEvents->all());
    }

    /**
     * @return Collection<int, AiWorkflowRequestFailed>
     */
    private function throwFromCompletedListener(): Collection
    {
        /** @var Collection<int, AiWorkflowRequestFailed> $failedEvents */
        $failedEvents = new Collection;

        Event::listen(AiWorkflowRequestCompleted::class, function (): void {
            throw new RuntimeException('Listener failed');
        });
        Event::listen(AiWorkflowRequestFailed::class, function (AiWorkflowRequestFailed $event) use ($failedEvents): void {
            $failedEvents->push($event);
        });

        return $failedEvents;
    }

    public function test_input_guardrail_violation_is_logged_without_tokens(): void
    {
        $service = app(AiService::class);
        $service->addMiddleware(new class extends InputGuardrail
        {
            protected function validate(AiWorkflowContext $context): void
            {
                throw new GuardrailViolationException('pii', GuardrailDirection::Input, 'Blocked');
            }
        });

        try {
            $service->sendMessages(collect([new UserMessage('Hi')]), $this->makePrompt());
            $this->fail('Expected GuardrailViolationException');
        } catch (GuardrailViolationException $e) {
            $this->assertNull($e->usage());
        }

        $request = AiWorkflowRequest::first();
        $this->assertNotNull($request);
        $this->assertNull($request->input_tokens);
    }

    public function test_execution_token_tracking(): void
    {
        OpenRouterFake::respondWith(...array_map(
            fn (string $text) => OpenRouterFake::completion($text, usage: OpenRouterFake::tokens(100, 50)),
            ['First', 'Second', 'Third'],
        ));

        $service = app(AiService::class);
        $service->startExecution('token_test');

        $service->sendMessages(collect([new UserMessage('First')]), $this->makePrompt());
        $service->sendMessages(collect([new UserMessage('Second')]), $this->makePrompt());
        $service->sendMessages(collect([new UserMessage('Third')]), $this->makePrompt());

        $execution = $service->endExecution();
        $this->assertNotNull($execution);

        $this->assertSame(3, $execution->request_count);
        $this->assertSame(300, $execution->total_input_tokens);
        $this->assertSame(150, $execution->total_output_tokens);
        $this->assertSame(450, $execution->total_tokens);
        $this->assertGreaterThanOrEqual(0, $execution->total_duration_ms);
    }

    public function test_execution_token_tracking_with_no_requests(): void
    {
        $service = app(AiService::class);
        $service->startExecution('empty_execution');
        $execution = $service->endExecution();

        $this->assertNotNull($execution);
        $this->assertSame(0, $execution->request_count);
        $this->assertSame(0, $execution->total_input_tokens);
        $this->assertSame(0, $execution->total_output_tokens);
        $this->assertSame(0, $execution->total_tokens);
        $this->assertSame(0, $execution->total_duration_ms);
    }

    public function test_prompt_tags_are_stored(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('Hello'));

        $prompt = new PromptData(
            id: 'test',
            model: 'openrouter:test-model',
            prompt: 'You are a helpful assistant.',
            tags: ['classification', 'intent'],
        );

        app(AiService::class)->sendMessages(collect([new UserMessage('Hello')]), $prompt);

        $request = AiWorkflowRequest::first();
        $this->assertNotNull($request);
        $this->assertSame(['classification', 'intent'], $request->tags);
    }

    public function test_service_tags_are_stored(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('Hello'));

        $service = app(AiService::class);
        $service->setTags(['billing', 'urgent']);
        $service->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt());

        $request = AiWorkflowRequest::first();
        $this->assertNotNull($request);
        $this->assertSame(['billing', 'urgent'], $request->tags);
    }

    public function test_prompt_and_service_tags_are_merged(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('Hello'));

        $prompt = new PromptData(
            id: 'test',
            model: 'openrouter:test-model',
            prompt: 'You are a helpful assistant.',
            tags: ['classification', 'shared'],
        );

        $service = app(AiService::class);
        $service->setTags(['billing', 'shared']);
        $service->sendMessages(collect([new UserMessage('Hello')]), $prompt);

        $request = AiWorkflowRequest::first();
        $this->assertNotNull($request);
        $this->assertSame(['classification', 'shared', 'billing'], $request->tags);
    }

    public function test_tags_null_when_none_set(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('Hello'));

        app(AiService::class)->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt());

        $request = AiWorkflowRequest::first();
        $this->assertNotNull($request);
        $this->assertNull($request->tags);
    }

    public function test_with_tag_scope_filters_correctly(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('First'), OpenRouterFake::completion('Second'));

        $service = app(AiService::class);

        $service->setTags(['billing']);
        $service->sendMessages(collect([new UserMessage('First')]), $this->makePrompt());

        $service->setTags(['support']);
        $service->sendMessages(collect([new UserMessage('Second')]), $this->makePrompt());

        $this->assertCount(1, AiWorkflowRequest::withTag('billing')->get());
        $this->assertCount(1, AiWorkflowRequest::withTag('support')->get());
        $this->assertCount(0, AiWorkflowRequest::withTag('nonexistent')->get());
    }

    public function test_with_any_tag_scope_filters_correctly(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('First'), OpenRouterFake::completion('Second'), OpenRouterFake::completion('Third'));

        $service = app(AiService::class);

        $service->setTags(['billing']);
        $service->sendMessages(collect([new UserMessage('First')]), $this->makePrompt());

        $service->setTags(['support']);
        $service->sendMessages(collect([new UserMessage('Second')]), $this->makePrompt());

        $service->setTags(['other']);
        $service->sendMessages(collect([new UserMessage('Third')]), $this->makePrompt());

        $this->assertCount(2, AiWorkflowRequest::withAnyTag(['billing', 'support'])->get());
    }

    public function test_stream_request_is_logged(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::textStream(['Streamed'], usage: OpenRouterFake::tokens(80, 40)));

        foreach (app(AiService::class)->streamMessages(collect([new UserMessage('Hello')]), $this->makePrompt()) as $event) {
            // Consume.
        }

        $this->assertDatabaseCount('ai_workflow_requests', 1);

        $request = AiWorkflowRequest::first();
        $this->assertNotNull($request);
        $this->assertSame('streamMessages', $request->method);
        $this->assertSame('openrouter', $request->provider);
        $this->assertSame('test-model', $request->model);
        $this->assertSame('stop', $request->finish_reason);
        $this->assertSame(80, $request->input_tokens);
        $this->assertSame(40, $request->output_tokens);
        $this->assertNull($request->response_text);
    }

    public function test_cache_hit_does_not_create_log_record(): void
    {
        config()->set('ai-workflow.cache.enabled', true);
        config()->set('ai-workflow.cache.store', 'array');

        $prompt = new PromptData(
            id: 'test',
            model: 'openrouter:test-model',
            prompt: 'You are a helpful assistant.',
            cacheTtl: 3600,
        );

        OpenRouterFake::respondWith(OpenRouterFake::completion('Cached response'));

        $service = app(AiService::class);

        $service->sendMessages(collect([new UserMessage('Hello')]), $prompt);
        $this->assertDatabaseCount('ai_workflow_requests', 1);

        // Second call should be a cache hit — no new log record.
        $service->sendMessages(collect([new UserMessage('Hello')]), $prompt);
        $this->assertDatabaseCount('ai_workflow_requests', 1);
    }

    public function test_thought_tokens_are_logged(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('Hello', usage: OpenRouterFake::tokens(100, 50, reasoning: 25)));

        app(AiService::class)->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt());

        $request = AiWorkflowRequest::first();
        $this->assertNotNull($request);
        $this->assertSame(100, $request->input_tokens);
        $this->assertSame(50, $request->output_tokens);
        $this->assertSame(25, $request->thought_tokens);
    }

    public function test_thought_tokens_null_when_not_present(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('Hello', usage: OpenRouterFake::tokens(100, 50)));

        app(AiService::class)->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt());

        $request = AiWorkflowRequest::first();
        $this->assertNotNull($request);
        $this->assertNull($request->thought_tokens);
    }

    public function test_execution_thought_token_tracking(): void
    {
        OpenRouterFake::respondWith(...array_map(
            fn (string $text) => OpenRouterFake::completion($text, usage: OpenRouterFake::tokens(100, 50, reasoning: 25)),
            ['First', 'Second', 'Third'],
        ));

        $service = app(AiService::class);
        $service->startExecution('thought_token_test');

        $service->sendMessages(collect([new UserMessage('First')]), $this->makePrompt());
        $service->sendMessages(collect([new UserMessage('Second')]), $this->makePrompt());
        $service->sendMessages(collect([new UserMessage('Third')]), $this->makePrompt());

        $execution = $service->endExecution();
        $this->assertNotNull($execution);

        $this->assertSame(75, $execution->total_thought_tokens);
        $this->assertSame(450, $execution->total_tokens);
    }

    public function test_messages_are_serialized_correctly(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('Response'));

        app(AiService::class)->sendMessages(collect([new UserMessage('Hello world')]), $this->makePrompt());

        $request = AiWorkflowRequest::first();
        $this->assertNotNull($request);
        $this->assertSame([['type' => 'user', 'content' => 'Hello world']], $request->messages);
    }
}

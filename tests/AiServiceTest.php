<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

use AiWorkflow\AiService;
use AiWorkflow\Enums\FinishReason;
use AiWorkflow\Events\AiWorkflowRequestFailed;
use AiWorkflow\Exceptions\StructuredDecodingException;
use AiWorkflow\Exceptions\UnexpectedFinishReasonException;
use AiWorkflow\Exceptions\UpstreamErrorException;
use AiWorkflow\Messages\UserMessage;
use AiWorkflow\Middleware\AiWorkflowContext;
use AiWorkflow\Middleware\AiWorkflowMiddleware;
use AiWorkflow\Models\AiWorkflowExecution;
use AiWorkflow\PromptData;
use AiWorkflow\Responses\TextResponse;
use AiWorkflow\Streaming\StreamEnd;
use AiWorkflow\Streaming\StreamEvent;
use AiWorkflow\Testing\OpenRouterFake;
use AiWorkflow\Tests\Concerns\MakesTestFixtures;
use AiWorkflow\Tools\Tool;
use Closure;
use Illuminate\Support\Facades\Event;
use RuntimeException;

class AiServiceTest extends TestCase
{
    use MakesTestFixtures;

    // --- Model Identifier Parsing ---

    public function test_parse_model_identifier_splits_on_first_colon(): void
    {
        $this->assertSame(['openrouter', 'anthropic/claude-4'], PromptData::parseModelIdentifier('openrouter:anthropic/claude-4'));
    }

    public function test_parse_model_identifier_handles_multiple_colons(): void
    {
        $this->assertSame(['openrouter', 'anthropic/claude:latest'], PromptData::parseModelIdentifier('openrouter:anthropic/claude:latest'));
    }

    public function test_parse_model_identifier_throws_without_colon(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("must be in 'provider:model' format");

        PromptData::parseModelIdentifier('no-colon-model');
    }

    // --- Tool Registration ---

    public function test_get_tools_returns_empty_array_by_default(): void
    {
        $this->assertSame([], app(AiService::class)->getTools());
    }

    public function test_resolve_tools_using_registers_tools(): void
    {
        $service = app(AiService::class);
        $tool = new Tool('test_tool', 'A test tool.', [], fn (): string => 'result');

        $service->resolveToolsUsing(fn (): array => [$tool]);

        $this->assertSame([$tool], $service->getTools());
    }

    public function test_tool_resolver_receives_context(): void
    {
        $service = app(AiService::class);
        $receivedContext = null;

        $service->resolveToolsUsing(function (array $context) use (&$receivedContext): array {
            $receivedContext = $context;

            return [];
        });

        $service->setContext(['customer' => 'test-customer']);
        $service->getTools();

        $this->assertSame(['customer' => 'test-customer'], $receivedContext);
    }

    public function test_send_messages_runs_the_resolved_tools(): void
    {
        OpenRouterFake::respondWith(
            OpenRouterFake::toolCalls([['name' => 'lookup_order', 'arguments' => ['id' => 'A-1']]]),
            OpenRouterFake::completion('Order A-1 has shipped.'),
        );

        $service = app(AiService::class);
        $service->resolveToolsUsing(fn (array $context): array => [
            new Tool('lookup_order', 'Look up an order.', ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]], fn (array $arguments): string => "{$context['status']}: {$arguments['id']}"),
        ]);
        $service->setContext(['status' => 'Shipped']);

        $response = $service->sendMessages(collect([new UserMessage('Where is A-1?')]), $this->makePrompt());

        $this->assertSame('Order A-1 has shipped.', $response->text);
        $this->assertSame('Shipped: A-1', $response->toolResults[0]->result);
    }

    public function test_set_context_and_get_context(): void
    {
        $service = app(AiService::class);

        $service->setContext(['key' => 'value']);

        $this->assertSame(['key' => 'value'], $service->getContext());
    }

    // --- sendMessages ---

    public function test_send_messages_returns_response(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('Hello from AI'));

        $response = app(AiService::class)->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt());

        $this->assertInstanceOf(TextResponse::class, $response);
        $this->assertSame('Hello from AI', $response->text);
        $this->assertSame('test-model', OpenRouterFake::sentBodies()[0]['model']);
    }

    public function test_send_messages_includes_extra_context(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('Response with context'));

        $extraContext = new PromptData(id: 'extra', model: 'openrouter:test-model', prompt: 'Extra context.');

        $response = app(AiService::class)->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt(), $extraContext);

        $this->assertSame('Response with context', $response->text);
        $this->assertSame(
            ['role' => 'system', 'content' => "Extra context.\n\nYou are a helpful assistant."],
            OpenRouterFake::sentBodies()[0]['messages'][0],
        );
    }

    // --- Finish Reason Handling ---

    public function test_finish_reason_stop_succeeds(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('Done'));

        $response = app(AiService::class)->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt());

        $this->assertSame(FinishReason::Stop, $response->finishReason);
    }

    public function test_finish_reason_tool_calls_succeeds(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('', 'tool_calls'));

        $response = app(AiService::class)->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt());

        $this->assertSame(FinishReason::ToolCalls, $response->finishReason);
    }

    public function test_finish_reason_unknown_throws(): void
    {
        OpenRouterFake::respondWith(...array_fill(0, 3, OpenRouterFake::completion('', 'weird')));

        $this->expectException(UnexpectedFinishReasonException::class);
        $this->expectExceptionMessage('Unexpected AI finish reason: unknown');

        app(AiService::class)->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt());
    }

    public function test_finish_reason_error_throws(): void
    {
        OpenRouterFake::respondWith(...array_fill(0, 3, OpenRouterFake::completion('', 'error')));

        $this->expectException(UnexpectedFinishReasonException::class);
        $this->expectExceptionMessage('Unexpected AI finish reason: error');

        app(AiService::class)->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt());
    }

    public function test_finish_reason_length_reports_but_returns(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('Truncated', 'length'));

        $response = app(AiService::class)->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt());

        // Length finish reason is reported but the response is still returned.
        $this->assertSame(FinishReason::Length, $response->finishReason);
    }

    public function test_finish_reason_content_filter_reports_but_returns(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('', 'content_filter'));

        $response = app(AiService::class)->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt());

        $this->assertSame(FinishReason::ContentFilter, $response->finishReason);
    }

    // --- sendStructuredMessages ---

    public function test_send_structured_messages_returns_response(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::structured(['answer' => 'test']));

        $response = app(AiService::class)->sendStructuredMessages(collect([new UserMessage('Hello')]), $this->makePrompt(), $this->makeSchema());

        $this->assertSame(['answer' => 'test'], $response->structured);
        $this->assertSame('test', OpenRouterFake::sentBodies()[0]['response_format']['json_schema']['name']);
    }

    // --- sendStructuredMessagesWithTools ---

    public function test_send_structured_messages_with_tools_happy_path(): void
    {
        OpenRouterFake::respondWith(
            // First call: text response with tools
            OpenRouterFake::completion('The answer is 42'),
            // Second call: structured extraction
            OpenRouterFake::structured(['answer' => '42']),
        );

        $response = app(AiService::class)->sendStructuredMessagesWithTools(
            collect([new UserMessage('What is the answer?')]),
            $this->makePrompt(),
            $this->makeSchema(),
        );

        $this->assertSame(['answer' => '42'], $response->structured);

        $messages = OpenRouterFake::sentBodies()[1]['messages'];
        $this->assertIsArray($messages);
        $this->assertCount(1, $messages);
        $this->assertIsArray($messages[0]);
        $this->assertStringStartsWith('The answer is 42', $messages[0]['content']);
    }

    public function test_send_structured_messages_with_tools_throws_on_empty_assistant_content(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion(''));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('text step did not produce an assistant message');

        app(AiService::class)->sendStructuredMessagesWithTools(collect([new UserMessage('Hello')]), $this->makePrompt(), $this->makeSchema());
    }

    // --- streamMessages ---

    public function test_stream_messages_yields_events(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::textStream(['Streamed ', 'response']));

        $events = $this->stream();

        $this->assertNotEmpty($events);
        $endEvent = end($events);
        $this->assertInstanceOf(StreamEnd::class, $endEvent);
        $this->assertSame(FinishReason::Stop, $endEvent->finishReason);
    }

    public function test_stream_messages_finish_reason_length_reports_but_returns(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::textStream(['Truncated'], 'length'));

        $events = $this->stream();
        $endEvent = end($events);

        $this->assertInstanceOf(StreamEnd::class, $endEvent);
        $this->assertSame(FinishReason::Length, $endEvent->finishReason);
    }

    public function test_stream_messages_finish_reason_content_filter_reports_but_returns(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::textStream(['Filtered'], 'content_filter'));

        $events = $this->stream();
        $endEvent = end($events);

        $this->assertInstanceOf(StreamEnd::class, $endEvent);
        $this->assertSame(FinishReason::ContentFilter, $endEvent->finishReason);
    }

    // --- Fallback Model ---

    public function test_structured_messages_falls_back_on_decoding_failure(): void
    {
        OpenRouterFake::respondWith(
            OpenRouterFake::completion('not json'),
            OpenRouterFake::structured(['answer' => 'from fallback']),
        );

        $response = app(AiService::class)->sendStructuredMessages(
            collect([new UserMessage('Hello')]),
            $this->makePrompt(model: 'openrouter:primary-model', fallbackModel: 'openrouter:fallback-model'),
            $this->makeSchema(),
        );

        $this->assertSame(['answer' => 'from fallback'], $response->structured);
        $this->assertSame(['primary-model', 'fallback-model'], array_column(OpenRouterFake::sentBodies(), 'model'));
    }

    public function test_structured_messages_no_fallback_when_model_override_set(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('not json'));

        $this->expectException(StructuredDecodingException::class);

        app(AiService::class)->sendStructuredMessages(
            collect([new UserMessage('Hello')]),
            $this->makePrompt(model: 'openrouter:primary-model', fallbackModel: 'openrouter:fallback-model'),
            $this->makeSchema(),
            modelOverride: 'openrouter:override-model',
        );
    }

    public function test_structured_messages_no_fallback_when_no_fallback_model(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('not json'));

        $this->expectException(StructuredDecodingException::class);

        app(AiService::class)->sendStructuredMessages(collect([new UserMessage('Hello')]), $this->makePrompt(), $this->makeSchema());
    }

    public function test_structured_messages_fall_back_when_the_answer_holds_a_non_finite_number(): void
    {
        OpenRouterFake::respondWith(
            OpenRouterFake::completion('{"answer":{"likelihood":1e999}}'),
            OpenRouterFake::structured(['answer' => 'from fallback']),
        );

        $response = app(AiService::class)->sendStructuredMessages(
            collect([new UserMessage('Hello')]),
            $this->makePrompt(fallbackModel: 'openrouter:fallback-model'),
            $this->makeSchema(),
        );

        $this->assertSame(['answer' => 'from fallback'], $response->structured);
    }

    // --- flush ---

    public function test_flush_resets_all_state(): void
    {
        $service = app(AiService::class);

        $service->setContext(['key' => 'value']);
        $service->setTags(['tag1']);
        $service->addMiddleware(new class implements AiWorkflowMiddleware
        {
            public function handle(AiWorkflowContext $context, Closure $next): AiWorkflowContext
            {
                return $next($context);
            }
        });
        $service->resolveToolsUsing(fn (): array => []);
        // startExecution requires logging; set currentExecution directly.
        $executionProp = new \ReflectionProperty($service, 'currentExecution');
        $executionProp->setValue($service, new AiWorkflowExecution);

        $service->flush();

        $this->assertSame([], $service->getContext());
        $this->assertSame([], $service->getTags());
        $this->assertSame([], $service->getTools());
        $this->assertNull($service->endExecution());

        $middlewareProp = new \ReflectionProperty($service, 'middleware');
        $this->assertSame([], $middlewareProp->getValue($service));
    }

    // --- streamMessages error handling ---

    public function test_stream_messages_dispatches_failed_event_on_error(): void
    {
        Event::fake();
        OpenRouterFake::respondWith(OpenRouterFake::stream([
            OpenRouterFake::chunk(['role' => 'assistant', 'content' => 'Partial']),
            ['error' => ['code' => 502, 'message' => 'Stream failed mid-iteration']],
        ]));

        try {
            $this->stream();
            $this->fail('Expected UpstreamErrorException was not thrown');
        } catch (UpstreamErrorException $e) {
            $this->assertSame('The openrouter stream failed: Stream failed mid-iteration', $e->getMessage());
            $this->assertSame(502, $e->errorCode);
        }

        Event::assertDispatched(AiWorkflowRequestFailed::class);
    }

    /**
     * @return list<StreamEvent>
     */
    private function stream(): array
    {
        return iterator_to_array(app(AiService::class)->streamMessages(collect([new UserMessage('Hello')]), $this->makePrompt()), false);
    }
}

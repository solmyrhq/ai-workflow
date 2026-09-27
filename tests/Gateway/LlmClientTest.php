<?php

declare(strict_types=1);

namespace AiWorkflow\Tests\Gateway;

use AiWorkflow\Enums\FinishReason;
use AiWorkflow\Exceptions\InsufficientCreditsException;
use AiWorkflow\Exceptions\ProviderConnectionException;
use AiWorkflow\Exceptions\ProviderOverloadedException;
use AiWorkflow\Exceptions\ProviderRequestException;
use AiWorkflow\Exceptions\RateLimitedException;
use AiWorkflow\Exceptions\StructuredDecodingException;
use AiWorkflow\Exceptions\UnexpectedFinishReasonException;
use AiWorkflow\Exceptions\UnknownToolException;
use AiWorkflow\Exceptions\UpstreamErrorException;
use AiWorkflow\Gateway\LlmClient;
use AiWorkflow\Gateway\StructuredCall;
use AiWorkflow\Gateway\TextCall;
use AiWorkflow\Messages\AssistantMessage;
use AiWorkflow\Messages\Attachment;
use AiWorkflow\Messages\AttachmentKind;
use AiWorkflow\Messages\SystemMessage;
use AiWorkflow\Messages\ToolCall;
use AiWorkflow\Messages\ToolResult;
use AiWorkflow\Messages\ToolResultMessage;
use AiWorkflow\Messages\UserMessage;
use AiWorkflow\Responses\ResponseMeta;
use AiWorkflow\Responses\Usage;
use AiWorkflow\Schema\ResponseSchema;
use AiWorkflow\Testing\OpenRouterFake;
use AiWorkflow\Tests\TestCase;
use AiWorkflow\Tools\Tool;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Integrations\Models\Integration;
use Integrations\Models\IntegrationRequest;
use RuntimeException;

class LlmClientTest extends TestCase
{
    private int $weatherCalls = 0;

    public function test_a_text_call_sends_the_request_and_maps_the_response(): void
    {
        OpenRouterFake::respondWith(self::fixture('chat-stop.json'));

        $response = $this->client()->text($this->weatherCall(tools: [], providerOptions: ['reasoning' => ['effort' => 'low']]), $this->integration());

        $this->assertSame([[
            'model' => 'anthropic/claude-sonnet-5',
            'messages' => [
                ['role' => 'system', 'content' => 'You answer weather questions.'],
                ['role' => 'user', 'content' => 'What is the weather in Lisbon?'],
            ],
            'max_tokens' => 1024,
            'reasoning' => ['effort' => 'low'],
        ]], OpenRouterFake::sentBodies());
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-key')
            && $request->url() === 'https://openrouter.ai/api/v1/chat/completions');

        $this->assertSame('It is 21°C and sunny in Lisbon.', $response->text);
        $this->assertSame(FinishReason::Stop, $response->finishReason);
        $this->assertEquals(new Usage(512, 14, cacheReadTokens: 384, cacheWriteTokens: 0, thoughtTokens: 0), $response->usage);
        $this->assertEquals(new ResponseMeta('gen-1758200001-Xk2pQ9rTbLm4Nv8sYc1d', 'anthropic/claude-sonnet-5', 'Anthropic', 0.001746), $response->meta);
        $this->assertEquals([new AssistantMessage('It is 21°C and sunny in Lisbon.')], $response->messages);
    }

    public function test_the_tool_loop_runs_tools_and_sends_reasoning_back_on_the_next_step(): void
    {
        OpenRouterFake::respondWith(self::fixture('tool-calls-with-reasoning.json'), self::fixture('chat-stop.json'));

        $response = $this->client()->text($this->weatherCall(), $this->integration());

        $bodies = OpenRouterFake::sentBodies();
        $this->assertCount(2, $bodies);
        $this->assertSame([[
            'type' => 'function',
            'function' => [
                'name' => 'get_weather',
                'description' => 'Get the current weather for a city.',
                'parameters' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']],
            ],
        ]], $bodies[0]['tools']);

        $toolCall = [
            'id' => 'toolu_01KxR7mWq2Tz9sLpVb3nYc4d',
            'type' => 'function',
            'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Lisbon"}'],
        ];
        $this->assertSame([
            ['role' => 'system', 'content' => 'You answer weather questions.'],
            ['role' => 'user', 'content' => 'What is the weather in Lisbon?'],
            [
                'role' => 'assistant',
                'tool_calls' => [$toolCall],
                'reasoning' => 'The user wants the weather in Lisbon, so I should call get_weather.',
                'reasoning_details' => [[
                    'type' => 'reasoning.text',
                    'text' => 'The user wants the weather in Lisbon, so I should call get_weather.',
                    'signature' => 'EqQBCkYIBxgCKkBv2mZ9cT0rWq1sHq8f3Lx6pN4dYjR7uK2eVb5gA0oS',
                    'format' => 'anthropic-claude-v1',
                    'index' => 0,
                ]],
            ],
            ['role' => 'tool', 'tool_call_id' => 'toolu_01KxR7mWq2Tz9sLpVb3nYc4d', 'content' => '{"city":"Lisbon","celsius":21}'],
        ], $bodies[1]['messages']);

        $this->assertSame(1, $this->weatherCalls);
        $this->assertSame('It is 21°C and sunny in Lisbon.', $response->text);
        $this->assertSame(FinishReason::Stop, $response->finishReason);
        $this->assertEquals(new Usage(1010, 75, cacheReadTokens: 384, cacheWriteTokens: 384, thoughtTokens: 23), $response->usage);

        $expectedCall = new ToolCall('toolu_01KxR7mWq2Tz9sLpVb3nYc4d', 'get_weather', ['city' => 'Lisbon']);
        $expectedResult = new ToolResult('toolu_01KxR7mWq2Tz9sLpVb3nYc4d', 'get_weather', ['city' => 'Lisbon'], '{"city":"Lisbon","celsius":21}');
        $this->assertSame([$expectedCall->id], array_map(fn (ToolCall $call): string => $call->id, $response->toolCalls));
        $this->assertEquals([$expectedResult], $response->toolResults);
        $this->assertCount(3, $response->messages);
        $this->assertInstanceOf(AssistantMessage::class, $response->messages[0]);
        $this->assertEquals(new ToolResultMessage([$expectedResult]), $response->messages[1]);
        $this->assertEquals(new AssistantMessage('It is 21°C and sunny in Lisbon.'), $response->messages[2]);
    }

    public function test_the_last_allowed_step_ends_the_loop_after_running_its_tools(): void
    {
        OpenRouterFake::respondWith(self::fixture('tool-calls-with-reasoning.json'));

        $response = $this->client()->text($this->weatherCall(maxSteps: 1), $this->integration());

        $this->assertCount(1, OpenRouterFake::sentBodies());
        $this->assertSame(1, $this->weatherCalls);
        $this->assertSame(FinishReason::ToolCalls, $response->finishReason);
        $this->assertCount(2, $response->messages);
    }

    public function test_a_failing_tool_reports_the_error_and_returns_it_to_the_model(): void
    {
        Exceptions::fake();
        OpenRouterFake::respondWith(self::fixture('tool-calls-with-reasoning.json'), self::fixture('chat-stop.json'));

        $broken = new Tool('get_weather', 'Get the current weather for a city.', [], function (): never {
            throw new RuntimeException('Weather service is down');
        });

        $this->client()->text($this->weatherCall(tools: [$broken]), $this->integration());

        $messages = OpenRouterFake::sentBodies()[1]['messages'];
        $this->assertIsArray($messages);
        $this->assertSame(
            ['role' => 'tool', 'tool_call_id' => 'toolu_01KxR7mWq2Tz9sLpVb3nYc4d', 'content' => 'Tool execution error: Weather service is down. This error occurred during tool execution, not due to invalid parameters.'],
            $messages[3],
        );
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_a_call_to_a_tool_that_was_not_provided_throws(): void
    {
        OpenRouterFake::respondWith(self::fixture('tool-calls-with-reasoning.json'));

        $this->expectException(UnknownToolException::class);

        $this->client()->text($this->weatherCall(tools: []), $this->integration());
    }

    public function test_each_step_is_its_own_integration_request_and_a_retry_does_not_rerun_earlier_tools(): void
    {
        OpenRouterFake::respondWith(
            self::fixture('tool-calls-with-reasoning.json'),
            OpenRouterFake::error(503, 'Service unavailable'),
            self::fixture('chat-stop.json'),
        );

        $response = $this->client()->text($this->weatherCall(), $this->integration());

        $this->assertSame('It is 21°C and sunny in Lisbon.', $response->text);
        $this->assertSame(1, $this->weatherCalls);
        $this->assertSame(3, IntegrationRequest::query()->count());
        $this->assertSame(1, IntegrationRequest::query()->where('response_success', false)->count());
    }

    public function test_an_unknown_finish_reason_is_retried_and_then_thrown(): void
    {
        OpenRouterFake::respondWith(
            OpenRouterFake::completion('', 'weird'),
            OpenRouterFake::completion('', 'weird'),
            OpenRouterFake::completion('', 'weird'),
        );

        try {
            $this->client()->text($this->weatherCall(tools: []), $this->integration());
            $this->fail('Expected UnexpectedFinishReasonException.');
        } catch (UnexpectedFinishReasonException $e) {
            $this->assertSame(FinishReason::Unknown, $e->finishReason);
            $this->assertIsString($e->responseBody);
            $this->assertStringContainsString('"finish_reason":"weird"', $e->responseBody);
        }

        $this->assertCount(3, OpenRouterFake::sentBodies());
    }

    public function test_an_error_finish_reason_is_retried(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('', 'error'), self::fixture('chat-stop.json'));

        $response = $this->client()->text($this->weatherCall(tools: []), $this->integration());

        $this->assertSame(FinishReason::Stop, $response->finishReason);
        $this->assertCount(2, OpenRouterFake::sentBodies());
    }

    public function test_length_and_content_filter_finish_reasons_are_returned(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('Partial', 'length'), OpenRouterFake::completion('', 'content_filter'));

        $this->assertSame(FinishReason::Length, $this->client()->text($this->weatherCall(tools: []), $this->integration())->finishReason);
        $this->assertSame(FinishReason::ContentFilter, $this->client()->text($this->weatherCall(tools: []), $this->integration())->finishReason);
    }

    public function test_a_client_error_is_thrown_with_its_status_and_body_and_not_retried(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::error(400, 'Invalid model'));

        try {
            $this->client()->text($this->weatherCall(tools: []), $this->integration());
            $this->fail('Expected ProviderRequestException.');
        } catch (ProviderRequestException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('{"error":{"code":400,"message":"Invalid model"}}', $e->responseBody);
            $this->assertSame('The openrouter request failed with HTTP 400: Invalid model', $e->getMessage());
        }

        $this->assertCount(1, OpenRouterFake::sentBodies());
    }

    public function test_insufficient_credits_are_not_retried(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::error(402, 'Insufficient credits'));

        $this->expectException(InsufficientCreditsException::class);

        try {
            $this->client()->text($this->weatherCall(tools: []), $this->integration());
        } finally {
            $this->assertCount(1, OpenRouterFake::sentBodies());
        }
    }

    public function test_a_rate_limit_keeps_the_retry_after_header(): void
    {
        config()->set('ai-workflow.retry.times', 1);
        OpenRouterFake::respondWith(OpenRouterFake::error(429, 'Rate limit exceeded', ['Retry-After' => '7']));

        try {
            $this->client()->text($this->weatherCall(tools: []), $this->integration());
            $this->fail('Expected RateLimitedException.');
        } catch (RateLimitedException $e) {
            $this->assertSame(429, $e->getStatusCode());
            $this->assertSame(7, $e->retryAfter);
        }
    }

    public function test_an_overloaded_provider_is_retried(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::error(502), OpenRouterFake::error(503), self::fixture('chat-stop.json'));

        $response = $this->client()->text($this->weatherCall(tools: []), $this->integration());

        $this->assertSame(FinishReason::Stop, $response->finishReason);
        $this->assertCount(3, OpenRouterFake::sentBodies());
    }

    public function test_an_overloaded_provider_is_thrown_after_the_last_attempt(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::error(503), OpenRouterFake::error(503), OpenRouterFake::error(503));

        $this->expectException(ProviderOverloadedException::class);

        $this->client()->text($this->weatherCall(tools: []), $this->integration());
    }

    public function test_a_connection_failure_is_retried_and_then_thrown(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::connectionFailure(), OpenRouterFake::connectionFailure(), OpenRouterFake::connectionFailure());

        $this->expectException(ProviderConnectionException::class);

        $this->client()->text($this->weatherCall(tools: []), $this->integration());
    }

    public function test_an_error_envelope_on_a_200_with_a_client_error_code_is_not_retried(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::errorEnvelope(400, 'Bad request'));

        try {
            $this->client()->text($this->weatherCall(tools: []), $this->integration());
            $this->fail('Expected UpstreamErrorException.');
        } catch (UpstreamErrorException $e) {
            $this->assertSame(400, $e->errorCode);
            $this->assertSame('{"error":{"code":400,"message":"Bad request"}}', $e->responseBody);
        }

        $this->assertCount(1, OpenRouterFake::sentBodies());
    }

    public function test_an_error_envelope_on_a_200_with_a_server_error_code_is_retried(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::errorEnvelope(502, 'Upstream error'), self::fixture('chat-stop.json'));

        $this->assertSame(FinishReason::Stop, $this->client()->text($this->weatherCall(tools: []), $this->integration())->finishReason);
        $this->assertCount(2, OpenRouterFake::sentBodies());
    }

    public function test_a_structured_call_sends_the_schema_strictly_and_decodes_the_reply(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion("```json\n{\"city\":\"Lisbon\",\"celsius\":21}\n```"));

        $response = $this->client()->structured($this->forecastCall(), $this->integration());

        $body = OpenRouterFake::sentBodies()[0];
        $this->assertSame([
            'type' => 'json_schema',
            'json_schema' => ['name' => 'forecast', 'strict' => true, 'schema' => self::forecastSchema()],
        ], $body['response_format']);
        $this->assertTrue($body['structured_outputs']);
        $this->assertSame(2048, $body['max_tokens']);
        $this->assertArrayNotHasKey('tools', $body);

        $this->assertSame(['city' => 'Lisbon', 'celsius' => 21], $response->structured);
        $this->assertSame(FinishReason::Stop, $response->finishReason);
    }

    public function test_undecodable_structured_output_is_thrown_without_a_retry(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('Sorry, I cannot help with that.'));

        try {
            $this->client()->structured($this->forecastCall(), $this->integration());
            $this->fail('Expected StructuredDecodingException.');
        } catch (StructuredDecodingException $e) {
            $this->assertSame('Sorry, I cannot help with that.', $e->text);
        }

        $this->assertCount(1, OpenRouterFake::sentBodies());
    }

    public function test_structured_output_with_a_number_too_large_to_represent_is_undecodable(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('{"city":"Lisbon","celsius":1e999}'));

        $this->expectException(StructuredDecodingException::class);

        $this->client()->structured($this->forecastCall(), $this->integration());
    }

    public function test_structured_output_that_is_a_list_is_undecodable(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('[1, 2]'));

        $this->expectException(StructuredDecodingException::class);

        $this->client()->structured($this->forecastCall(), $this->integration());
    }

    public function test_attachments_and_mid_conversation_system_messages_are_mapped(): void
    {
        OpenRouterFake::respondWith(self::fixture('chat-stop.json'));

        $call = new TextCall('openrouter', 'anthropic/claude-sonnet-5', 'Describe what you are given.', [
            new UserMessage('Here are the files.', [
                Attachment::text('Context: '),
                Attachment::fromBase64(AttachmentKind::Image, 'aW1hZ2U=', 'image/png'),
                Attachment::fromUrl(AttachmentKind::Document, 'https://example.com/report.pdf', 'application/pdf', 'Report'),
                Attachment::fromBase64(AttachmentKind::Document, 'cGRm', 'application/pdf', 'Invoice'),
                Attachment::fromBase64(AttachmentKind::Audio, 'YXVkaW8=', 'audio/mpeg'),
                Attachment::fromUrl(AttachmentKind::Video, 'https://example.com/clip.mp4', 'video/mp4'),
                Attachment::fromBase64(AttachmentKind::Media, 'dmlkZW8=', 'video/webm'),
            ]),
            new SystemMessage('Answer in Portuguese.'),
        ]);

        $this->client()->text($call, $this->integration());

        $this->assertSame([
            ['role' => 'system', 'content' => "Describe what you are given.\n\nAnswer in Portuguese."],
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => 'Context: Here are the files.'],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,aW1hZ2U=']],
                ['type' => 'file', 'file' => ['filename' => 'Report', 'file_data' => 'https://example.com/report.pdf']],
                ['type' => 'file', 'file' => ['filename' => 'Invoice', 'file_data' => 'data:application/pdf;base64,cGRm']],
                ['type' => 'input_audio', 'input_audio' => ['format' => 'mp3', 'data' => 'YXVkaW8=']],
                ['type' => 'video_url', 'video_url' => ['url' => 'https://example.com/clip.mp4']],
                ['type' => 'video_url', 'video_url' => ['url' => 'data:video/webm;base64,dmlkZW8=']],
            ]],
        ], OpenRouterFake::sentBodies()[0]['messages']);
    }

    public function test_client_options_reach_the_http_client(): void
    {
        config()->set('ai-workflow.client_options', ['timeout' => 42.5, 'headers' => ['X-Trace' => 'abc']]);
        OpenRouterFake::respondWith(self::fixture('chat-stop.json'));

        $this->client()->text($this->weatherCall(tools: []), $this->integration());

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Trace', 'abc'));
    }

    public function test_openrouter_calls_fail_without_an_api_key_on_the_integration(): void
    {
        $integration = $this->integration();
        $integration->update(['credentials' => ['api_key' => '']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has no api_key credential');

        $this->client()->text($this->weatherCall(tools: []), $integration);
    }

    private function client(): LlmClient
    {
        return $this->app->make(LlmClient::class);
    }

    private function integration(): Integration
    {
        return Integration::query()->where('provider', 'openrouter')->firstOrFail();
    }

    /**
     * @param  list<Tool>|null  $tools
     * @param  array<string, mixed>  $providerOptions
     */
    private function weatherCall(?array $tools = null, int $maxSteps = 5, array $providerOptions = []): TextCall
    {
        return new TextCall(
            'openrouter',
            'anthropic/claude-sonnet-5',
            'You answer weather questions.',
            [new UserMessage('What is the weather in Lisbon?')],
            $tools ?? [$this->weatherTool()],
            $maxSteps,
            1024,
            $providerOptions,
        );
    }

    private function weatherTool(): Tool
    {
        return new Tool(
            'get_weather',
            'Get the current weather for a city.',
            ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']],
            function (array $arguments): array {
                $this->weatherCalls++;

                return ['city' => $arguments['city'] ?? null, 'celsius' => 21];
            },
        );
    }

    private function forecastCall(): StructuredCall
    {
        return new StructuredCall(
            'openrouter',
            'anthropic/claude-sonnet-5',
            'Extract the forecast.',
            [new UserMessage('It is 21 degrees in Lisbon.')],
            new ResponseSchema('forecast', self::forecastSchema()),
            2048,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function forecastSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['city' => ['type' => 'string'], 'celsius' => ['type' => 'number']],
            'required' => ['city', 'celsius'],
            'additionalProperties' => false,
        ];
    }

    private static function fixture(string $name): PromiseInterface
    {
        $contents = file_get_contents(__DIR__.'/../Fixtures/Http/openrouter/'.$name);
        self::assertIsString($contents);

        return Http::response($contents, 200, ['Content-Type' => 'application/json']);
    }
}

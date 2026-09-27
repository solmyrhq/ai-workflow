<?php

declare(strict_types=1);

namespace AiWorkflow\Tests\Gateway;

use AiWorkflow\Enums\FinishReason;
use AiWorkflow\Exceptions\UnexpectedFinishReasonException;
use AiWorkflow\Exceptions\UpstreamErrorException;
use AiWorkflow\Gateway\LlmClient;
use AiWorkflow\Gateway\TextCall;
use AiWorkflow\Messages\ToolResult;
use AiWorkflow\Messages\UserMessage;
use AiWorkflow\Responses\ResponseMeta;
use AiWorkflow\Responses\Usage;
use AiWorkflow\Streaming\ReasoningDelta;
use AiWorkflow\Streaming\StreamEnd;
use AiWorkflow\Streaming\StreamEvent;
use AiWorkflow\Streaming\StreamStart;
use AiWorkflow\Streaming\TextDelta;
use AiWorkflow\Streaming\ToolCallEvent;
use AiWorkflow\Streaming\ToolResultEvent;
use AiWorkflow\Testing\OpenRouterFake;
use AiWorkflow\Tests\TestCase;
use AiWorkflow\Tools\Tool;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Integrations\Models\Integration;

class LlmClientStreamTest extends TestCase
{
    public function test_a_text_stream_yields_its_deltas_then_the_totals(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::textStream(['Bom ', 'dia!'], usage: ['cost' => 0.0002]));

        $events = $this->collect($this->weatherCall());

        $this->assertEquals([
            new StreamStart(OpenRouterFake::MODEL, 'openrouter'),
            new TextDelta('Bom '),
            new TextDelta('dia!'),
            new StreamEnd(FinishReason::Stop, new Usage(10, 5), new ResponseMeta('gen-fake', OpenRouterFake::MODEL, 'Anthropic', 0.0002)),
        ], $events);

        $body = OpenRouterFake::sentBodies()[0];
        $this->assertTrue($body['stream']);
        $this->assertSame(['include_usage' => true], $body['stream_options']);
    }

    public function test_a_streamed_tool_loop_runs_the_tools_and_sends_the_reasoning_back(): void
    {
        OpenRouterFake::respondWith(self::sse('stream-tool-calls-with-reasoning.sse'), OpenRouterFake::textStream(['Sunny, 21°C.']));

        $events = $this->collect($this->weatherCall([$this->weatherTool()]));

        $this->assertEquals([
            new StreamStart('google/gemini-3-pro', 'openrouter'),
            new ReasoningDelta('Checking the forecast'),
            new ReasoningDelta(' for Lisbon.'),
        ], array_slice($events, 0, 3));

        $toolCall = $events[3];
        $this->assertInstanceOf(ToolCallEvent::class, $toolCall);
        $this->assertSame('tool_get_weather_Zp4qR7', $toolCall->toolCall->id);
        $this->assertSame(['city' => 'Lisbon'], $toolCall->toolCall->arguments);
        $this->assertEquals(new ToolResultEvent(new ToolResult('tool_get_weather_Zp4qR7', 'get_weather', ['city' => 'Lisbon'], 'Sunny, 21°C')), $events[4]);
        $this->assertEquals(new TextDelta('Sunny, 21°C.'), $events[5]);
        $this->assertEquals(
            new StreamEnd(FinishReason::Stop, new Usage(311, 47, cacheReadTokens: 0, thoughtTokens: 18), new ResponseMeta('gen-fake', OpenRouterFake::MODEL, 'Anthropic', null)),
            $events[6],
        );

        $messages = OpenRouterFake::sentBodies()[1]['messages'];
        $this->assertIsArray($messages);
        $this->assertSame([
            'role' => 'assistant',
            'tool_calls' => [[
                'id' => 'tool_get_weather_Zp4qR7',
                'type' => 'function',
                'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Lisbon"}'],
            ]],
            'reasoning' => 'Checking the forecast for Lisbon.',
            'reasoning_details' => [
                ['type' => 'reasoning.text', 'text' => 'Checking the forecast', 'format' => 'google-gemini-v1', 'index' => 0],
                ['type' => 'reasoning.text', 'text' => ' for Lisbon.', 'format' => 'google-gemini-v1', 'index' => 0],
                ['type' => 'reasoning.encrypted', 'data' => 'CiQB0e2Kb7vX9mQ3pL1sR8tWc4YhN6uJ2kD5fG0aE', 'format' => 'google-gemini-v1', 'index' => 1],
            ],
        ], $messages[2]);
        $this->assertSame(['role' => 'tool', 'tool_call_id' => 'tool_get_weather_Zp4qR7', 'content' => 'Sunny, 21°C'], $messages[3]);
    }

    public function test_an_error_chunk_throws_after_the_events_before_it(): void
    {
        OpenRouterFake::respondWith(self::sse('stream-error.sse'));

        $events = [];

        try {
            foreach ($this->client()->stream($this->weatherCall(), $this->integration()) as $event) {
                $events[] = $event;
            }
            $this->fail('Expected UpstreamErrorException.');
        } catch (UpstreamErrorException $e) {
            $this->assertSame('The openrouter stream failed: Provider disconnected unexpectedly', $e->getMessage());
            $this->assertNull($e->errorCode);
        }

        $this->assertEquals([new StreamStart('openai/gpt-5', 'openrouter'), new TextDelta('The forecast')], $events);
    }

    public function test_an_unknown_finish_reason_ends_the_stream_with_an_exception(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::textStream(['Hi'], 'weird'));

        $this->expectException(UnexpectedFinishReasonException::class);

        $this->collect($this->weatherCall());
    }

    /**
     * @return list<StreamEvent>
     */
    private function collect(TextCall $call): array
    {
        return iterator_to_array($this->client()->stream($call, $this->integration()), false);
    }

    /**
     * @param  list<Tool>  $tools
     */
    private function weatherCall(array $tools = []): TextCall
    {
        return new TextCall('openrouter', OpenRouterFake::MODEL, 'Be brief.', [new UserMessage('Weather in Lisbon?')], $tools, 5);
    }

    private function weatherTool(): Tool
    {
        return new Tool(
            'get_weather',
            'Get the current weather for a city.',
            ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']],
            fn (array $arguments): string => 'Sunny, 21°C',
        );
    }

    private function client(): LlmClient
    {
        return $this->app->make(LlmClient::class);
    }

    private function integration(): Integration
    {
        return Integration::query()->where('provider', 'openrouter')->firstOrFail();
    }

    private static function sse(string $name): PromiseInterface
    {
        $contents = file_get_contents(__DIR__.'/../Fixtures/Http/openrouter/'.$name);
        self::assertIsString($contents);

        return Http::response($contents, 200, ['Content-Type' => 'text/event-stream']);
    }
}

<?php

declare(strict_types=1);

namespace AiWorkflow\Testing;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\ResponseSequence;
use Illuminate\Support\Facades\Http;

/**
 * Fakes OpenRouter's chat completions endpoint with Laravel's HTTP client
 * fake, and builds the responses OpenRouter sends.
 *
 * Queue every response in a single respondWith() call. The HTTP fake sends
 * each request to the first queue whose URL pattern matches it, so a queue
 * added by a second call is never used. A request made after the queue is
 * empty fails the test.
 */
final class OpenRouterFake
{
    public const MODEL = 'anthropic/claude-sonnet-5';

    private const URL = 'openrouter.ai/*';

    private const ID = 'gen-fake';

    /**
     * @param  PromiseInterface|(Closure(Request): PromiseInterface)  ...$responses
     */
    public static function respondWith(PromiseInterface|Closure ...$responses): ResponseSequence
    {
        $sequence = Http::fakeSequence(self::URL);

        foreach ($responses as $response) {
            $sequence->pushResponse($response);
        }

        return $sequence;
    }

    /**
     * @param  array<string, mixed>  $usage  Merged over the default token counts.
     * @param  array<string, mixed>  $message  Merged into the assistant message, for example to add reasoning.
     */
    public static function completion(string $content, string $finishReason = 'stop', array $usage = [], array $message = []): PromiseInterface
    {
        return self::chatCompletion(['role' => 'assistant', 'content' => $content, ...$message], $finishReason, $usage);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  array<string, mixed>  $usage
     */
    public static function structured(array $data, array $usage = []): PromiseInterface
    {
        return self::completion(json_encode($data, JSON_THROW_ON_ERROR), usage: $usage);
    }

    /**
     * @param  list<array{name: string, arguments?: array<string, mixed>, id?: string}>  $calls
     * @param  array<string, mixed>  $usage
     * @param  array<string, mixed>  $message  Merged into the assistant message, for example to add reasoning.
     */
    public static function toolCalls(array $calls, string $content = '', array $usage = [], array $message = []): PromiseInterface
    {
        return self::chatCompletion([
            'role' => 'assistant',
            'content' => $content,
            'tool_calls' => self::toolCallPayloads($calls),
            ...$message,
        ], 'tool_calls', $usage);
    }

    /**
     * An HTTP error response, with the error body that OpenRouter sends.
     *
     * @param  array<string, string>  $headers
     */
    public static function error(int $status, string $message = 'Provider returned error', array $headers = []): PromiseInterface
    {
        return Http::response(['error' => ['code' => $status, 'message' => $message]], $status, $headers);
    }

    /**
     * An HTTP 200 with an error envelope as its body.
     */
    public static function errorEnvelope(int $code, string $message = 'Provider returned error'): PromiseInterface
    {
        return Http::response(['error' => ['code' => $code, 'message' => $message]]);
    }

    /**
     * @return Closure(Request): PromiseInterface
     */
    public static function connectionFailure(): Closure
    {
        return Http::failedConnection();
    }

    /**
     * A streamed response with one server-sent event per chunk, followed by
     * the [DONE] marker.
     *
     * @param  list<array<string, mixed>>  $chunks
     */
    public static function stream(array $chunks): PromiseInterface
    {
        $body = ": OPENROUTER PROCESSING\n\n";

        foreach ($chunks as $chunk) {
            $body .= 'data: '.json_encode($chunk, JSON_THROW_ON_ERROR)."\n\n";
        }

        return Http::response($body."data: [DONE]\n\n", 200, ['Content-Type' => 'text/event-stream']);
    }

    /**
     * A streamed text completion: one chunk per delta, then the finish reason
     * and the usage.
     *
     * @param  list<string>  $deltas
     * @param  array<string, mixed>  $usage
     */
    public static function textStream(array $deltas, string $finishReason = 'stop', array $usage = []): PromiseInterface
    {
        $chunks = array_map(fn (string $delta): array => self::chunk(['role' => 'assistant', 'content' => $delta]), $deltas);

        return self::stream([...$chunks, self::chunk([], $finishReason), self::usageChunk($usage)]);
    }

    /**
     * A streamed completion that calls tools.
     *
     * @param  list<array{name: string, arguments?: array<string, mixed>, id?: string}>  $calls
     * @param  array<string, mixed>  $usage
     */
    public static function toolCallStream(array $calls, array $usage = []): PromiseInterface
    {
        $toolCalls = array_map(
            fn (array $call, int $index): array => ['index' => $index, ...$call],
            self::toolCallPayloads($calls),
            array_keys($calls),
        );

        return self::stream([
            self::chunk(['role' => 'assistant', 'content' => '', 'tool_calls' => $toolCalls]),
            self::chunk([], 'tool_calls'),
            self::usageChunk($usage),
        ]);
    }

    /**
     * One streamed chunk with the given delta.
     *
     * @param  array<string, mixed>  $delta
     * @return array<string, mixed>
     */
    public static function chunk(array $delta, ?string $finishReason = null): array
    {
        return [
            'id' => self::ID,
            'provider' => 'Anthropic',
            'model' => self::MODEL,
            'object' => 'chat.completion.chunk',
            'created' => 1_758_000_000,
            'choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finishReason]],
        ];
    }

    /**
     * A usage block in OpenRouter's format, to pass as a builder's $usage.
     *
     * @return array<string, mixed>
     */
    public static function tokens(int $input, int $output, ?int $cacheRead = null, ?int $cacheWrite = null, ?int $reasoning = null): array
    {
        $usage = ['prompt_tokens' => $input, 'completion_tokens' => $output, 'total_tokens' => $input + $output];
        $promptDetails = array_filter(['cached_tokens' => $cacheRead, 'cache_write_tokens' => $cacheWrite], fn (?int $tokens): bool => $tokens !== null);

        if ($promptDetails !== []) {
            $usage['prompt_tokens_details'] = $promptDetails;
        }

        if ($reasoning !== null) {
            $usage['completion_tokens_details'] = ['reasoning_tokens' => $reasoning];
        }

        return $usage;
    }

    /**
     * The JSON bodies of the requests sent to OpenRouter, oldest first.
     *
     * @return list<array<array-key, mixed>>
     */
    public static function sentBodies(): array
    {
        $bodies = [];

        foreach (Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'openrouter.ai')) as [$request]) {
            $bodies[] = $request->data();
        }

        return $bodies;
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  array<string, mixed>  $usage
     */
    private static function chatCompletion(array $message, string $finishReason, array $usage): PromiseInterface
    {
        return Http::response([
            'id' => self::ID,
            'provider' => 'Anthropic',
            'model' => self::MODEL,
            'object' => 'chat.completion',
            'created' => 1_758_000_000,
            'choices' => [['index' => 0, 'finish_reason' => $finishReason, 'message' => $message]],
            'usage' => self::usage($usage),
        ]);
    }

    /**
     * @param  array<string, mixed>  $usage
     * @return array<string, mixed>
     */
    private static function usageChunk(array $usage): array
    {
        return [...self::chunk([]), 'choices' => [], 'usage' => self::usage($usage)];
    }

    /**
     * @param  array<string, mixed>  $usage
     * @return array<string, mixed>
     */
    private static function usage(array $usage): array
    {
        return ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15, ...$usage];
    }

    /**
     * @param  list<array{name: string, arguments?: array<string, mixed>, id?: string}>  $calls
     * @return list<array<string, mixed>>
     */
    private static function toolCallPayloads(array $calls): array
    {
        return array_map(fn (array $call, int $index): array => [
            'id' => $call['id'] ?? 'call_'.($index + 1),
            'type' => 'function',
            'function' => [
                'name' => $call['name'],
                'arguments' => ($call['arguments'] ?? []) === [] ? '{}' : json_encode($call['arguments'], JSON_THROW_ON_ERROR),
            ],
        ], $calls, array_keys($calls));
    }
}

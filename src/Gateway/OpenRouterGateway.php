<?php

declare(strict_types=1);

namespace AiWorkflow\Gateway;

use AiWorkflow\Exceptions\UpstreamErrorException;
use Generator;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Files\Base64Video;
use Laravel\Ai\Files\RemoteVideo;
use Laravel\Ai\Gateway\OpenRouter\OpenRouterGateway as BaseOpenRouterGateway;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\FinishReason;
use LogicException;

/**
 * laravel/ai's OpenRouter gateway, changed for ai-workflow:
 *
 * - Reasoning is captured from each response and sent back on the assistant
 *   turn it came from. Tool turns with Anthropic thinking or Gemini thought
 *   signatures must include it.
 * - For an error envelope or an empty body on an HTTP 200, the gateway
 *   throws an UpstreamErrorException that includes the response body.
 * - Video is sent as video_url, and a tool's raw parameter schema is sent
 *   unchanged.
 * - The configured Guzzle options are applied to every request.
 * - The response id, upstream provider and cost, which laravel/ai discards,
 *   are kept for ResponseMeta.
 *
 * The response details stay on the instance until LlmClient reads them after
 * the step, so each call must use its own instance.
 *
 * @internal
 */
class OpenRouterGateway extends BaseOpenRouterGateway
{
    /** @var array{id: ?string, provider: ?string, cost: ?float} */
    private array $envelope = ['id' => null, 'provider' => null, 'cost' => null];

    private string $streamedReasoning = '';

    /** @var list<mixed> */
    private array $streamedReasoningDetails = [];

    /**
     * @param  array<string, mixed>  $clientOptions  Guzzle request options.
     */
    public function __construct(Dispatcher $events, private readonly array $clientOptions = [])
    {
        parent::__construct($events);
    }

    /**
     * The id, upstream provider and cost of the most recent response.
     *
     * @return array{id: ?string, provider: ?string, cost: ?float}
     */
    public function envelope(): array
    {
        return $this->envelope;
    }

    /**
     * @param  array<array-key, mixed>  $messages
     * @param  array<array-key, mixed>  $tools
     * @param  array<array-key, mixed>|null  $schema
     * @return array<array-key, mixed>
     */
    #[\Override]
    protected function buildStepBody(
        Provider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        StepContext $stepContext,
    ): array {
        $body = parent::buildStepBody($provider, $model, $instructions, $messages, $tools, $schema, $options, $stepContext);

        if (! is_array($body['messages'] ?? null)) {
            return $body;
        }

        $assistantTurns = array_values(array_filter($messages, fn (mixed $message): bool => $message instanceof AssistantMessage));
        $chatMessages = $body['messages'];
        $turn = 0;

        foreach ($chatMessages as $index => $chatMessage) {
            if (! is_array($chatMessage) || ($chatMessage['role'] ?? null) !== 'assistant') {
                continue;
            }

            $source = $assistantTurns[$turn++] ?? null;

            if ($source instanceof AssistantMessage && $source->replayBlocks !== []) {
                $chatMessages[$index] = [...$chatMessage, ...$source->replayBlocks];
            }
        }

        $body['messages'] = $chatMessages;

        return $body;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    #[\Override]
    protected function validateTextResponse(array $data): void
    {
        if ($data !== [] && ! array_key_exists('error', $data)) {
            return;
        }

        $error = is_array($data['error'] ?? null) ? $data['error'] : [];
        $code = $error['code'] ?? null;
        $message = is_string($error['message'] ?? null) ? $error['message'] : 'no error message';
        $body = json_encode($data);

        throw new UpstreamErrorException(
            $data === [] ? 'OpenRouter returned an empty response.' : "OpenRouter returned an error: {$message}",
            'openrouter',
            $body === false ? null : $body,
            match (true) {
                is_int($code) => $code,
                is_string($code) && ctype_digit($code) => (int) $code,
                default => null,
            },
        );
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    #[\Override]
    protected function parseTextResponse(array $data, Provider $provider, bool $structured): StepResponse
    {
        $response = parent::parseTextResponse($data, $provider, $structured);

        $this->envelope = ['id' => null, 'provider' => null, 'cost' => null];
        $this->captureEnvelope($data);

        $choices = is_array($data['choices'] ?? null) ? $data['choices'] : [];
        $choice = is_array($choices[0] ?? null) ? $choices[0] : [];
        $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];

        $response->replayBlocks = self::replayBlocks($message['reasoning'] ?? null, $message['reasoning_details'] ?? null);

        return $response;
    }

    /**
     * laravel/ai maps an OpenRouter "error" finish reason to Unknown.
     *
     * @param  array<array-key, mixed>  $choice
     */
    #[\Override]
    protected function extractFinishReason(array $choice): FinishReason
    {
        return ($choice['finish_reason'] ?? null) === 'error'
            ? FinishReason::Error
            : parent::extractFinishReason($choice);
    }

    /**
     * @param  iterable<mixed>  $attachments
     * @return list<array<array-key, mixed>>
     */
    #[\Override]
    protected function mapAttachments(iterable $attachments): array
    {
        $parts = [];

        foreach ($attachments as $attachment) {
            $parts[] = match (true) {
                $attachment instanceof RemoteVideo => ['type' => 'video_url', 'video_url' => ['url' => $attachment->url]],
                $attachment instanceof Base64Video => ['type' => 'video_url', 'video_url' => ['url' => 'data:'.($attachment->mimeType() ?? 'video/mp4').';base64,'.$attachment->base64]],
                default => self::firstPart(parent::mapAttachments(new Collection([$attachment]))),
            };
        }

        return $parts;
    }

    /**
     * @return array<array-key, mixed>
     */
    #[\Override]
    protected function mapTool(Tool $tool): array
    {
        if (! $tool instanceof ToolAdapter) {
            return parent::mapTool($tool);
        }

        return [
            'type' => 'function',
            'function' => [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'parameters' => $tool->parameters(),
            ],
        ];
    }

    #[\Override]
    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        return parent::client($provider, $timeout)->withOptions($this->clientOptions);
    }

    /**
     * The reasoning from the most recent stream, to send back on its
     * assistant turn. laravel/ai's stream parser discards it.
     *
     * @return array<string, mixed>
     */
    public function streamedReplayBlocks(): array
    {
        return self::replayBlocks($this->streamedReasoning, $this->streamedReasoningDetails);
    }

    /**
     * Capture the details that laravel/ai's stream parser discards from each
     * streamed chunk.
     *
     * @param  mixed  $streamBody
     * @return Generator<int, mixed>
     */
    #[\Override]
    protected function parseServerSentEvents($streamBody): Generator
    {
        $this->envelope = ['id' => null, 'provider' => null, 'cost' => null];
        $this->streamedReasoning = '';
        $this->streamedReasoningDetails = [];

        foreach (parent::parseServerSentEvents($streamBody) as $data) {
            if (is_array($data)) {
                $this->captureStreamChunk($data);
            }

            yield $data;
        }
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function captureStreamChunk(array $data): void
    {
        $this->captureEnvelope($data);

        $choices = is_array($data['choices'] ?? null) ? $data['choices'] : [];
        $choice = is_array($choices[0] ?? null) ? $choices[0] : [];
        $delta = is_array($choice['delta'] ?? null) ? $choice['delta'] : [];

        if (is_string($delta['reasoning'] ?? null)) {
            $this->streamedReasoning .= $delta['reasoning'];
        }

        if (is_array($delta['reasoning_details'] ?? null)) {
            array_push($this->streamedReasoningDetails, ...array_values($delta['reasoning_details']));
        }
    }

    /**
     * Keep each envelope field from the latest response or chunk that
     * includes it. In a stream, OpenRouter sends the cost on the final usage
     * chunk.
     *
     * @param  array<array-key, mixed>  $data
     */
    private function captureEnvelope(array $data): void
    {
        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];
        $cost = $usage['cost'] ?? null;

        $this->envelope = [
            'id' => is_string($data['id'] ?? null) ? $data['id'] : $this->envelope['id'],
            'provider' => is_string($data['provider'] ?? null) ? $data['provider'] : $this->envelope['provider'],
            'cost' => is_int($cost) || is_float($cost) ? (float) $cost : $this->envelope['cost'],
        ];
    }

    /**
     * The fields an assistant turn must include for OpenRouter to continue
     * its reasoning, in the chat completions API format.
     *
     * @return array<string, mixed>
     */
    private static function replayBlocks(mixed $reasoning, mixed $reasoningDetails): array
    {
        return array_filter([
            'reasoning' => is_string($reasoning) && $reasoning !== '' ? $reasoning : null,
            'reasoning_details' => is_array($reasoningDetails) && $reasoningDetails !== [] ? array_values($reasoningDetails) : null,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<array-key, mixed>  $parts
     * @return array<array-key, mixed>
     */
    private static function firstPart(array $parts): array
    {
        $part = array_values($parts)[0] ?? null;

        if (! is_array($part)) {
            throw new LogicException('laravel/ai returned no content part for an attachment.');
        }

        return $part;
    }
}

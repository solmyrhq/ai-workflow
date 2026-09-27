<?php

declare(strict_types=1);

namespace AiWorkflow\Gateway;

use AiWorkflow\Exceptions\UnsupportedAttachmentException;
use AiWorkflow\Messages\AssistantMessage;
use AiWorkflow\Messages\Attachment;
use AiWorkflow\Messages\AttachmentKind;
use AiWorkflow\Messages\Message;
use AiWorkflow\Messages\SystemMessage;
use AiWorkflow\Messages\ToolCall;
use AiWorkflow\Messages\ToolResultMessage;
use AiWorkflow\Messages\UserMessage;
use Illuminate\Support\Collection;
use Laravel\Ai\Files\Base64Audio;
use Laravel\Ai\Files\Base64Document;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\Base64Video;
use Laravel\Ai\Files\File;
use Laravel\Ai\Files\RemoteAudio;
use Laravel\Ai\Files\RemoteDocument;
use Laravel\Ai\Files\RemoteImage;
use Laravel\Ai\Files\RemoteVideo;
use Laravel\Ai\Messages\AssistantMessage as LaravelAssistantMessage;
use Laravel\Ai\Messages\Message as LaravelMessage;
use Laravel\Ai\Messages\ToolResultMessage as LaravelToolResultMessage;
use Laravel\Ai\Messages\UserMessage as LaravelUserMessage;
use Laravel\Ai\Responses\Data\ToolCall as LaravelToolCall;
use Laravel\Ai\Responses\Data\ToolResult as LaravelToolResult;
use LogicException;

/**
 * Converts between package messages and laravel/ai messages.
 *
 * Replay state from a provider (reasoning blocks, tool call ids and
 * signatures) is stored in providerState, tagged with the key of the
 * provider it came from, and is sent back only to that provider.
 *
 * @internal
 */
final class MessageMapper
{
    /**
     * Converts package messages to laravel/ai's instructions and message list.
     * System messages in the conversation are appended to the instructions.
     *
     * @param  list<Message>  $messages
     * @return array{?string, list<LaravelMessage>}
     */
    public static function toLaravel(string $provider, string $systemPrompt, array $messages): array
    {
        $instructions = $systemPrompt !== '' ? [$systemPrompt] : [];
        $mapped = [];
        $resultIds = [];

        foreach ($messages as $message) {
            if ($message instanceof SystemMessage) {
                $instructions[] = $message->content;
            } elseif ($message instanceof UserMessage) {
                $mapped[] = self::userMessage($message);
            } elseif ($message instanceof AssistantMessage) {
                $assistant = self::assistantMessage($provider, $message);
                foreach ($assistant->toolCalls as $toolCall) {
                    $resultIds[$toolCall->id] = $toolCall->resultId;
                }
                $mapped[] = $assistant;
            } elseif ($message instanceof ToolResultMessage) {
                $mapped[] = self::toolResultMessage($message, $resultIds);
            }
        }

        return [$instructions !== [] ? implode("\n\n", $instructions) : null, $mapped];
    }

    /**
     * @param  list<LaravelToolCall>  $toolCalls
     * @param  array<array-key, mixed>  $replayBlocks
     */
    public static function fromLaravelAssistant(string $provider, string $content, array $toolCalls, array $replayBlocks): AssistantMessage
    {
        return new AssistantMessage(
            $content,
            array_map(fn (LaravelToolCall $toolCall): ToolCall => self::fromLaravelToolCall($provider, $toolCall), $toolCalls),
            $replayBlocks !== [] ? ['provider' => $provider, 'replay_blocks' => $replayBlocks] : [],
        );
    }

    public static function fromLaravelToolCall(string $provider, LaravelToolCall $toolCall): ToolCall
    {
        $arguments = array_filter($toolCall->arguments, is_string(...), ARRAY_FILTER_USE_KEY);

        return new ToolCall($toolCall->id, $toolCall->name, $arguments, [
            'provider' => $provider,
            'result_id' => $toolCall->resultId,
            'reasoning_id' => $toolCall->reasoningId,
            'reasoning_summary' => $toolCall->reasoningSummary,
            'reasoning_encrypted_content' => $toolCall->reasoningEncryptedContent,
            'thought_signature' => $toolCall->thoughtSignature,
        ]);
    }

    private static function userMessage(UserMessage $message): LaravelUserMessage
    {
        $texts = [];
        $files = [];

        foreach ($message->attachments as $attachment) {
            if ($attachment->kind === AttachmentKind::Text) {
                $texts[] = $attachment->text ?? '';
            } else {
                $files[] = self::file($attachment);
            }
        }

        // Text attachments go first with no separator, as in 6.x, so that a
        // replayed request sends the prompt it was recorded with.
        return new LaravelUserMessage(implode('', [...$texts, $message->content]), $files);
    }

    private static function file(Attachment $attachment): File
    {
        $kind = self::kind($attachment);
        $mimeType = $attachment->mimeType;

        if ($kind === AttachmentKind::Text || $kind === AttachmentKind::Media) {
            throw new LogicException("A {$kind->value} attachment cannot be sent as a file.");
        }

        if ($attachment->url !== null) {
            $url = $attachment->url;
            $file = match ($kind) {
                AttachmentKind::Image => new RemoteImage($url, $mimeType),
                AttachmentKind::Audio => new RemoteAudio($url, $mimeType),
                AttachmentKind::Video => new RemoteVideo($url, $mimeType),
                AttachmentKind::Document => new RemoteDocument($url, $mimeType),
            };
        } elseif ($attachment->base64 !== null && $attachment->base64 !== '') {
            $base64 = $attachment->base64;
            $file = match ($kind) {
                AttachmentKind::Image => new Base64Image($base64, $mimeType),
                AttachmentKind::Audio => new Base64Audio($base64, $mimeType),
                AttachmentKind::Video => new Base64Video($base64, $mimeType),
                AttachmentKind::Document => new Base64Document($base64, $mimeType),
            };
        } else {
            throw new UnsupportedAttachmentException("A {$attachment->kind->value} attachment must have a URL or base64 content.");
        }

        return $attachment->title !== null ? $file->as($attachment->title) : $file;
    }

    /**
     * The kind to send an attachment as. A generic media attachment's kind
     * comes from its MIME type.
     */
    private static function kind(Attachment $attachment): AttachmentKind
    {
        if ($attachment->kind !== AttachmentKind::Media) {
            return $attachment->kind;
        }

        $mimeType = $attachment->mimeType ?? throw new UnsupportedAttachmentException('A media attachment must have a MIME type, so that it can be sent as an image, audio, video or document.');

        return match (true) {
            str_starts_with($mimeType, 'image/') => AttachmentKind::Image,
            str_starts_with($mimeType, 'audio/') => AttachmentKind::Audio,
            str_starts_with($mimeType, 'video/') => AttachmentKind::Video,
            default => AttachmentKind::Document,
        };
    }

    private static function assistantMessage(string $provider, AssistantMessage $message): LaravelAssistantMessage
    {
        $state = $message->providerState;
        $replayBlocks = ($state['provider'] ?? null) === $provider && is_array($state['replay_blocks'] ?? null)
            ? $state['replay_blocks']
            : [];

        return new LaravelAssistantMessage(
            $message->content,
            new Collection(array_map(fn (ToolCall $toolCall): LaravelToolCall => self::toolCall($provider, $toolCall), $message->toolCalls)),
            $replayBlocks,
        );
    }

    /**
     * A tool call without state from this provider, such as one from the
     * request log, uses its id as the result id.
     */
    private static function toolCall(string $provider, ToolCall $toolCall): LaravelToolCall
    {
        $state = ($toolCall->providerState['provider'] ?? null) === $provider ? $toolCall->providerState : [];
        $summary = $state['reasoning_summary'] ?? null;

        return new LaravelToolCall(
            $toolCall->id,
            $toolCall->name,
            $toolCall->arguments,
            self::stringOrNull($state, 'result_id') ?? $toolCall->id,
            self::stringOrNull($state, 'reasoning_id'),
            is_array($summary) ? $summary : null,
            self::stringOrNull($state, 'reasoning_encrypted_content'),
            self::stringOrNull($state, 'thought_signature'),
        );
    }

    /**
     * @param  array<string, ?string>  $resultIds  Result ids of the tool calls so far, by tool call id.
     */
    private static function toolResultMessage(ToolResultMessage $message, array $resultIds): LaravelToolResultMessage
    {
        $results = [];

        foreach ($message->toolResults as $result) {
            $results[] = new LaravelToolResult(
                $result->toolCallId,
                $result->toolName,
                $result->args,
                $result->result,
                $resultIds[$result->toolCallId] ?? $result->toolCallId,
            );
        }

        return new LaravelToolResultMessage(new Collection($results));
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private static function stringOrNull(array $state, string $key): ?string
    {
        return is_string($state[$key] ?? null) ? $state[$key] : null;
    }
}

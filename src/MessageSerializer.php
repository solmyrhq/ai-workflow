<?php

declare(strict_types=1);

namespace AiWorkflow;

use AiWorkflow\Messages\AssistantMessage;
use AiWorkflow\Messages\Attachment;
use AiWorkflow\Messages\AttachmentKind;
use AiWorkflow\Messages\Message;
use AiWorkflow\Messages\SystemMessage;
use AiWorkflow\Messages\ToolCall;
use AiWorkflow\Messages\ToolResult;
use AiWorkflow\Messages\ToolResultMessage;
use AiWorkflow\Messages\UserMessage;
use RuntimeException;

/**
 * Converts messages to and from the JSON stored on logged requests. The
 * format must stay readable for rows that 6.x logged, which
 * UpgradeCompatibilityTest checks. Provider state is not stored.
 */
class MessageSerializer
{
    /**
     * @param  array<int, Message>  $messages
     * @return list<array<string, mixed>>
     */
    public static function serialize(array $messages): array
    {
        return array_values(array_map(self::serializeMessage(...), $messages));
    }

    /**
     * @param  list<array<string, mixed>>  $data
     * @return list<Message>
     */
    public static function deserialize(array $data): array
    {
        return array_map(self::deserializeMessage(...), $data);
    }

    /**
     * @return array<string, mixed>
     */
    private static function serializeMessage(Message $message): array
    {
        return match (true) {
            $message instanceof UserMessage => self::serializeUserMessage($message),
            $message instanceof AssistantMessage => [
                'type' => 'assistant',
                'content' => $message->content,
                'tool_calls' => array_map(fn (ToolCall $toolCall): array => [
                    'id' => $toolCall->id,
                    'name' => $toolCall->name,
                    'arguments' => $toolCall->arguments,
                ], $message->toolCalls),
            ],
            $message instanceof ToolResultMessage => [
                'type' => 'tool_result',
                'tool_results' => array_map(fn (ToolResult $toolResult): array => [
                    'tool_call_id' => $toolResult->toolCallId,
                    'tool_name' => $toolResult->toolName,
                    'args' => $toolResult->args,
                    'result' => $toolResult->result,
                ], $message->toolResults),
            ],
            $message instanceof SystemMessage => ['type' => 'system', 'content' => $message->content],
            default => ['type' => 'unknown', 'class' => $message::class],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function serializeUserMessage(UserMessage $message): array
    {
        $result = ['type' => 'user', 'content' => $message->content];

        if ($message->attachments !== []) {
            $result['additional_content'] = array_map(self::serializeAttachment(...), $message->attachments);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private static function serializeAttachment(Attachment $attachment): array
    {
        if ($attachment->kind === AttachmentKind::Text) {
            return ['media_type' => 'text', 'text' => $attachment->text ?? ''];
        }

        return array_filter([
            'media_type' => $attachment->kind->value,
            'url' => $attachment->url,
            'base64' => $attachment->base64,
            'mime_type' => $attachment->mimeType,
            'document_title' => $attachment->kind === AttachmentKind::Document ? $attachment->title : null,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function deserializeMessage(array $data): Message
    {
        $type = is_string($data['type'] ?? null) ? $data['type'] : 'unknown';
        $content = is_string($data['content'] ?? null) ? $data['content'] : '';

        return match ($type) {
            'user' => new UserMessage($content, array_map(self::deserializeAttachment(...), self::list($data['additional_content'] ?? null))),
            'assistant' => new AssistantMessage($content, array_map(self::deserializeToolCall(...), self::list($data['tool_calls'] ?? null))),
            'tool_result' => new ToolResultMessage(array_map(self::deserializeToolResult(...), self::list($data['tool_results'] ?? null))),
            'system' => new SystemMessage($content),
            default => throw new RuntimeException("Unknown message type: {$type}"),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function deserializeAttachment(array $data): Attachment
    {
        $kind = AttachmentKind::tryFrom(self::string($data, 'media_type') ?? 'text') ?? AttachmentKind::Media;

        if ($kind === AttachmentKind::Text) {
            return Attachment::text(self::string($data, 'text') ?? '');
        }

        return new Attachment(
            $kind,
            url: self::string($data, 'url'),
            base64: self::string($data, 'base64'),
            mimeType: self::string($data, 'mime_type'),
            title: $kind === AttachmentKind::Document ? self::string($data, 'document_title') : null,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function deserializeToolCall(array $data): ToolCall
    {
        return new ToolCall(self::string($data, 'id') ?? '', self::string($data, 'name') ?? '', self::object($data['arguments'] ?? null));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function deserializeToolResult(array $data): ToolResult
    {
        return new ToolResult(
            self::string($data, 'tool_call_id') ?? '',
            self::string($data, 'tool_name') ?? '',
            self::object($data['args'] ?? null),
            $data['result'] ?? null,
        );
    }

    /**
     * The array rows of a stored list, each with its non-string keys dropped.
     *
     * @return list<array<string, mixed>>
     */
    private static function list(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $rows = [];

        foreach ($value as $row) {
            if (is_array($row)) {
                $rows[] = self::object($row);
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private static function object(mixed $value): array
    {
        return is_array($value) ? array_filter($value, is_string(...), ARRAY_FILTER_USE_KEY) : [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function string(array $data, string $key): ?string
    {
        return is_string($data[$key] ?? null) ? $data[$key] : null;
    }
}

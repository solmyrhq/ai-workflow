<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

use AiWorkflow\Messages\AssistantMessage;
use AiWorkflow\Messages\Attachment;
use AiWorkflow\Messages\AttachmentKind;
use AiWorkflow\Messages\Message;
use AiWorkflow\Messages\SystemMessage;
use AiWorkflow\Messages\ToolCall;
use AiWorkflow\Messages\ToolResult;
use AiWorkflow\Messages\ToolResultMessage;
use AiWorkflow\Messages\UserMessage;
use AiWorkflow\MessageSerializer;

class MessageSerializerTest extends TestCase
{
    public function test_roundtrip_image_base64(): void
    {
        $this->assertRoundTrip(new UserMessage('Describe this image', [Attachment::fromBase64(AttachmentKind::Image, 'iVBORw0KGgo=', 'image/png')]));
    }

    public function test_roundtrip_image_url(): void
    {
        $this->assertRoundTrip(new UserMessage('What is this?', [Attachment::fromUrl(AttachmentKind::Image, 'https://example.com/photo.jpg', 'image/jpeg')]));
    }

    public function test_roundtrip_document_with_title(): void
    {
        $this->assertRoundTrip(new UserMessage('Summarize this', [Attachment::fromBase64(AttachmentKind::Document, 'JVBERi0xLjQ=', 'application/pdf', 'Invoice')]));
    }

    public function test_roundtrip_audio(): void
    {
        $this->assertRoundTrip(new UserMessage('Transcribe this', [Attachment::fromBase64(AttachmentKind::Audio, 'AAAA', 'audio/mp3')]));
    }

    public function test_roundtrip_video(): void
    {
        $this->assertRoundTrip(new UserMessage('Describe this video', [Attachment::fromUrl(AttachmentKind::Video, 'https://example.com/clip.mp4', 'video/mp4')]));
    }

    public function test_roundtrip_mixed_media(): void
    {
        $this->assertRoundTrip(new UserMessage('Analyze all of this', [
            Attachment::fromBase64(AttachmentKind::Image, 'iVBORw0KGgo=', 'image/png'),
            Attachment::fromUrl(AttachmentKind::Document, 'https://example.com/doc.pdf', title: 'Report'),
            Attachment::text('Extra context here'),
        ]));
    }

    public function test_only_documents_keep_a_title(): void
    {
        $serialized = MessageSerializer::serialize([
            new UserMessage('Look', [Attachment::fromBase64(AttachmentKind::Image, 'iVBORw0KGgo=', 'image/png', 'Ignored')]),
        ]);

        $this->assertSame(
            [['type' => 'user', 'content' => 'Look', 'additional_content' => [['media_type' => 'image', 'base64' => 'iVBORw0KGgo=', 'mime_type' => 'image/png']]]],
            $serialized,
        );
    }

    public function test_backward_compat_without_additional_content(): void
    {
        // Old serialized format — no additional_content key.
        $deserialized = MessageSerializer::deserialize([['type' => 'user', 'content' => 'Hello world']]);

        $this->assertEquals([new UserMessage('Hello world')], $deserialized);
    }

    public function test_an_unknown_media_type_reads_back_as_generic_media(): void
    {
        $deserialized = MessageSerializer::deserialize([
            ['type' => 'user', 'content' => 'Hi', 'additional_content' => [['media_type' => 'hologram', 'base64' => 'AAAA', 'mime_type' => 'application/x-hologram']]],
        ]);

        $this->assertEquals(
            [new UserMessage('Hi', [new Attachment(AttachmentKind::Media, base64: 'AAAA', mimeType: 'application/x-hologram')])],
            $deserialized,
        );
    }

    public function test_text_only_message_has_no_additional_content_key(): void
    {
        $serialized = MessageSerializer::serialize([new UserMessage('Just text')]);

        $this->assertSame([['type' => 'user', 'content' => 'Just text']], $serialized);
    }

    public function test_extra_text_parts_are_preserved(): void
    {
        $serialized = MessageSerializer::serialize([new UserMessage('Main question', [Attachment::text('Additional context')])]);

        $this->assertSame([['media_type' => 'text', 'text' => 'Additional context']], $serialized[0]['additional_content']);
    }

    public function test_roundtrip_assistant_message_with_tool_calls(): void
    {
        $message = new AssistantMessage('Let me search for that', [new ToolCall('call-1', 'search', ['query' => 'test', 'limit' => 5])]);

        $this->assertSame([[
            'type' => 'assistant',
            'content' => 'Let me search for that',
            'tool_calls' => [['id' => 'call-1', 'name' => 'search', 'arguments' => ['query' => 'test', 'limit' => 5]]],
        ]], MessageSerializer::serialize([$message]));

        $this->assertRoundTrip($message);
    }

    public function test_provider_state_is_not_stored(): void
    {
        $message = new AssistantMessage('Thinking done', [new ToolCall('call-1', 'search', [], ['provider' => 'openrouter'])], ['provider' => 'openrouter', 'replay_blocks' => ['reasoning' => 'x']]);

        $this->assertEquals(
            [new AssistantMessage('Thinking done', [new ToolCall('call-1', 'search')])],
            MessageSerializer::deserialize(MessageSerializer::serialize([$message])),
        );
    }

    public function test_roundtrip_tool_result_message(): void
    {
        $message = new ToolResultMessage([
            new ToolResult('call-1', 'search', ['query' => 'test'], ['count' => 3, 'items' => ['a', 'b', 'c']]),
        ]);

        $this->assertSame([[
            'type' => 'tool_result',
            'tool_results' => [[
                'tool_call_id' => 'call-1',
                'tool_name' => 'search',
                'args' => ['query' => 'test'],
                'result' => ['count' => 3, 'items' => ['a', 'b', 'c']],
            ]],
        ]], MessageSerializer::serialize([$message]));

        $this->assertRoundTrip($message);
    }

    public function test_roundtrip_system_message(): void
    {
        $message = new SystemMessage('You are a helpful assistant.');

        $this->assertSame([['type' => 'system', 'content' => 'You are a helpful assistant.']], MessageSerializer::serialize([$message]));
        $this->assertRoundTrip($message);
    }

    public function test_unknown_message_type_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown message type: bogus');

        MessageSerializer::deserialize([['type' => 'bogus', 'content' => 'Hello']]);
    }

    public function test_roundtrip_mixed_conversation(): void
    {
        $this->assertRoundTrip(
            new SystemMessage('You are helpful.'),
            new UserMessage('Search for cats'),
            new AssistantMessage('I will search', [new ToolCall('tc-1', 'search', ['q' => 'cats'])]),
            new ToolResultMessage([new ToolResult('tc-1', 'search', ['q' => 'cats'], 'Found 5 cats')]),
            new AssistantMessage('I found 5 cats!'),
        );
    }

    private function assertRoundTrip(Message ...$messages): void
    {
        $this->assertEquals(array_values($messages), MessageSerializer::deserialize(MessageSerializer::serialize($messages)));
    }
}

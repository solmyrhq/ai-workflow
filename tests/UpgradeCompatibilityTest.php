<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

use AiWorkflow\AiWorkflowCache;
use AiWorkflow\Messages\AssistantMessage;
use AiWorkflow\Messages\Attachment;
use AiWorkflow\Messages\AttachmentKind;
use AiWorkflow\Messages\SystemMessage;
use AiWorkflow\Messages\ToolCall;
use AiWorkflow\Messages\ToolResult;
use AiWorkflow\Messages\ToolResultMessage;
use AiWorkflow\Messages\UserMessage;
use AiWorkflow\MessageSerializer;
use AiWorkflow\SchemaBuilder;
use AiWorkflow\Tests\Fixtures\Data\SentimentData;

class UpgradeCompatibilityTest extends TestCase
{
    public function test_schema_builder_output_matches_the_golden_fixture(): void
    {
        foreach ($this->golden('schema-builder.json') as $class => $expected) {
            $schema = SchemaBuilder::fromDataClass('AiWorkflow\\Tests\\Fixtures\\Data\\'.$class);

            $this->assertSame($expected, ['name' => $schema->name(), 'schema' => $schema->toArray()], $class);
        }
    }

    public function test_serializing_a_conversation_matches_the_golden_fixture(): void
    {
        $messages = [
            new SystemMessage('You are a careful assistant.'),
            new UserMessage('Plain question'),
            new UserMessage('Look at these', [
                Attachment::fromBase64(AttachmentKind::Image, 'iVBORw0KGgo=', 'image/png'),
                Attachment::fromBase64(AttachmentKind::Document, 'JVBERi0xLjQ=', 'application/pdf', 'Invoice'),
                Attachment::fromBase64(AttachmentKind::Audio, 'SUQzBAA=', 'audio/mpeg'),
                Attachment::fromBase64(AttachmentKind::Video, 'AAAAIGZ0eXA=', 'video/mp4'),
                Attachment::fromBase64(AttachmentKind::Media, 'AAAA', 'application/octet-stream'),
                Attachment::text('Extra context'),
            ]),
            new AssistantMessage('Let me check.', [
                new ToolCall('call_1', 'lookup_order', ['id' => 'A-1', 'verbose' => true]),
                new ToolCall('call_2', 'weather'),
            ]),
            new ToolResultMessage([
                new ToolResult(toolCallId: 'call_1', toolName: 'lookup_order', args: ['id' => 'A-1', 'verbose' => true], result: 'Shipped'),
                new ToolResult(toolCallId: 'call_2', toolName: 'weather', args: [], result: ['temp' => 21.5, 'unit' => 'C']),
            ]),
            new AssistantMessage('Order A-1 shipped and it is 21.5C.'),
        ];

        $this->assertSame($this->golden('messages.json'), MessageSerializer::serialize($messages));
    }

    public function test_stored_messages_survive_a_round_trip_unchanged(): void
    {
        foreach (['messages.json', 'messages-url-media.json'] as $file) {
            $stored = $this->golden($file);

            $this->assertSame($stored, MessageSerializer::serialize(MessageSerializer::deserialize($stored)), $file);
        }
    }

    public function test_cache_keys_match_the_golden_fixture(): void
    {
        $cache = new AiWorkflowCache;
        $messages = MessageSerializer::deserialize($this->golden('messages.json'));

        $this->assertSame($this->golden('cache-keys.json'), [
            'text' => $cache->generateKey('openrouter', 'anthropic/claude-sonnet-5', 'You are helpful.', $messages),
            'structured' => $cache->generateKey('openrouter', 'anthropic/claude-sonnet-5', 'You are helpful.', $messages, SchemaBuilder::fromDataClass(SentimentData::class)),
        ]);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function golden(string $file): array
    {
        $decoded = json_decode((string) file_get_contents(__DIR__.'/Fixtures/Golden/'.$file), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}

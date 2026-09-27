<?php

declare(strict_types=1);

namespace AiWorkflow\Tests\Gateway;

use AiWorkflow\Exceptions\UnsupportedAttachmentException;
use AiWorkflow\Gateway\MessageMapper;
use AiWorkflow\Messages\AssistantMessage;
use AiWorkflow\Messages\Attachment;
use AiWorkflow\Messages\AttachmentKind;
use AiWorkflow\Messages\ToolCall;
use AiWorkflow\Messages\ToolResult;
use AiWorkflow\Messages\ToolResultMessage;
use AiWorkflow\Messages\UserMessage;
use AiWorkflow\Tests\TestCase;
use Laravel\Ai\Messages\AssistantMessage as LaravelAssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage as LaravelToolResultMessage;
use Laravel\Ai\Responses\Data\ToolCall as LaravelToolCall;

class MessageMapperTest extends TestCase
{
    public function test_replay_state_goes_back_only_to_the_provider_that_produced_it(): void
    {
        $assistant = MessageMapper::fromLaravelAssistant(
            'openai',
            '',
            [new LaravelToolCall('fc_1', 'lookup', ['id' => 7], 'call_1', 'rs_1')],
            [['type' => 'reasoning', 'id' => 'rs_1']],
        );
        $conversation = [
            $assistant,
            new ToolResultMessage([new ToolResult('fc_1', 'lookup', ['id' => 7], 'found')]),
        ];

        [, $sameProvider] = MessageMapper::toLaravel('openai', '', $conversation);
        [, $otherProvider] = MessageMapper::toLaravel('anthropic', '', $conversation);

        $this->assertInstanceOf(LaravelAssistantMessage::class, $sameProvider[0]);
        $this->assertSame([['type' => 'reasoning', 'id' => 'rs_1']], $sameProvider[0]->replayBlocks);
        $this->assertEquals(new LaravelToolCall('fc_1', 'lookup', ['id' => 7], 'call_1', 'rs_1'), $sameProvider[0]->toolCalls->first());
        $this->assertInstanceOf(LaravelToolResultMessage::class, $sameProvider[1]);
        $this->assertSame('call_1', $sameProvider[1]->toolResults->first()?->resultId);

        $this->assertInstanceOf(LaravelAssistantMessage::class, $otherProvider[0]);
        $this->assertSame([], $otherProvider[0]->replayBlocks);
        $this->assertEquals(new LaravelToolCall('fc_1', 'lookup', ['id' => 7], 'fc_1'), $otherProvider[0]->toolCalls->first());
        $this->assertInstanceOf(LaravelToolResultMessage::class, $otherProvider[1]);
        $this->assertSame('fc_1', $otherProvider[1]->toolResults->first()?->resultId);
    }

    public function test_a_tool_call_read_back_from_the_log_uses_its_id_as_the_result_id(): void
    {
        [, $messages] = MessageMapper::toLaravel('openrouter', '', [new AssistantMessage('', [new ToolCall('call_9', 'lookup', ['id' => 9])])]);

        $this->assertInstanceOf(LaravelAssistantMessage::class, $messages[0]);
        $this->assertSame('call_9', $messages[0]->toolCalls->first()?->resultId);
    }

    public function test_a_media_attachment_without_a_mime_type_cannot_be_sent(): void
    {
        $this->expectException(UnsupportedAttachmentException::class);

        MessageMapper::toLaravel('openrouter', '', [new UserMessage('Look.', [new Attachment(AttachmentKind::Media, base64: 'AAAA')])]);
    }

    public function test_an_attachment_without_content_cannot_be_sent(): void
    {
        $this->expectException(UnsupportedAttachmentException::class);

        MessageMapper::toLaravel('openrouter', '', [new UserMessage('Look.', [new Attachment(AttachmentKind::Image, mimeType: 'image/png')])]);
    }
}

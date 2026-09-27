<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

use AiWorkflow\AiService;
use AiWorkflow\Enums\FinishReason;
use AiWorkflow\Events\AiWorkflowRequestCompleted;
use AiWorkflow\Events\AiWorkflowRequestFailed;
use AiWorkflow\Messages\UserMessage;
use AiWorkflow\Testing\OpenRouterFake;
use AiWorkflow\Tests\Concerns\MakesTestFixtures;
use Illuminate\Support\Facades\Event;

class AiServiceEventsTest extends TestCase
{
    use MakesTestFixtures;

    public function test_completed_event_dispatched_on_success(): void
    {
        Event::fake([AiWorkflowRequestCompleted::class]);
        OpenRouterFake::respondWith(OpenRouterFake::completion('Hello'));

        app(AiService::class)->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt());

        Event::assertDispatched(AiWorkflowRequestCompleted::class, function (AiWorkflowRequestCompleted $event): bool {
            return $event->method === 'sendMessages'
                && $event->model === 'test-model'
                && $event->finishReason === FinishReason::Stop
                && $event->usage->inputTokens === 10
                && $event->durationMs > 0
                && $event->prompt->id === 'test';
        });
    }

    public function test_failed_event_dispatched_on_failure(): void
    {
        Event::fake([AiWorkflowRequestFailed::class]);
        OpenRouterFake::respondWith(...array_fill(0, 3, OpenRouterFake::completion('Bad', 'weird')));

        try {
            app(AiService::class)->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt());
        } catch (\Throwable) {
            // Expected.
        }

        Event::assertDispatched(AiWorkflowRequestFailed::class, function (AiWorkflowRequestFailed $event): bool {
            return $event->method === 'sendMessages'
                && $event->model === 'test-model'
                && $event->prompt->id === 'test';
        });
    }

    public function test_events_dispatched_even_when_logging_disabled(): void
    {
        config()->set('ai-workflow.logging.enabled', false);

        Event::fake([AiWorkflowRequestCompleted::class]);
        OpenRouterFake::respondWith(OpenRouterFake::completion('Hello'));

        app(AiService::class)->sendMessages(collect([new UserMessage('Hello')]), $this->makePrompt());

        Event::assertDispatched(AiWorkflowRequestCompleted::class);
    }

    public function test_completed_event_dispatched_on_stream_end(): void
    {
        Event::fake([AiWorkflowRequestCompleted::class]);
        OpenRouterFake::respondWith(OpenRouterFake::textStream(['Streamed']));

        // Must consume the generator for events to fire.
        foreach (app(AiService::class)->streamMessages(collect([new UserMessage('Hello')]), $this->makePrompt()) as $event) {
            // Consume.
        }

        Event::assertDispatched(AiWorkflowRequestCompleted::class, function (AiWorkflowRequestCompleted $event): bool {
            return $event->method === 'streamMessages'
                && $event->model === 'test-model'
                && $event->finishReason === FinishReason::Stop;
        });
    }
}

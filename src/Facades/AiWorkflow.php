<?php

declare(strict_types=1);

namespace AiWorkflow\Facades;

use AiWorkflow\AiService;
use AiWorkflow\Messages\Message;
use AiWorkflow\Middleware\AiWorkflowMiddleware;
use AiWorkflow\Models\AiWorkflowExecution;
use AiWorkflow\Responses\StructuredResponse;
use AiWorkflow\Responses\TextResponse;
use AiWorkflow\Schema\ResponseSchema;
use AiWorkflow\Streaming\StreamEvent;
use AiWorkflow\StructuredDataResult;
use AiWorkflow\Tools\Tool;
use Closure;
use Generator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use Override;

/**
 * @method static TextResponse sendMessages(Collection<int, Message> $messages, \AiWorkflow\PromptData $prompt, ?\AiWorkflow\PromptData $extraContext = null, ?int $steps = null)
 * @method static StructuredResponse sendStructuredMessages(Collection<int, Message> $messages, \AiWorkflow\PromptData $prompt, ResponseSchema $schema, ?string $modelOverride = null)
 * @method static StructuredResponse sendStructuredMessagesWithTools(Collection<int, Message> $messages, \AiWorkflow\PromptData $prompt, ResponseSchema $schema)
 * @method static Generator<int, StreamEvent, mixed, void> streamMessages(Collection<int, Message> $messages, \AiWorkflow\PromptData $prompt, ?\AiWorkflow\PromptData $extraContext = null, ?int $steps = null)
 * @method static void setContext(array<string, mixed> $context)
 * @method static array<string, mixed> getContext()
 * @method static void setTags(list<string> $tags)
 * @method static list<string> getTags()
 * @method static StructuredDataResult sendStructuredData(Collection<int, Message> $messages, \AiWorkflow\PromptData $prompt, class-string<\Spatie\LaravelData\Data> $dataClass, int $maxAttempts = 3)
 * @method static void addMiddleware(AiWorkflowMiddleware $middleware)
 * @method static void clearMiddleware()
 * @method static void resolveToolsUsing(Closure $resolver)
 * @method static list<Tool> getTools()
 * @method static void startExecution(string $name, array<string, mixed> $metadata = [])
 * @method static AiWorkflowExecution|null currentExecution()
 * @method static AiWorkflowExecution|null endExecution()
 * @method static void flush()
 *
 * @see AiService
 */
class AiWorkflow extends Facade
{
    #[Override]
    protected static function getFacadeAccessor(): string
    {
        return AiService::class;
    }
}

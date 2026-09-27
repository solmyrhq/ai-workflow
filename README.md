# AI Workflow

AI Workflow is a Laravel package for running AI workflows in production. It is built on the [Laravel AI SDK](https://github.com/laravel/ai) and sends OpenRouter calls through [laravel-integrations](https://github.com/pocketarc/laravel-integrations), which handles circuit breaking, retries, failure classification, and rate limiting. On top of that, the package has fallback models, finish reason monitoring, YAML-based prompt management with Mustache templating, request logging with tagging, a middleware pipeline, caching, multimodal support, an eval framework, and Laravel Data integration.

AI Workflow requires PHP 8.3+ and Laravel 12.62+ or 13.15+.

## Installation

```bash
composer require pocketarc/ai-workflow
```

Publish the config file:

```bash
php artisan vendor:publish --tag=ai-workflow-config
```

OpenRouter calls are routed through `pocketarc/laravel-integrations` (installed automatically as a dependency), which owns the circuit breaker, retries, and rate limiting. Publish and run its migrations, then create an OpenRouter integration:

```bash
php artisan vendor:publish --tag=integrations-migrations
php artisan migrate
php artisan integrations:install openrouter --credential=api_key=YOUR_OPENROUTER_KEY
```

The integration row holds the OpenRouter API key, and the package uses that key for every OpenRouter request, including replays. The row also stores the circuit-breaker and rate-limit state. The package reads the OpenRouter URL and extra headers from `config('ai.providers.openrouter')`. To change them, publish the Laravel AI SDK's config with `php artisan vendor:publish --tag=ai-config`.

A database is therefore required for OpenRouter requests, because it stores the integration row and its transport audit. Calls to providers without a registered integration (for example `anthropic:...`) are sent directly through the Laravel AI SDK and do not use a database. The SDK reads the API keys for those providers from `config/ai.php`.

Request logging and the eval framework add their own tables. Publish and run ai-workflow's migrations to enable them:

```bash
php artisan vendor:publish --tag=ai-workflow-migrations
php artisan migrate
```

## Prompt Files

Prompts live as Markdown files with YAML front-matter in your configured `prompts_path` (default: `resources/prompts/`).

```markdown
---
model: openrouter:google/gemini-3-pro-preview
fallback_model: openrouter:openai/gpt-5.2
tags: [classification, intent]
cache_ttl: 3600
reasoning: high
---

You are a helpful assistant that answers questions concisely.
```

Front-matter fields:
- `model` (required): Model identifier in `provider:model` format (e.g. `openrouter:google/gemini-3-pro-preview`, `anthropic:claude-opus-4.5`).
- `fallback_model` (optional): If structured decoding fails, retry with this model. Same `provider:model` format.
- `tags` (optional): Array of string tags stored with each request for filtering.
- `cache_ttl` (optional): Cache responses for this many seconds. Omit to disable caching.
- `reasoning` (optional): Enable extended thinking / reasoning. Accepts an effort level string (`xhigh`, `high`, `medium`, `low`, `minimal`, `none`) or an integer token budget (e.g. `8000`). The package translates it into each provider's request fields: OpenRouter and OpenAI `reasoning.effort` or `reasoning.max_tokens`, Anthropic `thinking.budget_tokens`, Gemini `thinking_level`, Ollama `think`, and xAI `reasoning_effort`. Gemini and xAI have no token budget field, so the package throws an exception if a Gemini or xAI prompt sets an integer. Thinking cannot be turned off on Gemini, so the package sends `minimal` for `none`.
- `max_tokens` (optional): Override the global max output tokens for this prompt. Defaults to `16384` for text and `32768` for structured responses (configurable in `ai-workflow.max_tokens`).

The prompt's `id` is derived from the filename. A file at `resources/prompts/my_prompt.md` is `my_prompt`.

### Mustache Templating

Prompts support Mustache variables and conditionals:

```markdown
---
model: openrouter:anthropic/claude-4-opus
---

You are helping {{ customer_name }} with their {{ product }} subscription.
{{#is_vip}}
This is a VIP customer. Provide priority support.
{{/is_vip}}
```

```php
use AiWorkflow\Facades\Prompt;

$prompt = Prompt::load('support', [
    'customer_name' => 'Jane',
    'product' => 'Pro',
    'is_vip' => true,
]);
```

Load prompts without variables as before:

```php
$prompt = Prompt::load('my_prompt');
```

## Usage

### Text Responses

Send messages and get a text response with tool-calling support:

```php
use AiWorkflow\AiService;
use AiWorkflow\Facades\Prompt;
use AiWorkflow\Messages\UserMessage;

$aiService = app(AiService::class);

$response = $aiService->sendMessages(
    collect([new UserMessage('What is the weather like?')]),
    Prompt::load('chat'),
);

echo $response->text;
```

The response also has `finishReason`, `usage` (input, output, cache and thought tokens, summed over every step of the tool loop), `meta` (the response id, model and, for OpenRouter, the upstream provider and cost), `toolCalls`, `toolResults`, and `messages`. `messages` holds the assistant and tool-result messages from this call, which you can append to your conversation history.

Build conversations from `UserMessage`, `AssistantMessage`, `ToolResultMessage` and `SystemMessage` in `AiWorkflow\Messages`. A system message partway through a conversation is appended to the system prompt, because the Laravel AI SDK takes the instructions as one string, separate from the messages.

### Attachments

Attach images, documents, audio and video to a user message:

```php
use AiWorkflow\Messages\Attachment;
use AiWorkflow\Messages\AttachmentKind;
use AiWorkflow\Messages\UserMessage;

new UserMessage('What does this invoice say?', [
    Attachment::fromPath(AttachmentKind::Document, storage_path('invoice.pdf'), title: 'Invoice'),
    Attachment::fromUrl(AttachmentKind::Image, 'https://example.com/receipt.jpg'),
    Attachment::fromBase64(AttachmentKind::Audio, $base64, 'audio/mpeg'),
    Attachment::text('Extra context, placed before the message text.'),
]);
```

An `AttachmentKind::Media` attachment is sent as an image, audio, video or document according to its MIME type. Only OpenRouter and Gemini support video attachments.

### Structured Responses

Get structured JSON output matching a schema:

```php
use AiWorkflow\Schema\ResponseSchema;

$schema = new ResponseSchema('analysis', [
    'type' => 'object',
    'properties' => [
        'summary' => ['type' => 'string', 'description' => 'A brief summary'],
        'priority' => ['type' => 'number', 'description' => 'Priority from 1-5'],
    ],
    'required' => ['summary', 'priority'],
    'additionalProperties' => false,
]);

$response = $aiService->sendStructuredMessages(
    collect([new UserMessage('Analyze this ticket...')]),
    Prompt::load('analyze_ticket'),
    $schema,
);

$data = $response->structured;
// ['summary' => '...', 'priority' => 3]
```

A `ResponseSchema` is a JSON Schema object. The package sends it to OpenRouter unchanged, in strict mode. For other providers, the package converts it to the Laravel AI SDK's own structured output, which is not strict and supports only part of JSON Schema. If the schema uses a feature that the SDK does not support, the package throws `UnsupportedSchemaException`.

### Structured Responses with Laravel Data

If you have `spatie/laravel-data` installed, you can use Data classes directly. The package generates the schema from the class, validates the response, and retries with feedback on validation failure:

```php
use AiWorkflow\Attributes\Description;
use Spatie\LaravelData\Data;

class SentimentAnalysis extends Data
{
    public function __construct(
        #[Description('The detected sentiment: positive, negative, or neutral')]
        public readonly string $sentiment,
        #[Description('Confidence score from 0.0 to 1.0')]
        public readonly float $confidence,
    ) {}
}

$result = $aiService->sendStructuredData(
    collect([new UserMessage('Analyze the sentiment of: "I love this product!"')]),
    Prompt::load('sentiment'),
    SentimentAnalysis::class,
);

// $result->data is a validated SentimentAnalysis instance
echo $result->data->sentiment;   // "positive"
echo $result->data->confidence;  // 0.95

$result->response;  // the structured response from the attempt that passed
$result->usage;     // token usage summed across every attempt
```

On validation failure, `sendStructuredData()` appends the error to the conversation and sends the request again. It makes at most `$maxAttempts` attempts (default 3). If no attempt passes validation, it throws `StructuredValidationException`. If an attempt fails with an exception that extends `AiWorkflowException`, such as `GuardrailViolationException`, `sendStructuredData()` rethrows the same exception instance. If an attempt throws any other exception, such as a provider error, `sendStructuredData()` throws `StructuredDataRequestException`, and `getPrevious()` returns the original exception.

Every exception that extends `AiWorkflowException` has a `usage()` method. It returns the token usage of the responses that were received before the exception was thrown, including a response that an `OutputGuardrail` rejected. For an exception from `sendStructuredData()`, the usage is summed across every attempt, including responses rejected for their finish reason. No tokens are counted for an attempt that fails at the provider. Outside `sendStructuredData()`, `usage()` returns `null` if no response was received, for example when an `InputGuardrail` blocks a `sendMessages()` call.

### Streaming

Stream text responses as a generator of events:

```php
$stream = $aiService->streamMessages(
    collect([new UserMessage('Tell me a story')]),
    Prompt::load('chat'),
);

foreach ($stream as $event) {
    if ($event instanceof \AiWorkflow\Streaming\TextDelta) {
        echo $event->delta;
    }
}
```

The events in `AiWorkflow\Streaming` are `StreamStart`, `TextDelta`, `ReasoningDelta`, `ToolCallEvent`, `ToolResultEvent`, and a final `StreamEnd` with the finish reason, the usage and the response meta. If the provider sends an error during the stream, the generator throws it as an exception. There is no error event.

Streaming does not support automatic retries (this is inherent to how streaming APIs work).

### Extra Context

Pass a second prompt as shared context that gets prepended to the system prompt:

```php
$response = $aiService->sendMessages(
    $messages,
    Prompt::load('respond_to_customer'),
    extraContext: Prompt::load('shared_context'),
);
```

## Tool Registration

Register tools that the AI can call during text conversations:

```php
// In your AppServiceProvider::boot()
use AiWorkflow\AiService;
use AiWorkflow\Tools\Tool;

$aiService = app(AiService::class);

$aiService->resolveToolsUsing(fn (array $context) => [
    new Tool(
        'get_weather',
        'Get current weather conditions.',
        [
            'type' => 'object',
            'properties' => ['city' => ['type' => 'string', 'description' => 'The city name']],
            'required' => ['city'],
        ],
        fn (array $arguments): string => "Weather in {$arguments['city']}: sunny, 20°C",
    ),
]);
```

The parameters are a JSON Schema object, and the handler receives the model's arguments as one array. A handler can return a string or any value that `json_encode()` can encode. If the handler throws, the package passes the exception to `report()` and sends the exception message to the model as the tool's result. If the model calls a tool that was not provided, the package throws `UnknownToolException`.

The package sends each step of the tool loop as a separate request. If a step fails, the package retries only that step, and the tools of earlier steps do not run again. The package ends the loop after `ai-workflow.max_steps` requests, or after `$steps` requests if you pass `$steps`.

Set context before making calls to pass runtime data to your tools:

```php
$aiService->setContext(['customer' => $customer]);
$response = $aiService->sendMessages($messages, $prompt);
```

## Request Tagging

Tags help you categorize and filter logged requests. Set them in prompt front-matter and/or at runtime:

```yaml
---
model: openrouter:anthropic/claude-4
tags: [classification, intent]
---
```

```php
$aiService->setTags(['billing', 'priority']);
```

Tags from both sources are merged and deduplicated. Query with custom builder scopes:

```php
use AiWorkflow\Models\AiWorkflowRequest;

AiWorkflowRequest::query()->withTag('classification')->get();
AiWorkflowRequest::query()->withAnyTag(['classification', 'intent'])->get();
AiWorkflowRequest::query()->byModel('claude-4')->successful()->get();
AiWorkflowRequest::query()->errors()->get();
```

## Request Logging

When enabled, every AI call is recorded to the database with enough detail to replay it: system prompt, messages, model, provider, schema, response, token usage, duration, and tags. A failed call is recorded with its error, and with the HTTP status and response body when the provider sent one. If a middleware, such as an output guardrail, rejected the response, the row also includes that response and its token usage. If the response was rejected for its finish reason, the row includes its body and the tokens of every rejected attempt.

Enable logging in your `.env`:

```
AI_WORKFLOW_LOGGING=true
```

### Pruning

Logged requests carry full replay payloads, so the table grows quickly. Schedule `ai-workflow:prune` to delete requests older than the retention window, along with executions that are past the window, empty, and in no dataset:

```php
Schedule::command('ai-workflow:prune')->daily();
```

Requests the eval framework references are kept regardless of age: annotated requests, scored requests, and requests whose execution belongs to an eval dataset. Deleting them would cascade away labels and scores, or break dataset replays.

Retention is configured in `config/ai-workflow.php`:

```php
'pruning' => [
    'requests_days' => 90,
    'chunk_size' => 1000,
],
```

### Execution Grouping

Group related AI calls under a named execution:

```php
$aiService->startExecution('work_ticket', ['ticket_id' => $ticket->id]);

$aiService->sendMessages($messages, Prompt::load('decide_action'));
$aiService->sendMessages($messages, Prompt::load('generate_response'));
$aiService->sendStructuredMessages($messages, Prompt::load('judge_response'), $schema);

$execution = $aiService->endExecution();
// All three calls are linked to this execution.
```

Query executions and get aggregate token usage:

```php
use AiWorkflow\Models\AiWorkflowExecution;

$execution = AiWorkflowExecution::query()->byName('work_ticket')->recent()->first();
$execution->totalInputTokens();
$execution->totalOutputTokens();
$execution->totalTokens();
$execution->totalDurationMs();
$execution->requestCount();
```

### Events

Two events are dispatched after every AI call, regardless of whether logging is enabled:

- `AiWorkflowRequestCompleted` — prompt, method, model, finish reason, usage, duration, execution ID.
- `AiWorkflowRequestFailed` — prompt, method, model, exception, duration, execution ID.

### Sentry Integration

A ready-to-use listener adds Sentry breadcrumbs for AI requests. Register in your `EventServiceProvider`:

```php
use AiWorkflow\Events\AiWorkflowRequestCompleted;
use AiWorkflow\Events\AiWorkflowRequestFailed;
use AiWorkflow\Listeners\SentryBreadcrumbListener;

protected $listen = [
    AiWorkflowRequestCompleted::class => [SentryBreadcrumbListener::class . '@handleCompleted'],
    AiWorkflowRequestFailed::class => [SentryBreadcrumbListener::class . '@handleFailed'],
];
```

No hard dependency on Sentry — the listener is a no-op if Sentry is not installed.

## Caching

Responses can be cached per-prompt using a content-addressable key derived from the request parameters. Set `cache_ttl` in the prompt front-matter:

```yaml
---
model: openrouter:anthropic/claude-4
cache_ttl: 3600
---
```

Enable caching globally in your `.env`:

```
AI_WORKFLOW_CACHE=true
AI_WORKFLOW_CACHE_STORE=redis  # optional, defaults to your app's default cache store
```

On a cache hit, no API call is made and no log record is created. `sendStructuredData()` caches a response only after it passes validation, and the result's `usage` includes no tokens for an attempt served from the cache. If a cache write fails, `AiService` passes the exception to Laravel's `report()` and still returns the response.

## Middleware

Add before/after hooks to all AI requests using a middleware pipeline.

### Global Middleware

Register middleware in your config:

```php
// config/ai-workflow.php
'middleware' => [
    App\Middleware\LogRequestMetrics::class,
],
```

### Instance Middleware

Add middleware per-instance:

```php
$aiService->addMiddleware(new App\Middleware\LogRequestMetrics());
```

### Writing Middleware

Implement `AiWorkflowMiddleware`:

```php
use AiWorkflow\Middleware\AiWorkflowContext;
use AiWorkflow\Middleware\AiWorkflowMiddleware;

class LogRequestMetrics implements AiWorkflowMiddleware
{
    public function handle(AiWorkflowContext $context, Closure $next): AiWorkflowContext
    {
        // Before the AI request
        $start = microtime(true);

        $context = $next($context);

        // After the AI request
        logger()->info('AI request took ' . (microtime(true) - $start) . 's');

        return $context;
    }
}
```

### Guardrails

Abstract base classes for input and output validation:

```php
use AiWorkflow\Middleware\InputGuardrail;
use AiWorkflow\Middleware\AiWorkflowContext;

class PiiDetectionGuardrail extends InputGuardrail
{
    protected function validate(AiWorkflowContext $context): void
    {
        // Throw GuardrailViolationException if PII is detected in messages
    }
}
```

`InputGuardrail` validates before the request; `OutputGuardrail` validates after. Both throw `GuardrailViolationException` on failure.

## Replay Engine

The replay engine lets you re-run recorded AI requests with different models or updated prompts. This is the foundation for evals.

```php
use AiWorkflow\AiWorkflowReplayer;

$replayer = app(AiWorkflowReplayer::class);

// Replay exactly as recorded
$result = $replayer->replay($request);

// Replay with a different model
$result = $replayer->replay($request, model: 'anthropic:claude-4');

// Replay with the latest prompt from disk (uses the stored prompt_id to load)
$result = $replayer->replay($request, useCurrentPrompts: true);

// Both: latest prompt + different model
$result = $replayer->replay($request, useCurrentPrompts: true, model: 'anthropic:claude-4');

// Compare one request across multiple models
$results = $replayer->replayAcrossModels($request, [
    'openrouter:google/gemini-3-pro',
    'anthropic:claude-4',
    'openrouter:openai/gpt-5.2',
]);
// Returns array keyed by model name.

// Replay an entire execution — each request loads its own prompt via prompt_id
$results = $replayer->replayExecution($execution, useCurrentPrompts: true);
```

Structured requests are replayed with the schema and schema name they were sent with. MySQL stores the keys of a JSON object in sorted order, so on MySQL the replayer restores each object's property order from its `required` list. This works when `required` lists every property in the same order as `properties`, as the schemas generated from Laravel Data classes do.

The replayer sends requests directly, not through the integration executor, because the eval runner retries replays with its own setting (`ai-workflow.eval.replay_tries`). OpenRouter replays still use the API key from the integration row.

## Eval Framework

Evaluate AI outputs by replaying recorded requests from curated datasets across models with pluggable judges.

The workflow: run an AI action, verify the response is correct, add the execution to a named dataset, then eval that dataset against different models to see which ones produce equivalent results.

### Building a Dataset

Datasets are collections of known-good executions. Use execution grouping to track AI calls, then add verified executions to a dataset:

```php
// In your action, group AI calls under an execution:
$aiService->startExecution('decide_action #42', ['ticket_id' => 42]);
$response = $aiService->sendStructuredMessages($messages, $prompt, $schema);
$execution = $aiService->endExecution();
// $execution->id is the UUID you'll reference
```

```bash
# After verifying the response was correct, add it to a dataset:
php artisan eval:add decide-actions abc-123-uuid

# List all datasets
php artisan eval:list

# Show executions in a dataset
php artisan eval:show decide-actions

# Remove an execution from a dataset
php artisan eval:remove decide-actions abc-123-uuid
```

### Running Evals

```bash
php artisan eval:run decide-actions \
    --models=openrouter:google/gemini-3-pro,openrouter:openai/gpt-5.2 \
    --judge=App\\Eval\\MyJudge
```

This replays every request in the dataset against each model, judges the results, and displays a per-model score table.

### Writing a Judge

Implement `AiWorkflowEvalJudge`:

```php
use AiWorkflow\Eval\AiWorkflowEvalJudge;
use AiWorkflow\Eval\AiWorkflowEvalResult;
use AiWorkflow\Models\AiWorkflowRequest;
use AiWorkflow\Responses\StructuredResponse;
use AiWorkflow\Responses\TextResponse;

class MyJudge implements AiWorkflowEvalJudge
{
    public function judge(AiWorkflowRequest $originalRequest, TextResponse|StructuredResponse $response): AiWorkflowEvalResult
    {
        // Compare the new response against the original recorded response
        // Return a score from 0.0 to 1.0
        return new AiWorkflowEvalResult(score: 0.9, details: ['reasoning' => '...']);
    }
}
```

The package includes `AiJudge` — an AI-powered judge that semantically compares original and new responses (e.g. `{"payer": "John"}` vs `{"payer": "john"}` scores high). For domain-specific evaluation, implement your own judge with custom scoring logic.

### Running Evals in Code

```php
use AiWorkflow\Eval\AiWorkflowEvalRunner;
use AiWorkflow\Models\AiWorkflowEvalDataset;

$runner = app(AiWorkflowEvalRunner::class);
$dataset = AiWorkflowEvalDataset::query()->where('name', 'decide-actions')->firstOrFail();

$evalRun = $runner->run(
    name: 'Decision eval',
    requests: $dataset->requests(),
    models: ['openrouter:anthropic/claude-4', 'openrouter:google/gemini-3-pro'],
    judge: app(MyJudge::class),
);

$evalRun->averageScore();                                    // overall
$evalRun->averageScoreForModel('openrouter:anthropic/claude-4'); // per model
```

## Prompt Testing

Run YAML-defined test cases against prompts to verify AI outputs.

### Test File Format

Test files live alongside prompts in a `tests/` subdirectory:

```
resources/prompts/
  classify_intent.md
  tests/
    classify_intent.yaml
```

```yaml
variables:
  company_name: "Test Corp"

cases:
  - name: "Billing question"
    messages:
      - role: user
        content: "How do I update my credit card?"
    assert:
      structured:
        intent: "billing"
      contains: "billing"

  - name: "Multiple keywords"
    messages:
      - role: user
        content: "I need help with my account password"
    assert:
      contains:
        - "account"
        - "password"
```

### Running Tests

```bash
# Test a specific prompt
php artisan ai-workflow:prompt-test classify_intent

# Test all prompts that have test files
php artisan ai-workflow:prompt-test

# Override the model
php artisan ai-workflow:prompt-test classify_intent --model=anthropic:claude-4
```

## Resilience

OpenRouter calls run through laravel-integrations, which owns the circuit breaker, retries, and rate limiting. Failures are classified so the breaker reacts correctly:

- 402 / 403 / other 4xx are client errors: not retried, and they never trip the breaker. This stops a billing or access outage from becoming a retry storm.
- 429 is a throttle: retried (honouring `Retry-After`) without tripping the breaker.
- 5xx, connection errors, and timeouts are upstream faults: retried with backoff and counted toward the breaker.
- An HTTP 200 with an error in place of a response is classified by its error code in the same way, or as an upstream fault if it has no error code.
- An `Unknown`, `Error` or `Other` finish reason is also treated as an upstream fault, so the request is retried.

Backoff is a fixed ~30s pause on rate limits and ~attempt x 2s on server errors, with optional ±25% jitter. Tune it via `ai-workflow.retry`: `times` sets the max attempts, and `rate_limit_delay_ms`, `server_error_multiplier_ms`, and `jitter` shape the backoff.

When retries are exhausted, `AiService` throws one of the exceptions in `AiWorkflow\Exceptions`. They all extend `ProviderException`, which has the provider, the HTTP status (`getStatusCode()`) and the response body:

| Exception                         | Cause                                                        |
|-----------------------------------|--------------------------------------------------------------|
| `RateLimitedException`            | HTTP 429. `retryAfter` holds the `Retry-After` seconds.      |
| `InsufficientCreditsException`    | HTTP 402.                                                    |
| `ProviderOverloadedException`     | HTTP 502, 503, 504, 520, 522 or 524.                         |
| `ProviderConnectionException`     | No response, such as a timeout or a refused connection.     |
| `ProviderRequestException`        | Any other HTTP error.                                        |
| `UpstreamErrorException`          | An error or empty body on an HTTP 200, or an error in a stream. `errorCode` holds the error code. |
| `UnexpectedFinishReasonException` | An `Unknown`, `Error` or `Other` finish reason.              |
| `StructuredDecodingException`     | Structured output that is not a JSON object.                 |

`sendStructuredData()` throws these wrapped in `StructuredDataRequestException`.

The integration row stores the breaker state and rate budget. The transport audit is in `integration_requests`, with one row per HTTP request.

### Fallback Models

If the JSON in a structured response cannot be decoded (the model produced invalid output, or a number too large to represent), the package retries the request with the `fallback_model` configured in the prompt's front-matter, if there is one. The fallback request is run through the same middleware as the original, and both requests are logged.

## Finish Reason Handling

After each AI response, the finish reason is checked:

| Finish Reason               | Behaviour                                                                                |
|-----------------------------|------------------------------------------------------------------------------------------|
| `Stop`, `ToolCalls`         | Success. The package returns the response.                                               |
| `Unknown`, `Error`, `Other` | Transient issue. The package retries, then throws `UnexpectedFinishReasonException`.     |
| `Length`, `ContentFilter`   | Degraded. The package calls `report()`, then returns the response.                       |

## Testing

`AiWorkflow\Testing\OpenRouterFake` fakes OpenRouter's HTTP API with Laravel's HTTP client fake, so your tests run the same request, retry and logging code as production:

```php
use AiWorkflow\Testing\OpenRouterFake;

OpenRouterFake::respondWith(
    OpenRouterFake::toolCalls([['name' => 'get_weather', 'arguments' => ['city' => 'Lisbon']]]),
    OpenRouterFake::completion('It is sunny in Lisbon.', usage: OpenRouterFake::tokens(120, 30)),
);

// Your code that calls AiService receives these responses in order.

OpenRouterFake::sentBodies(); // the JSON bodies sent to OpenRouter, oldest first
```

It also builds structured replies (`structured()`), HTTP errors (`error(429, headers: ['Retry-After' => '5'])`), errors on an HTTP 200 (`errorEnvelope()`), failed connections (`connectionFailure()`), and streams (`textStream()`, `toolCallStream()`, `stream()`). Queue every response in a single `respondWith()` call. The package reads the OpenRouter API key from the integration row, so create an OpenRouter integration row in your test setup.

## Upgrading from 6.x

Version 7.0 uses the Laravel AI SDK instead of Prism. The package now has its own types, so you can remove every import from `Prism\` in your code.

1. Require Laravel 12.62+ or 13.15+. Laravel 11 is no longer supported.
2. Remove the `prism-php/prism` requirement and the `pocketarc/prism` repository from your `composer.json`, unless you use Prism yourself.
3. Make sure the OpenRouter integration row holds your OpenRouter key. In 7.0, the package reads the key from that row and ignores `OPENROUTER_API_KEY`. Put the keys for other providers in the Laravel AI SDK's `config/ai.php`.
4. Replace the Prism types:

| 6.x (Prism)                                            | 7.0                                                          |
|--------------------------------------------------------|--------------------------------------------------------------|
| `ValueObjects\Messages\UserMessage` and the other messages | `AiWorkflow\Messages\UserMessage` and the other messages |
| `ValueObjects\Media\Image`, `Document`, `Audio`, `Video`, `Text` | `AiWorkflow\Messages\Attachment` with an `AttachmentKind` |
| `ValueObjects\ToolCall`, `ToolResult`                  | `AiWorkflow\Messages\ToolCall`, `ToolResult`                 |
| `Schema\ObjectSchema` and the other schemas            | `AiWorkflow\Schema\ResponseSchema` with a JSON Schema array  |
| `Tool::as(...)->using(...)`                            | `new AiWorkflow\Tools\Tool(name, description, parameters, handler)` |
| `Text\Response`, `Structured\Response`                 | `AiWorkflow\Responses\TextResponse`, `StructuredResponse`    |
| `ValueObjects\Usage` (`promptTokens`, `completionTokens`, `cacheReadInputTokens`, `cacheWriteInputTokens`) | `AiWorkflow\Responses\Usage` (`inputTokens`, `outputTokens`, `cacheReadTokens`, `cacheWriteTokens`) |
| `ValueObjects\Meta`                                    | `AiWorkflow\Responses\ResponseMeta`                          |
| `Enums\FinishReason`                                   | `AiWorkflow\Enums\FinishReason`, with the same values        |
| `Streaming\Events\*Event`                              | `AiWorkflow\Streaming\*`                                     |
| `PrismException` and its subclasses                    | `AiWorkflow\Exceptions\ProviderException` and its subclasses |
| `Prism::fake()`                                        | `AiWorkflow\Testing\OpenRouterFake`                          |

5. Update your judges' signature to `judge(AiWorkflowRequest, TextResponse|StructuredResponse)`.
6. Update your tool handlers to take a single `array $arguments`.

Other changes to plan for:

- `TextResponse::$messages` holds only the assistant and tool-result messages from the call, and `toolCalls` and `toolResults` cover every step, not only the last one.
- An `Unknown`, `Error` or `Other` finish reason is now retried before `UnexpectedFinishReasonException` is thrown. A response rejected for its finish reason is no longer logged as the call's response. Its body and tokens are still logged.
- Each step of a tool loop now has its own `integration_requests` row and is retried separately.
- For providers other than OpenRouter, the package now takes token counts from the Laravel AI SDK, where input tokens include cache reads and writes, and output tokens include thought tokens. Eval report costs are calculated with this formula for every provider.
- The package now translates `reasoning` front-matter into each provider's own request fields. It throws an exception for a Gemini or xAI prompt with an integer budget, and it sends `minimal` for a Gemini prompt with `none`.
- A generic `Media` attachment is now sent according to its MIME type. In 6.x, the package left it out of OpenRouter requests.
- When the package logs a request with a URL attachment, it no longer downloads the file. In 6.x, it downloaded the file to store its base64 content next to the URL.

The stored data format has not changed, so logged requests, schemas, cache keys and cached responses from 6.x can be read and replayed as before.

## Development

```bash
docker compose up -d devtools
docker compose exec devtools composer install
docker compose exec devtools ./vendor/bin/pint          # Code style
docker compose exec devtools ./vendor/bin/phpstan analyse --memory-limit=1G  # Static analysis
docker compose exec devtools ./vendor/bin/phpunit       # Tests
```

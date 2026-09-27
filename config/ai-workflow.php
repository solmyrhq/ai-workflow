<?php

declare(strict_types=1);

return [
    // Directory containing prompt markdown files with YAML front-matter.
    'prompts_path' => resource_path('prompts'),

    // Transient-failure handling is owned by laravel-integrations (circuit
    // breaker + retries). `times` is the per-request max attempts; the delay
    // values feed OpenRouterProvider's CustomizesRetry backoff.
    'retry' => [
        'times' => 3,
        'rate_limit_delay_ms' => 30_000,
        'server_error_multiplier_ms' => 2_000,
        'jitter' => true,
    ],

    // OpenRouter integration settings.
    'openrouter' => [
        // Per-minute request budget for the OpenRouter integration, or null
        // for unlimited. The circuit breaker is the primary protection; this
        // is an optional secondary pacing limit honored by the framework.
        'rate_limit_per_minute' => null,
    ],

    // Guzzle request options for AI calls. `timeout` (seconds) applies to
    // every provider; the other options apply to OpenRouter only.
    'client_options' => [
        'timeout' => 600,
        'curl' => [
            CURLOPT_IGNORE_CONTENT_LENGTH => true,
        ],
    ],

    // Max tokens defaults per response type.
    'max_tokens' => [
        'text' => 16_384,
        'structured' => 32_768,
    ],

    // Maximum tool-use steps per text/stream request.
    'max_steps' => 15,

    // Request logging — records every AI call with enough detail to replay.
    'logging' => [
        'enabled' => env('AI_WORKFLOW_LOGGING', false),
    ],

    // Retention applied by ai-workflow:prune. Requests the eval framework
    // references — annotated, scored, or belonging to an execution in an eval
    // dataset — are kept regardless of age; deleting them would cascade away
    // labels and scores, or break dataset replays.
    'pruning' => [
        // Delete unreferenced ai_workflow_requests older than this many days.
        // Also the age past which an execution with no remaining requests and
        // no dataset membership is deleted.
        'requests_days' => 90,

        // Deleting in chunks avoids holding a table lock for the whole prune
        // on a large backlog.
        'chunk_size' => 1000,
    ],

    // Response caching — opt-in per prompt via cache_ttl front-matter.
    'cache' => [
        'enabled' => env('AI_WORKFLOW_CACHE', false),
        'store' => env('AI_WORKFLOW_CACHE_STORE'),
    ],

    // Middleware pipeline — global middleware applied to every AI request.
    'middleware' => [],

    'eval' => [
        // Set the number of attempts per replay, including the first attempt. If the
        // integration provider is registered, the runner uses its failure policy to
        // classify failures and calculate retry delays. Keep this value low because
        // you pay for one call per item on every attempt.
        'replay_tries' => 2,
    ],

    // Human review UI (ai-workflow:review). Off unless explicitly enabled, so
    // the routes never answer in a deployed app; the command turns it on for
    // the local server it starts.
    'review' => [
        'enabled' => env('AI_WORKFLOW_REVIEW', false),
        'reviewer' => env('AI_WORKFLOW_REVIEWER'),
        'per_page' => 20,

        // Optional AiWorkflow\Eval\ReviewContextResolver implementation, adding
        // links and situational notes to each reviewed request.
        'context' => null,
    ],

    // Per-model prices for eval reports, in USD per 1M tokens. A model missing
    // from this map is shown without a cost and listed as having no pricing.
    // Cache-read or cache-write tokens without a configured rate are charged
    // at the input rate.
    'model_pricing' => [
        // 'openrouter:vendor/model' => ['input' => 5.00, 'output' => 25.00, 'cache_read' => 0.50, 'cache_write' => 6.25],
    ],
];

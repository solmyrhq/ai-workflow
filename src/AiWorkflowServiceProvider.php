<?php

declare(strict_types=1);

namespace AiWorkflow;

use AiWorkflow\Console\EvalAddCommand;
use AiWorkflow\Console\EvalListCommand;
use AiWorkflow\Console\EvalRemoveCommand;
use AiWorkflow\Console\EvalReportCommand;
use AiWorkflow\Console\EvalRunCommand;
use AiWorkflow\Console\EvalShowCommand;
use AiWorkflow\Console\PromptTestCommand;
use AiWorkflow\Console\PruneCommand;
use AiWorkflow\Console\ReviewCommand;
use AiWorkflow\Eval\AiWorkflowEvalRunner;
use AiWorkflow\Eval\GoldenSetAssembler;
use AiWorkflow\Events\AiWorkflowRequestCompleted;
use AiWorkflow\Events\AiWorkflowRequestFailed;
use AiWorkflow\Gateway\LlmClient;
use AiWorkflow\Gateway\ProviderFactory;
use AiWorkflow\Integrations\OpenRouterProvider;
use AiWorkflow\Listeners\SentryBreadcrumbListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Integrations\IntegrationManager;
use Override;

class AiWorkflowServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ai-workflow.php', 'ai-workflow');

        IntegrationManager::registerDefaults([
            'openrouter' => OpenRouterProvider::class,
        ]);

        $this->app->singleton(ProviderFactory::class);
        $this->app->singleton(LlmClient::class);
        $this->app->singleton(AiService::class);
        $this->app->singleton(PromptService::class);
        $this->app->singleton(AiWorkflowReplayer::class);
        $this->app->singleton(AiWorkflowCache::class);
        $this->app->singleton(AiWorkflowEvalRunner::class);
        $this->app->singleton(GoldenSetAssembler::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'ai-workflow');

        // The review UI is a local labelling tool, so its routes only exist
        // when explicitly switched on.
        if (config('ai-workflow.review.enabled') === true) {
            $this->loadRoutesFrom(__DIR__.'/../routes/review.php');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/ai-workflow.php' => config_path('ai-workflow.php'),
            ], 'ai-workflow-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/ai-workflow'),
            ], 'ai-workflow-views');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'ai-workflow-migrations');

            $this->commands([
                EvalAddCommand::class,
                EvalListCommand::class,
                EvalRemoveCommand::class,
                EvalReportCommand::class,
                EvalRunCommand::class,
                EvalShowCommand::class,
                PromptTestCommand::class,
                PruneCommand::class,
                ReviewCommand::class,
            ]);
        }

        if (function_exists('\Sentry\addBreadcrumb')) {
            Event::listen(AiWorkflowRequestCompleted::class, [SentryBreadcrumbListener::class, 'handleCompleted']);
            Event::listen(AiWorkflowRequestFailed::class, [SentryBreadcrumbListener::class, 'handleFailed']);
        }
    }
}

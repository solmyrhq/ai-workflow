<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

use AiWorkflow\AiWorkflowServiceProvider;
use AiWorkflow\Integrations\OpenRouterProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Integrations\IntegrationsServiceProvider;
use Integrations\Testing\CreatesIntegration;
use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Override;
use Spatie\LaravelData\LaravelDataServiceProvider;

abstract class TestCase extends BaseTestCase
{
    use CreatesIntegration;
    use RefreshDatabase;

    /**
     * @return list<class-string>
     */
    #[Override]
    protected function getPackageProviders($app): array
    {
        return [
            AiServiceProvider::class,
            IntegrationsServiceProvider::class,
            AiWorkflowServiceProvider::class,
            LaravelDataServiceProvider::class,
        ];
    }

    #[Override]
    protected function defineEnvironment($app): void
    {
        // laravel-integrations encrypts credentials, so the suite needs a key.
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));

        $app['config']->set('ai-workflow.prompts_path', __DIR__.'/Fixtures/prompts');
        $app['config']->set('data.structure_caching.enabled', false);

        $app['config']->set('ai-workflow.retry.jitter', false);
        $app['config']->set('ai-workflow.retry.rate_limit_delay_ms', 0);
        $app['config']->set('ai-workflow.retry.server_error_multiplier_ms', 0);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    #[Override]
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../vendor/pocketarc/laravel-integrations/database/migrations');
    }

    /**
     * Every AI call routes through laravel-integrations, so the suite needs an
     * OpenRouter integration to resolve. Seeded fresh per test (inside the
     * RefreshDatabase transaction) so circuit/health state never leaks.
     */
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->createIntegration(
            providerKey: 'openrouter',
            providerClass: OpenRouterProvider::class,
            credentials: ['api_key' => 'test-key'],
        );
    }
}

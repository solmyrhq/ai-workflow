<?php

declare(strict_types=1);

namespace AiWorkflow\Gateway;

use Illuminate\Contracts\Events\Dispatcher;
use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Providers\OpenRouterProvider;
use LogicException;
use RuntimeException;

/**
 * Finds the Integration row for a provider key, and builds the laravel/ai
 * provider and gateway for it.
 *
 * The OpenRouter provider is built here, with the API key from the
 * Integration row, so that the application's AiManager is not modified.
 * Other providers come from the AiManager.
 *
 * @internal
 */
class ProviderFactory
{
    public function __construct(
        private readonly AiManager $ai,
        private readonly Dispatcher $events,
    ) {}

    /**
     * The Integration row for a provider key, or null when the provider isn't
     * managed by laravel-integrations.
     *
     * Throws when a registered provider has no row or more than one.
     */
    public function integration(string $provider): ?Integration
    {
        // The manager is scoped to the request, so it is resolved per call.
        if (! app(IntegrationManager::class)->has($provider)) {
            return null;
        }

        $integrations = Integration::query()->where('provider', $provider)->orderBy('id')->take(2)->get();

        if ($integrations->isEmpty()) {
            throw new RuntimeException(
                "Provider '{$provider}' is registered with laravel-integrations but has no Integration row. Create one (e.g. `php artisan integrations:install {$provider}`) before making AI requests."
            );
        }

        if ($integrations->count() > 1) {
            throw new RuntimeException(
                "Multiple Integration rows exist for provider '{$provider}'. There must be exactly one, so that ai-workflow uses the right credentials and circuit breaker. Remove the duplicates."
            );
        }

        return $integrations->first();
    }

    /**
     * @return array{TextProvider, StepTextGateway}
     */
    public function resolve(string $provider, ?Integration $integration): array
    {
        if ($provider === 'openrouter') {
            return [$this->openRouter($integration), new OpenRouterGateway($this->events, $this->clientOptions())];
        }

        $textProvider = $this->ai->textProvider($provider);
        $gateway = method_exists($textProvider, 'textGateway') ? $textProvider->textGateway() : null;

        if (! $gateway instanceof StepTextGateway) {
            throw new LogicException("The [{$provider}] provider has no step text gateway.");
        }

        return [$textProvider, $gateway];
    }

    /**
     * The request timeout in whole seconds, applied to every provider.
     */
    public function timeout(): ?int
    {
        $timeout = $this->clientOptions()['timeout'] ?? null;

        return is_int($timeout) || is_float($timeout) ? (int) ceil($timeout) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function clientOptions(): array
    {
        $options = config('ai-workflow.client_options');

        if (! is_array($options)) {
            return [];
        }

        return array_filter($options, is_string(...), ARRAY_FILTER_USE_KEY);
    }

    private function openRouter(?Integration $integration): OpenRouterProvider
    {
        if ($integration === null) {
            throw new LogicException('OpenRouter calls cannot be made without the OpenRouter Integration row.');
        }

        $key = $integration->credentialsArray()['api_key'] ?? null;

        if (! is_string($key) || $key === '') {
            throw new RuntimeException("The OpenRouter Integration row (id {$integration->id}) has no api_key credential.");
        }

        $config = config('ai.providers.openrouter');

        return new OpenRouterProvider(
            [...(is_array($config) ? $config : []), 'name' => 'openrouter', 'driver' => 'openrouter', 'key' => $key],
            $this->events,
        );
    }
}

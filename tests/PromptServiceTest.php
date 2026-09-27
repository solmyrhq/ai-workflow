<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

use AiWorkflow\PromptData;
use AiWorkflow\PromptService;
use InvalidArgumentException;
use RuntimeException;

class PromptServiceTest extends TestCase
{
    private PromptService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PromptService::class);
    }

    public function test_load_returns_prompt_data(): void
    {
        $prompt = $this->service->load('test_prompt');

        $this->assertInstanceOf(PromptData::class, $prompt);
        $this->assertSame('test_prompt', $prompt->id);
        $this->assertSame('openrouter:test/model', $prompt->model);
        $this->assertSame('You are a helpful test assistant.', $prompt->prompt);
        $this->assertNull($prompt->fallbackModel);
    }

    public function test_load_parses_fallback_model(): void
    {
        $prompt = $this->service->load('fallback_prompt');

        $this->assertSame('openrouter:test/primary-model', $prompt->model);
        $this->assertSame('openrouter:test/fallback-model', $prompt->fallbackModel);
    }

    public function test_load_parses_provider_in_model(): void
    {
        $prompt = $this->service->load('provider_prompt');

        $this->assertSame('anthropic:claude-opus-4.5', $prompt->model);
        [$provider, $model] = PromptData::parseModelIdentifier($prompt->model);
        $this->assertSame('anthropic', $provider);
        $this->assertSame('claude-opus-4.5', $model);
    }

    public function test_load_throws_for_missing_file(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Prompt file not found: nonexistent');

        $this->service->load('nonexistent');
    }

    public function test_load_throws_for_invalid_front_matter(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("missing required 'model'");

        $this->service->load('invalid_prompt');
    }

    public function test_load_with_variables_renders_template(): void
    {
        $prompt = $this->service->load('template_prompt', [
            'customer_name' => 'Jane',
            'product' => 'Pro',
            'is_vip' => true,
        ]);

        $this->assertStringContainsString('You are helping Jane with their Pro subscription.', $prompt->prompt);
        $this->assertStringContainsString('This is a VIP customer.', $prompt->prompt);
    }

    public function test_load_with_variables_omits_falsy_sections(): void
    {
        $prompt = $this->service->load('template_prompt', [
            'customer_name' => 'Bob',
            'product' => 'Basic',
            'is_vip' => false,
        ]);

        $this->assertStringContainsString('You are helping Bob with their Basic subscription.', $prompt->prompt);
        $this->assertStringNotContainsString('VIP', $prompt->prompt);
    }

    public function test_load_without_variables_passes_through(): void
    {
        $prompt = $this->service->load('template_prompt');

        $this->assertStringContainsString('{{ customer_name }}', $prompt->prompt);
    }

    public function test_load_preserves_raw_template(): void
    {
        $prompt = $this->service->load('template_prompt', [
            'customer_name' => 'Jane',
            'product' => 'Pro',
        ]);

        $this->assertNotNull($prompt->rawTemplate);
        $this->assertStringContainsString('{{ customer_name }}', $prompt->rawTemplate);
        $this->assertStringContainsString('Jane', $prompt->prompt);
    }

    public function test_raw_template_set_even_without_variables(): void
    {
        $prompt = $this->service->load('test_prompt');

        $this->assertNotNull($prompt->rawTemplate);
        $this->assertSame($prompt->prompt, $prompt->rawTemplate);
    }

    public function test_load_parses_tags_from_front_matter(): void
    {
        $prompt = $this->service->load('tagged_prompt');

        $this->assertSame(['classification', 'intent'], $prompt->tags);
    }

    public function test_load_returns_empty_tags_when_not_specified(): void
    {
        $prompt = $this->service->load('test_prompt');

        $this->assertSame([], $prompt->tags);
    }

    public function test_load_parses_cache_ttl(): void
    {
        $prompt = $this->service->load('cached_prompt');

        $this->assertSame(3600, $prompt->cacheTtl);
    }

    public function test_load_returns_null_cache_ttl_when_not_specified(): void
    {
        $prompt = $this->service->load('test_prompt');

        $this->assertNull($prompt->cacheTtl);
    }

    public function test_load_parses_reasoning_effort_as_string(): void
    {
        $prompt = $this->service->load('reasoning_effort_prompt');

        $this->assertSame('high', $prompt->reasoning);
    }

    public function test_load_parses_reasoning_max_tokens_as_int(): void
    {
        $prompt = $this->service->load('reasoning_tokens_prompt');

        $this->assertSame(8000, $prompt->reasoning);
    }

    public function test_load_returns_null_reasoning_when_not_specified(): void
    {
        $prompt = $this->service->load('test_prompt');

        $this->assertNull($prompt->reasoning);
    }

    public function test_resolve_reasoning_options_for_openrouter_effort(): void
    {
        $prompt = new PromptData(id: 'test', model: 'openrouter:test/model', prompt: 'test', reasoning: 'high');

        $this->assertSame(['reasoning' => ['effort' => 'high']], $prompt->resolveReasoningOptions('openrouter', 16384));
    }

    public function test_resolve_reasoning_options_for_openrouter_max_tokens(): void
    {
        $prompt = new PromptData(id: 'test', model: 'openrouter:test/model', prompt: 'test', reasoning: 8000);

        $this->assertSame(['reasoning' => ['max_tokens' => 8000]], $prompt->resolveReasoningOptions('openrouter', 16384));
    }

    public function test_resolve_reasoning_options_for_anthropic_max_tokens(): void
    {
        $prompt = new PromptData(id: 'test', model: 'anthropic:claude-4', prompt: 'test', reasoning: 8000);

        $this->assertSame(['thinking' => ['type' => 'enabled', 'budget_tokens' => 8000]], $prompt->resolveReasoningOptions('anthropic', 16384));
    }

    public function test_resolve_reasoning_options_for_anthropic_effort(): void
    {
        $prompt = new PromptData(id: 'test', model: 'anthropic:claude-4', prompt: 'test', reasoning: 'high');

        // high = 0.8 * 16384 = 13107
        $this->assertSame(['thinking' => ['type' => 'enabled', 'budget_tokens' => 13107]], $prompt->resolveReasoningOptions('anthropic', 16384));
    }

    public function test_resolve_reasoning_options_for_anthropic_effort_clamps_minimum(): void
    {
        $prompt = new PromptData(id: 'test', model: 'anthropic:claude-4', prompt: 'test', reasoning: 'minimal');

        // minimal = 0.1 * 2000 = 200, clamped to 1024
        $this->assertSame(['thinking' => ['type' => 'enabled', 'budget_tokens' => 1024]], $prompt->resolveReasoningOptions('anthropic', 2000));
    }

    public function test_resolve_reasoning_options_for_anthropic_none_returns_empty(): void
    {
        $prompt = new PromptData(id: 'test', model: 'anthropic:claude-4', prompt: 'test', reasoning: 'none');

        $this->assertSame([], $prompt->resolveReasoningOptions('anthropic', 16384));
    }

    public function test_resolve_reasoning_options_for_gemini_effort(): void
    {
        $prompt = new PromptData(id: 'test', model: 'gemini:gemini-3-pro', prompt: 'test', reasoning: 'high');

        $this->assertSame(['thinking_level' => 'high'], $prompt->resolveReasoningOptions('gemini', 16384));
    }

    public function test_resolve_reasoning_options_for_gemini_xhigh_maps_to_high(): void
    {
        $prompt = new PromptData(id: 'test', model: 'gemini:gemini-3-pro', prompt: 'test', reasoning: 'xhigh');

        $this->assertSame(['thinking_level' => 'high'], $prompt->resolveReasoningOptions('gemini', 16384));
    }

    public function test_resolve_reasoning_options_for_gemini_none_asks_for_the_least_thinking(): void
    {
        $prompt = new PromptData(id: 'test', model: 'gemini:gemini-3-pro', prompt: 'test', reasoning: 'none');

        $this->assertSame(['thinking_level' => 'minimal'], $prompt->resolveReasoningOptions('gemini', 16384));
    }

    public function test_resolve_reasoning_options_for_gemini_rejects_a_token_budget(): void
    {
        $prompt = new PromptData(id: 'test', model: 'gemini:gemini-3-pro', prompt: 'test', reasoning: 8000);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Gemini only accepts an effort level');

        $prompt->resolveReasoningOptions('gemini', 16384);
    }

    public function test_resolve_reasoning_options_for_ollama_and_xai(): void
    {
        $effort = new PromptData(id: 'test', model: 'xai:grok-4', prompt: 'test', reasoning: 'medium');
        $none = new PromptData(id: 'test', model: 'xai:grok-4', prompt: 'test', reasoning: 'none');

        $this->assertSame(['think' => true], $effort->resolveReasoningOptions('ollama', 16384));
        $this->assertSame([], $none->resolveReasoningOptions('ollama', 16384));
        $this->assertSame(['reasoning_effort' => 'high'], $effort->resolveReasoningOptions('xai', 16384));
        $this->assertSame([], $none->resolveReasoningOptions('xai', 16384));
    }

    public function test_resolve_reasoning_options_returns_empty_when_null(): void
    {
        $prompt = new PromptData(id: 'test', model: 'openrouter:test/model', prompt: 'test');

        $this->assertSame([], $prompt->resolveReasoningOptions('openrouter', 16384));
    }
}

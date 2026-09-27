<?php

declare(strict_types=1);

namespace AiWorkflow;

use InvalidArgumentException;

class PromptData
{
    /**
     * @param  list<string>  $tags
     * @param  array<string, mixed>  $variables
     */
    public function __construct(
        public readonly string $id,
        public readonly string $model,
        public readonly string $prompt,
        public readonly ?string $fallbackModel = null,
        public readonly ?string $rawTemplate = null,
        public readonly array $tags = [],
        public readonly ?int $cacheTtl = null,
        public readonly array $variables = [],
        public readonly string|int|null $reasoning = null,
        public readonly ?int $maxTokens = null,
    ) {}

    private const EFFORT_RATIOS = [
        'xhigh' => 0.95,
        'high' => 0.8,
        'medium' => 0.5,
        'low' => 0.2,
        'minimal' => 0.1,
        'none' => 0.0,
    ];

    /**
     * Translate the reasoning setting into the provider's request body fields.
     *
     * @return array<string, mixed>
     */
    public function resolveReasoningOptions(string $provider, int $maxTokens): array
    {
        $reasoning = $this->reasoning;

        if ($reasoning === null) {
            return [];
        }

        return match ($provider) {
            'anthropic' => $this->resolveAnthropicReasoning($reasoning, $maxTokens),
            'gemini' => $this->resolveGeminiReasoning($reasoning),
            'ollama' => $reasoning === 'none' ? [] : ['think' => true],
            'xai' => $this->resolveXaiReasoning($reasoning),
            default => $this->resolveDefaultReasoning($reasoning),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveAnthropicReasoning(string|int $reasoning, int $maxTokens): array
    {
        if ($reasoning === 'none') {
            return [];
        }

        $budgetTokens = is_int($reasoning)
            ? $reasoning
            : $this->effortToBudgetTokens($reasoning, $maxTokens);

        return ['thinking' => ['type' => 'enabled', 'budget_tokens' => $budgetTokens]];
    }

    /**
     * Gemini cannot turn thinking off, so "none" is sent as the lowest level,
     * "minimal".
     *
     * @return array<string, mixed>
     */
    private function resolveGeminiReasoning(string|int $reasoning): array
    {
        if (is_int($reasoning)) {
            throw new InvalidArgumentException("Prompt '{$this->id}' has a reasoning budget of {$reasoning} tokens, but Gemini only accepts an effort level (minimal, low, medium or high).");
        }

        return ['thinking_level' => match ($reasoning) {
            'none' => 'minimal',
            'xhigh' => 'high',
            default => $reasoning,
        }];
    }

    /**
     * xAI's reasoning_effort field accepts only "low" and "high".
     *
     * @return array<string, mixed>
     */
    private function resolveXaiReasoning(string|int $reasoning): array
    {
        if (is_int($reasoning)) {
            throw new InvalidArgumentException("Prompt '{$this->id}' has a reasoning budget of {$reasoning} tokens, but xAI only accepts an effort level.");
        }

        return match ($reasoning) {
            'none' => [],
            'minimal', 'low' => ['reasoning_effort' => 'low'],
            default => ['reasoning_effort' => 'high'],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveDefaultReasoning(string|int $reasoning): array
    {
        if ($reasoning === 'none') {
            return ['reasoning' => ['effort' => 'none']];
        }

        return is_int($reasoning)
            ? ['reasoning' => ['max_tokens' => $reasoning]]
            : ['reasoning' => ['effort' => $reasoning]];
    }

    private function effortToBudgetTokens(string $effort, int $maxTokens): int
    {
        $ratio = self::EFFORT_RATIOS[$effort] ?? self::EFFORT_RATIOS['medium'];

        return max(min((int) floor($maxTokens * $ratio), 128_000), 1024);
    }

    /**
     * Parse a unified model identifier into [provider, model].
     *
     * @return array{string, string}
     */
    public static function parseModelIdentifier(string $identifier): array
    {
        $colonPos = strpos($identifier, ':');

        if ($colonPos === false) {
            throw new \RuntimeException("Invalid model identifier '{$identifier}': must be in 'provider:model' format (e.g. 'openrouter:anthropic/claude-4')");
        }

        return [substr($identifier, 0, $colonPos), substr($identifier, $colonPos + 1)];
    }
}

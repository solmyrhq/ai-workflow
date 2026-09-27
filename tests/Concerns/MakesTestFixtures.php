<?php

declare(strict_types=1);

namespace AiWorkflow\Tests\Concerns;

use AiWorkflow\PromptData;
use AiWorkflow\Schema\ResponseSchema;

trait MakesTestFixtures
{
    private function makePrompt(
        string $id = 'test',
        string $model = 'openrouter:test-model',
        ?string $fallbackModel = null,
        ?int $cacheTtl = null,
    ): PromptData {
        return new PromptData(
            id: $id,
            model: $model,
            prompt: 'You are a helpful assistant.',
            fallbackModel: $fallbackModel,
            cacheTtl: $cacheTtl,
        );
    }

    private function makeSchema(): ResponseSchema
    {
        return new ResponseSchema('test', [
            'description' => 'A test schema',
            'type' => 'object',
            'properties' => ['answer' => ['description' => 'The answer', 'type' => 'string']],
            'required' => ['answer'],
            'additionalProperties' => false,
        ]);
    }
}

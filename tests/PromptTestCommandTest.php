<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

use AiWorkflow\Testing\OpenRouterFake;
use Symfony\Component\Yaml\Yaml;

class PromptTestCommandTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
        $this->tempFiles = [];

        parent::tearDown();
    }

    public function test_runs_single_prompt_test(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('Hello! How can I help you?'));

        $this->artisan('ai-workflow:prompt-test', ['prompt' => 'test_prompt'])
            ->expectsOutputToContain('PASS: Basic text response')
            ->expectsOutputToContain('Results: 1/1 passed')
            ->assertExitCode(0);
    }

    public function test_contains_assertion_fails_when_missing(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('Goodbye cruel world'));

        $this->artisan('ai-workflow:prompt-test', ['prompt' => 'test_prompt'])
            ->expectsOutputToContain('FAIL: Basic text response')
            ->expectsOutputToContain('Results: 0/1 passed')
            ->assertExitCode(1);
    }

    public function test_runs_all_prompt_tests(): void
    {
        OpenRouterFake::respondWith(
            // template_prompt (alphabetically first)
            OpenRouterFake::completion('Hi Jane Doe, welcome to your Pro Plan support.'),
            // test_prompt
            OpenRouterFake::completion('Hello there!'),
        );

        $this->artisan('ai-workflow:prompt-test')
            ->expectsOutputToContain('Results: 2/2 passed')
            ->assertExitCode(0);
    }

    public function test_warns_on_missing_test_file(): void
    {
        $this->artisan('ai-workflow:prompt-test', ['prompt' => 'nonexistent'])
            ->expectsOutputToContain('No test file found')
            ->assertExitCode(0);
    }

    public function test_template_variables_are_injected(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('Hello Jane Doe, I see you are on the Pro Plan. As a VIP, let me help you right away.'));

        $this->artisan('ai-workflow:prompt-test', ['prompt' => 'template_prompt'])
            ->expectsOutputToContain('PASS: VIP customer greeting')
            ->assertExitCode(0);
    }

    public function test_structured_assertion(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::structured(['intent' => 'billing', 'confidence' => '0.9']));

        $this->createTempTestFile('structured_test', [
            'cases' => [
                [
                    'name' => 'Intent classification',
                    'messages' => [
                        ['role' => 'user', 'content' => 'How do I update my credit card?'],
                    ],
                    'assert' => [
                        'structured' => ['intent' => 'billing'],
                    ],
                ],
            ],
        ]);

        $this->artisan('ai-workflow:prompt-test', ['prompt' => 'structured_test'])
            ->expectsOutputToContain('PASS: Intent classification')
            ->assertExitCode(0);

        $this->assertSame([
            'name' => 'PromptTestSchema',
            'strict' => true,
            'schema' => [
                'description' => 'Auto-generated schema from test assertions',
                'type' => 'object',
                'properties' => ['intent' => ['description' => 'intent', 'type' => 'string']],
                'required' => ['intent'],
                'additionalProperties' => false,
            ],
        ], OpenRouterFake::sentBodies()[0]['response_format']['json_schema']);
    }

    public function test_structured_assertion_failure(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::structured(['intent' => 'support']));

        $this->createTempTestFile('structured_fail', [
            'cases' => [
                [
                    'name' => 'Wrong classification',
                    'messages' => [
                        ['role' => 'user', 'content' => 'Help'],
                    ],
                    'assert' => [
                        'structured' => ['intent' => 'billing'],
                    ],
                ],
            ],
        ]);

        $this->artisan('ai-workflow:prompt-test', ['prompt' => 'structured_fail'])
            ->expectsOutputToContain('FAIL: Wrong classification')
            ->assertExitCode(1);
    }

    public function test_model_override(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::completion('Hello from override model!'));

        $this->artisan('ai-workflow:prompt-test', [
            'prompt' => 'test_prompt',
            '--model' => 'openrouter:other/model',
        ])
            ->expectsOutputToContain('PASS: Basic text response')
            ->assertExitCode(0);

        $this->assertSame('other/model', OpenRouterFake::sentBodies()[0]['model']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createTempTestFile(string $name, array $data): string
    {
        /** @var string $basePath */
        $basePath = config('ai-workflow.prompts_path');

        $promptPath = "{$basePath}/{$name}.md";
        file_put_contents($promptPath, "---\nmodel: openrouter:test/model\n---\n\nTest prompt.");
        $this->tempFiles[] = $promptPath;

        $testPath = "{$basePath}/tests/{$name}.yaml";
        file_put_contents($testPath, Yaml::dump($data, 4));
        $this->tempFiles[] = $testPath;

        return $testPath;
    }
}

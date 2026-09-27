<?php

declare(strict_types=1);

namespace AiWorkflow\Console;

use AiWorkflow\AiService;
use AiWorkflow\Messages\AssistantMessage;
use AiWorkflow\Messages\Message;
use AiWorkflow\Messages\SystemMessage;
use AiWorkflow\Messages\UserMessage;
use AiWorkflow\PromptData;
use AiWorkflow\PromptService;
use AiWorkflow\Responses\StructuredResponse;
use AiWorkflow\Responses\TextResponse;
use AiWorkflow\Schema\ResponseSchema;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Symfony\Component\Yaml\Yaml;

class PromptTestCommand extends Command
{
    /** @var string */
    protected $signature = 'ai-workflow:prompt-test
        {prompt? : Prompt ID to test (omit to run all)}
        {--model= : Override the prompt model (provider:model format)}';

    /** @var string */
    protected $description = 'Run prompt tests defined in YAML files against AI models.';

    private int $passed = 0;

    private int $failed = 0;

    public function handle(PromptService $promptService, AiService $aiService): int
    {
        $promptId = $this->argument('prompt');
        $testFiles = is_string($promptId) && $promptId !== ''
            ? $this->findTestFile($promptId)
            : $this->findAllTestFiles();

        if ($testFiles === []) {
            $this->warn('No test files found.');

            return self::SUCCESS;
        }

        foreach ($testFiles as $testFile) {
            $this->runTestFile($testFile, $promptService, $aiService);
        }

        $this->newLine();
        $total = $this->passed + $this->failed;
        $this->info("Results: {$this->passed}/{$total} passed");

        return $this->failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function findTestFile(string $promptId): array
    {
        $path = $this->testsPath()."/{$promptId}.yaml";

        if (! file_exists($path)) {
            $this->warn("No test file found for prompt '{$promptId}' at {$path}");

            return [];
        }

        return [$path];
    }

    /**
     * @return list<string>
     */
    private function findAllTestFiles(): array
    {
        $dir = $this->testsPath();

        if (! is_dir($dir)) {
            return [];
        }

        $files = glob("{$dir}/*.yaml");

        if (! is_array($files)) {
            return [];
        }

        sort($files);

        return $files;
    }

    private function testsPath(): string
    {
        /** @var string $basePath */
        $basePath = config('ai-workflow.prompts_path');

        return "{$basePath}/tests";
    }

    private function runTestFile(string $path, PromptService $promptService, AiService $aiService): void
    {
        $promptId = pathinfo($path, PATHINFO_FILENAME);
        $this->info("Testing prompt: {$promptId}");

        $content = file_get_contents($path);
        if ($content === false) {
            $this->error("  Could not read {$path}");
            $this->failed++;

            return;
        }

        /** @var array<string, mixed> $testData */
        $testData = Yaml::parse($content);

        /** @var array<string, mixed> $variables */
        $variables = is_array($testData['variables'] ?? null) ? $testData['variables'] : [];

        /** @var list<array<string, mixed>> $cases */
        $cases = is_array($testData['cases'] ?? null) ? $testData['cases'] : [];

        if ($cases === []) {
            $this->warn("  No test cases found in {$path}");

            return;
        }

        $prompt = $promptService->load($promptId, $variables);

        $modelOverride = $this->option('model');
        if (is_string($modelOverride) && $modelOverride !== '') {
            $prompt = new PromptData(
                id: $prompt->id,
                model: $modelOverride,
                prompt: $prompt->prompt,
                fallbackModel: $prompt->fallbackModel,
                rawTemplate: $prompt->rawTemplate,
                tags: $prompt->tags,
                cacheTtl: $prompt->cacheTtl,
                variables: $prompt->variables,
                reasoning: $prompt->reasoning,
                maxTokens: $prompt->maxTokens,
            );
        }

        foreach ($cases as $case) {
            $this->runTestCase($case, $prompt, $aiService);
        }
    }

    /**
     * @param  array<string, mixed>  $case
     */
    private function runTestCase(array $case, PromptData $prompt, AiService $aiService): void
    {
        $name = is_string($case['name'] ?? null) ? $case['name'] : 'unnamed';

        /** @var list<array<string, mixed>> $rawMessages */
        $rawMessages = is_array($case['messages'] ?? null) ? $case['messages'] : [];

        /** @var array<string, mixed> $assertions */
        $assertions = is_array($case['assert'] ?? null) ? $case['assert'] : [];

        $messages = $this->buildMessages($rawMessages);

        try {
            if (array_key_exists('structured', $assertions)) {
                $schema = $this->buildSchemaFromAssertions($assertions);
                $response = $aiService->sendStructuredMessages($messages, $prompt, $schema);
                $this->runAssertions($name, $assertions, $response);
            } else {
                $response = $aiService->sendMessages($messages, $prompt);
                $this->runAssertions($name, $assertions, $response);
            }
        } catch (\Throwable $e) {
            $this->error("  FAIL: {$name} — {$e->getMessage()}");
            $this->failed++;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rawMessages
     * @return Collection<int, Message>
     */
    private function buildMessages(array $rawMessages): Collection
    {
        /** @var list<Message> $messages */
        $messages = [];

        foreach ($rawMessages as $msg) {
            $content = is_string($msg['content'] ?? null) ? $msg['content'] : '';
            $role = is_string($msg['role'] ?? null) ? $msg['role'] : 'user';

            $messages[] = match ($role) {
                'assistant' => new AssistantMessage($content),
                'system' => new SystemMessage($content),
                default => new UserMessage($content),
            };
        }

        return new Collection($messages);
    }

    /**
     * @param  array<string, mixed>  $assertions
     */
    private function runAssertions(string $name, array $assertions, TextResponse|StructuredResponse $response): void
    {
        $failures = [];

        if (array_key_exists('contains', $assertions)) {
            $text = $response instanceof StructuredResponse
                ? json_encode($response->structured, JSON_THROW_ON_ERROR)
                : $response->text;

            /** @var list<mixed> $rawNeedles */
            $rawNeedles = is_array($assertions['contains']) ? $assertions['contains'] : [$assertions['contains']];

            foreach ($rawNeedles as $needle) {
                if (! is_string($needle)) {
                    continue;
                }
                if (! str_contains(mb_strtolower($text), mb_strtolower($needle))) {
                    $failures[] = "Response does not contain '{$needle}'";
                }
            }
        }

        if (array_key_exists('structured', $assertions) && $response instanceof StructuredResponse) {
            /** @var array<string, mixed> $expected */
            $expected = is_array($assertions['structured']) ? $assertions['structured'] : [];

            foreach ($expected as $key => $expectedValue) {
                $actual = $response->structured[$key] ?? null;
                if (json_encode($actual) !== json_encode($expectedValue)) {
                    $failures[] = "structured.{$key}: expected ".json_encode($expectedValue).', got '.json_encode($actual);
                }
            }
        }

        if ($failures !== []) {
            foreach ($failures as $failure) {
                $this->error("  FAIL: {$name} — {$failure}");
            }
            $this->failed++;
        } else {
            $this->line("  PASS: {$name}");
            $this->passed++;
        }
    }

    /**
     * Build a simple schema from the structured assertion keys.
     *
     * @param  array<string, mixed>  $assertions
     */
    private function buildSchemaFromAssertions(array $assertions): ResponseSchema
    {
        /** @var array<string, mixed> $structured */
        $structured = is_array($assertions['structured'] ?? null) ? $assertions['structured'] : [];

        $properties = [];

        foreach ($structured as $key => $value) {
            $properties[$key] = [
                'description' => $key,
                'type' => match (true) {
                    is_int($value), is_float($value) => 'number',
                    is_bool($value) => 'boolean',
                    default => 'string',
                },
            ];
        }

        $schema = ['description' => 'Auto-generated schema from test assertions', 'type' => 'object'];

        if ($properties !== []) {
            $schema['properties'] = $properties;
        }

        return new ResponseSchema('PromptTestSchema', [
            ...$schema,
            'required' => array_keys($structured),
            'additionalProperties' => false,
        ]);
    }
}

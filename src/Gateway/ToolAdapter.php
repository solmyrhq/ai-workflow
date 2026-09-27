<?php

declare(strict_types=1);

namespace AiWorkflow\Gateway;

use AiWorkflow\Tools\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool as LaravelTool;
use Laravel\Ai\Tools\Request;
use stdClass;

/**
 * Adapts a package Tool for laravel/ai's gateways, which use it only to add
 * the tool definition to the request. LlmClient runs the tools.
 *
 * @internal
 */
final class ToolAdapter implements LaravelTool
{
    public function __construct(
        public readonly Tool $tool,
    ) {}

    public function name(): string
    {
        return $this->tool->name;
    }

    #[\Override]
    public function description(): string
    {
        return $this->tool->description;
    }

    #[\Override]
    public function handle(Request $request): string
    {
        return $this->tool->handle($request->all());
    }

    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return SchemaConverter::toProperties($this->tool->parameters);
    }

    /**
     * The tool's parameter schema as sent to OpenRouter, which requires an
     * empty property list to be a JSON object.
     *
     * @return array<array-key, mixed>
     */
    public function parameters(): array
    {
        $parameters = $this->tool->parameters === [] ? ['type' => 'object'] : $this->tool->parameters;

        if (($parameters['properties'] ?? []) === []) {
            $parameters['properties'] = new stdClass;
        }

        return $parameters;
    }
}

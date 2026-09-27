<?php

declare(strict_types=1);

namespace AiWorkflow\Tools;

use Closure;
use Throwable;

class Tool
{
    /**
     * @param  array<array-key, mixed>  $parameters  A JSON Schema object describing the arguments.
     * @param  Closure(array<string, mixed>): mixed  $handler
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $parameters,
        private readonly Closure $handler,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function handle(array $arguments): string
    {
        try {
            $result = ($this->handler)($arguments);
        } catch (Throwable $e) {
            report($e);

            return sprintf('Tool execution error: %s. This error occurred during tool execution, not due to invalid parameters.', $e->getMessage());
        }

        return is_string($result) ? $result : json_encode($result, JSON_THROW_ON_ERROR);
    }
}

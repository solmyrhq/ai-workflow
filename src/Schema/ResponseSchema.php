<?php

declare(strict_types=1);

namespace AiWorkflow\Schema;

use InvalidArgumentException;

class ResponseSchema
{
    /**
     * @param  array<array-key, mixed>  $schema  A JSON Schema object.
     */
    public function __construct(
        private readonly string $name,
        private readonly array $schema,
    ) {
        if (($schema['type'] ?? null) !== 'object') {
            throw new InvalidArgumentException("Response schema [{$name}] must describe an object.");
        }
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function toArray(): array
    {
        return $this->schema;
    }
}

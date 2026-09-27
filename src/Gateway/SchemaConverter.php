<?php

declare(strict_types=1);

namespace AiWorkflow\Gateway;

use AiWorkflow\Exceptions\UnsupportedSchemaException;
use Illuminate\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use InvalidArgumentException;
use stdClass;

/**
 * Converts a JSON Schema object into the typed properties that laravel/ai's
 * gateways take. It supports only the JSON Schema subset that
 * illuminate/json-schema can parse.
 *
 * @internal
 */
final class SchemaConverter
{
    /**
     * @param  array<array-key, mixed>  $schema
     * @return array<string, Type>
     */
    public static function toProperties(array $schema): array
    {
        $properties = self::object($schema['properties'] ?? [], 'properties');
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];

        $types = [];

        foreach ($properties as $name => $property) {
            try {
                $type = JsonSchema::fromArray(self::object($property, "property [{$name}]"));
            } catch (InvalidArgumentException $e) {
                throw new UnsupportedSchemaException("Property [{$name}] uses JSON Schema that cannot be converted for direct providers: {$e->getMessage()}", previous: $e);
            }

            $types[$name] = in_array($name, $required, true) ? $type->required() : $type;
        }

        return $types;
    }

    /**
     * @return array<string, mixed>
     */
    private static function object(mixed $value, string $label): array
    {
        $items = $value instanceof stdClass ? get_object_vars($value) : $value;

        if (! is_array($items)) {
            throw new UnsupportedSchemaException("The schema's {$label} must be a JSON object.");
        }

        $object = [];

        foreach ($items as $key => $item) {
            if (! is_string($key)) {
                throw new UnsupportedSchemaException("The schema's {$label} must be a JSON object, not a list.");
            }

            $object[$key] = $item;
        }

        return $object;
    }
}

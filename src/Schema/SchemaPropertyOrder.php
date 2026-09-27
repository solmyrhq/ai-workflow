<?php

declare(strict_types=1);

namespace AiWorkflow\Schema;

class SchemaPropertyOrder
{
    /**
     * Put each object's properties back in the order of its `required` list.
     *
     * This assumes that `required` lists the properties in the order they were
     * sent. An object is left unchanged unless `required` names exactly its
     * properties.
     *
     * @param  array<array-key, mixed>  $schema
     * @return array<array-key, mixed>
     */
    public static function restore(array $schema): array
    {
        $properties = $schema['properties'] ?? null;

        if (is_array($properties)) {
            $restored = [];

            foreach ($properties as $name => $property) {
                $restored[$name] = is_array($property) ? self::restore($property) : $property;
            }

            $schema['properties'] = self::orderByRequired($restored, $schema['required'] ?? null);
        }

        $items = $schema['items'] ?? null;

        if (is_array($items)) {
            $schema['items'] = self::restore($items);
        }

        foreach (['anyOf', 'oneOf', 'allOf'] as $keyword) {
            $branches = $schema[$keyword] ?? null;

            if (is_array($branches)) {
                $schema[$keyword] = array_map(static fn (mixed $branch): mixed => is_array($branch) ? self::restore($branch) : $branch, $branches);
            }
        }

        return $schema;
    }

    /**
     * @param  array<array-key, mixed>  $properties
     * @return array<array-key, mixed>
     */
    private static function orderByRequired(array $properties, mixed $required): array
    {
        if (! is_array($required) || ! array_is_list($required)) {
            return $properties;
        }

        $names = array_map(strval(...), array_keys($properties));
        $requiredNames = array_map(static fn (mixed $field): string => is_string($field) ? $field : '', $required);
        sort($names);
        sort($requiredNames);

        if ($names !== $requiredNames) {
            return $properties;
        }

        $ordered = [];

        foreach ($required as $field) {
            if (! is_string($field) || ! array_key_exists($field, $properties)) {
                return $properties;
            }

            $ordered[$field] = $properties[$field];
        }

        return $ordered;
    }
}

<?php

declare(strict_types=1);

namespace AiWorkflow;

use AiWorkflow\Attributes\ArrayItemType;
use AiWorkflow\Attributes\Description;
use AiWorkflow\Schema\ResponseSchema;
use BackedEnum;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;
use RuntimeException;
use Spatie\LaravelData\Data;

/**
 * Generates a strict JSON Schema from a Spatie LaravelData class. The schema
 * is part of the response cache key and is stored on logged requests, so its
 * key order and layout must match 6.x, which UpgradeCompatibilityTest checks.
 */
class SchemaBuilder
{
    /**
     * @param  class-string<Data>  $dataClass
     */
    public static function fromDataClass(string $dataClass): ResponseSchema
    {
        if (! class_exists(Data::class)) {
            throw new RuntimeException('spatie/laravel-data is required to use SchemaBuilder. Install it with: composer require spatie/laravel-data');
        }

        $name = (new ReflectionClass($dataClass))->getShortName();

        return new ResponseSchema($name, self::objectSchema($dataClass, $name, false));
    }

    /**
     * @param  class-string  $dataClass
     * @return array<string, mixed>
     */
    private static function objectSchema(string $dataClass, string $description, bool $nullable): array
    {
        $constructor = (new ReflectionClass($dataClass))->getConstructor();

        if ($constructor === null) {
            throw new RuntimeException("Data class {$dataClass} has no constructor");
        }

        $properties = [];
        $requiredFields = [];

        foreach ($constructor->getParameters() as $param) {
            $name = $param->getName();
            $properties[$name] = self::resolveType($param->getType(), $name, self::getDescription($param), $param);

            // Strict mode rejects a schema that lists a key in `properties` but not in `required`.
            $requiredFields[] = $name;
        }

        $schema = ['description' => $description, 'type' => self::type('object', $nullable)];

        if ($properties !== []) {
            $schema['properties'] = $properties;
        }

        $schema['required'] = $requiredFields;
        $schema['additionalProperties'] = false;

        return $schema;
    }

    /**
     * Drop the nulls a model returned for properties that declare a default, so PHP applies the
     * default. `sendStructuredData()` calls this; call it yourself if you hydrate the structured
     * response from `sendStructuredMessages()`.
     *
     * @param  class-string<Data>  $dataClass
     * @param  array<mixed>|null  $structured
     * @return array<mixed>|null
     */
    public static function stripNullsForDefaultedProperties(string $dataClass, ?array $structured): ?array
    {
        if ($structured === null) {
            return null;
        }

        $constructor = (new ReflectionClass($dataClass))->getConstructor();

        if ($constructor === null) {
            return $structured;
        }

        foreach ($constructor->getParameters() as $param) {
            $name = $param->getName();

            if (! array_key_exists($name, $structured)) {
                continue;
            }

            $value = $structured[$name];

            if ($value === null) {
                if ($param->isDefaultValueAvailable()) {
                    unset($structured[$name]);
                }

                continue;
            }

            // Nested schemas widen their own defaulted properties, so their nulls need the same
            // treatment before the nested constructor sees them.
            $nested = self::nestedDataClass($param);

            if ($nested === null || ! is_array($value)) {
                continue;
            }

            [$nestedClass, $isList] = $nested;

            if (! $isList) {
                $structured[$name] = self::stripNullsForDefaultedProperties($nestedClass, $value);

                continue;
            }

            foreach ($value as $index => $item) {
                if (is_array($item)) {
                    $value[$index] = self::stripNullsForDefaultedProperties($nestedClass, $item);
                }
            }

            $structured[$name] = $value;
        }

        return $structured;
    }

    /**
     * The Data class behind a property, if it holds one or a list of them.
     *
     * @return array{class-string<Data>, bool}|null [data class, whether the property holds a list]
     */
    private static function nestedDataClass(ReflectionParameter $param): ?array
    {
        $type = $param->getType();
        $namedType = $type;

        if ($type instanceof ReflectionUnionType) {
            $namedType = null;

            foreach ($type->getTypes() as $unionMember) {
                if ($unionMember instanceof ReflectionNamedType && $unionMember->getName() !== 'null') {
                    $namedType = $unionMember;

                    break;
                }
            }
        }

        if (! $namedType instanceof ReflectionNamedType) {
            return null;
        }

        if ($namedType->getName() !== 'array') {
            $typeName = $namedType->getName();

            return is_subclass_of($typeName, Data::class) ? [$typeName, false] : null;
        }

        $attributes = $param->getAttributes(ArrayItemType::class);

        if ($attributes === []) {
            return null;
        }

        $itemType = $attributes[0]->newInstance()->type;

        return is_subclass_of($itemType, Data::class) ? [$itemType, true] : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function resolveType(?\ReflectionType $type, string $name, string $description, ReflectionParameter $param): array
    {
        if ($type === null) {
            throw new RuntimeException("Property '{$name}' has no type declaration");
        }

        if ($type instanceof ReflectionIntersectionType) {
            throw new RuntimeException("Property '{$name}' has an intersection type that cannot be mapped to a schema");
        }

        $nullable = false;

        if ($type instanceof ReflectionUnionType) {
            $nonNullTypes = [];
            foreach ($type->getTypes() as $unionMember) {
                if ($unionMember instanceof ReflectionNamedType && $unionMember->getName() !== 'null') {
                    $nonNullTypes[] = $unionMember;
                }
            }

            if (count($nonNullTypes) !== 1) {
                throw new RuntimeException("Property '{$name}' has a union type that cannot be mapped to a schema");
            }

            $namedType = $nonNullTypes[0];
            $nullable = true;
        } else {
            $namedType = $type;
        }

        if (! $namedType instanceof ReflectionNamedType) {
            throw new RuntimeException("Property '{$name}' has an unsupported type");
        }

        $nullable = $nullable || $namedType->allowsNull();

        // Strict mode cannot leave a key out, so a defaulted property needs a null to return.
        $nullable = $nullable || $param->isDefaultValueAvailable();

        return match ($namedType->getName()) {
            'string' => self::scalar('string', $description, $nullable),
            'int', 'float' => self::scalar('number', $description, $nullable),
            'bool' => self::scalar('boolean', $description, $nullable),
            'array' => ['description' => $description, 'type' => self::type('array', $nullable), 'items' => self::arrayItemSchema($param)],
            default => self::classSchema($namedType->getName(), $name, $description, $nullable),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function arrayItemSchema(ReflectionParameter $param): array
    {
        $attributes = $param->getAttributes(ArrayItemType::class);

        if ($attributes === []) {
            return self::scalar('string', 'Array item', false);
        }

        $typeName = $attributes[0]->newInstance()->type;

        return match (true) {
            $typeName === 'int', $typeName === 'float' => self::scalar('number', 'Array item', false),
            $typeName === 'bool' => self::scalar('boolean', 'Array item', false),
            is_subclass_of($typeName, Data::class) => self::objectSchema($typeName, 'Array item', false),
            default => self::scalar('string', 'Array item', false),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function classSchema(string $typeName, string $name, string $description, bool $nullable): array
    {
        if (is_subclass_of($typeName, Data::class)) {
            return self::objectSchema($typeName, $description, $nullable);
        }

        if (is_subclass_of($typeName, BackedEnum::class)) {
            $options = array_map(fn (BackedEnum $case): string|int => $case->value, $typeName::cases());

            $types = array_values(array_unique(array_map(fn (string|int $option): string => is_int($option) ? 'number' : 'string', $options)));

            if ($nullable) {
                $types[] = 'null';
            }

            return ['description' => $description, 'enum' => $options, 'type' => count($types) === 1 ? $types[0] : $types];
        }

        throw new RuntimeException("Property '{$name}' type '{$typeName}' cannot be mapped to a schema");
    }

    /**
     * @return array{description: string, type: string|list<string>}
     */
    private static function scalar(string $type, string $description, bool $nullable): array
    {
        return ['description' => $description, 'type' => self::type($type, $nullable)];
    }

    /**
     * @return string|list<string>
     */
    private static function type(string $type, bool $nullable): string|array
    {
        return $nullable ? [$type, 'null'] : $type;
    }

    private static function getDescription(ReflectionParameter $param): string
    {
        $attributes = $param->getAttributes(Description::class);

        if ($attributes !== []) {
            /** @var Description $instance */
            $instance = $attributes[0]->newInstance();

            return $instance->text;
        }

        return $param->getName();
    }
}

<?php

declare(strict_types=1);

namespace AiWorkflow\Gateway;

use AiWorkflow\Exceptions\StructuredDecodingException;
use JsonException;

/**
 * Decodes a model's structured output, stripping a Markdown code fence.
 * Non-finite numbers are rejected because the result is JSON-encoded again
 * for logging and caching.
 *
 * @internal
 */
final class StructuredOutputDecoder
{
    /**
     * @return array<array-key, mixed>
     */
    public static function decode(string $text, string $provider): array
    {
        $json = trim($text);

        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $json, $matches) === 1) {
            $json = $matches[1];
        }

        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new StructuredDecodingException("The structured response is not valid JSON: {$e->getMessage()}", $provider, $text, $e);
        }

        if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new StructuredDecodingException('The structured response is not a JSON object.', $provider, $text);
        }

        if (self::holdsNonFiniteNumber($decoded)) {
            throw new StructuredDecodingException('The structured response contains a number too large to represent.', $provider, $text);
        }

        return $decoded;
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private static function holdsNonFiniteNumber(array $values): bool
    {
        foreach ($values as $value) {
            if (is_float($value) && ! is_finite($value)) {
                return true;
            }

            if (is_array($value) && self::holdsNonFiniteNumber($value)) {
                return true;
            }
        }

        return false;
    }
}

<?php

declare(strict_types=1);

namespace AiWorkflow\Responses;

use AiWorkflow\Enums\FinishReason;

class StructuredResponse
{
    /**
     * @param  array<array-key, mixed>  $structured  The decoded JSON object.
     */
    public function __construct(
        public readonly array $structured,
        public readonly string $text,
        public readonly FinishReason $finishReason,
        public readonly Usage $usage,
        public readonly ResponseMeta $meta,
        public readonly string $reasoning = '',
    ) {}
}

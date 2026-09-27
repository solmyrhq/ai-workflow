<?php

declare(strict_types=1);

namespace AiWorkflow;

use AiWorkflow\Responses\StructuredResponse;
use AiWorkflow\Responses\Usage;
use Spatie\LaravelData\Data;

class StructuredDataResult
{
    public function __construct(
        public readonly Data $data,
        public readonly StructuredResponse $response,
        public readonly Usage $usage,
    ) {}
}

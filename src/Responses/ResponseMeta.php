<?php

declare(strict_types=1);

namespace AiWorkflow\Responses;

class ResponseMeta
{
    public function __construct(
        public readonly string $id = '',
        public readonly string $model = '',
        public readonly ?string $provider = null,
        public readonly ?float $cost = null,
    ) {}
}

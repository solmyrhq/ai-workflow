<?php

declare(strict_types=1);

namespace AiWorkflow\Responses;

class Usage
{
    public function __construct(
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly ?int $cacheReadTokens = null,
        public readonly ?int $cacheWriteTokens = null,
        public readonly ?int $thoughtTokens = null,
    ) {}

    public static function zero(): self
    {
        return new self;
    }

    public function add(self $other): self
    {
        return new self(
            inputTokens: $this->inputTokens + $other->inputTokens,
            outputTokens: $this->outputTokens + $other->outputTokens,
            cacheReadTokens: self::addOptional($this->cacheReadTokens, $other->cacheReadTokens),
            cacheWriteTokens: self::addOptional($this->cacheWriteTokens, $other->cacheWriteTokens),
            thoughtTokens: self::addOptional($this->thoughtTokens, $other->thoughtTokens),
        );
    }

    private static function addOptional(?int $total, ?int $tokens): ?int
    {
        return $tokens === null ? $total : ($total ?? 0) + $tokens;
    }
}

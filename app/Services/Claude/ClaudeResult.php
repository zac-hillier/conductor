<?php

namespace App\Services\Claude;

readonly class ClaudeResult
{
    public function __construct(
        public bool $isError,
        public string $result,
        public ?string $sessionId = null,
        public ?float $costUsd = null,
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public ?int $numTurns = null,
        public ?int $durationMs = null,
        public string $rawJson = '',
    ) {}

    public function totalTokens(): ?int
    {
        if ($this->inputTokens === null && $this->outputTokens === null) {
            return null;
        }

        return (int) $this->inputTokens + (int) $this->outputTokens;
    }
}

<?php

declare(strict_types=1);

namespace App\Claude;

class ParsedOutput
{
    public function __construct(
        public readonly bool $success,
        public readonly string $result,
        public readonly ?string $sessionId,
        public readonly ?string $error,
        public readonly float $costUsd,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly array $images,
        public readonly array $raw,
    ) {}

    public static function fromSuccess(string $result, ?string $sessionId = null, array $raw = [], array $images = []): self
    {
        return new self(
            success: true,
            result: $result,
            sessionId: $sessionId,
            error: null,
            costUsd: (float) ($raw['total_cost_usd'] ?? $raw['cost_usd'] ?? 0),
            inputTokens: (int) ($raw['input_tokens'] ?? 0),
            outputTokens: (int) ($raw['output_tokens'] ?? 0),
            images: $images,
            raw: $raw,
        );
    }

    public static function fromFailure(string $error, array $raw = [], ?string $sessionId = null): self
    {
        return new self(
            success: false,
            result: '',
            sessionId: $sessionId,
            error: $error,
            costUsd: (float) ($raw['total_cost_usd'] ?? $raw['cost_usd'] ?? 0),
            inputTokens: (int) ($raw['input_tokens'] ?? 0),
            outputTokens: (int) ($raw['output_tokens'] ?? 0),
            images: [],
            raw: $raw,
        );
    }
}

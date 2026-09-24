<?php
declare(strict_types=1);

namespace Arche\Ai;

/**
 * Outcome of one structured text-model call (Claude or OpenAI). `data` is null
 * whenever the answer is unusable — refused, cut off, rate limited, timed out,
 * no key — and `reason` says which; every caller has a fallback for that case
 * and never retries in-line.
 */
final class TextResult
{
    /** @param array<string,mixed>|null $data */
    public function __construct(
        public readonly ?array $data,
        public readonly string $reason = 'ok',
        public readonly string $model = '',
    ) {}

    public function ok(): bool
    {
        return $this->data !== null;
    }
}

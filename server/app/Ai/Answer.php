<?php
declare(strict_types=1);

namespace Arche\Ai;

/**
 * What a long call started in one tick (OpenAI's background response,
 * Gemini's background interaction) says when a later tick asks: still
 * running, or done with its data, or why not. Like TextResult, a failure is
 * an answer without data, never an exception.
 */
final class Answer
{
    /**
     * @param 'running'|'done'|'failed' $state
     * @param array<string,mixed>|null $data
     * @param list<string> $sources the pages a web search consulted or opened
     */
    public function __construct(
        public readonly string $state,
        public readonly ?array $data = null,
        public readonly string $reason = '',
        public readonly array $sources = [],
        public readonly int $costMicros = 0,
        public readonly string $model = '',
        public readonly int $in = 0,
        public readonly int $out = 0,
    ) {}

    public function running(): bool
    {
        return $this->state === 'running';
    }

    public function ok(): bool
    {
        return $this->state === 'done' && $this->data !== null;
    }
}

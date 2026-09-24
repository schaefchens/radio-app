<?php
declare(strict_types=1);

namespace Arche\Realtime;

use Arche\App;

/**
 * POST /api/realtime/wake: where to connect, plus a fresh join token once a
 * node is ready. The listener's app polls this every few seconds while a node
 * boots (walk-in-the-spirit's resolver pattern); each poll is a SQLite read.
 */
final class WakeController
{
    public function __construct(private App $app) {}

    /** @param array<string,mixed> $identity @return array<string,mixed> */
    public function handle(array $identity, string $channel): array
    {
        $w = $this->app->nodes()->wake();
        if ($w['status'] === 'ready') $w['token'] = $this->app->tokens()->issue($identity, $channel);
        return $w;
    }
}

<?php
declare(strict_types=1);

namespace Arche\Identity;

use Arche\App;

/**
 * Sliding-window limits over the `attempts` table. Keys are HMACs, so neither
 * IPs nor device ids are stored in the clear. This is the main abuse brake: a
 * passphrase-free radio has no e-mail verification to lean on.
 */
final class RateLimit
{
    public function __construct(private App $app) {}

    /** Record one attempt; false when the limit for the window is already used. */
    public function hit(string $key, int $limit, int $windowSeconds): bool
    {
        $store = $this->app->store();
        $now = $this->app->clock->now();
        $used = (int) $store->value('SELECT COUNT(*) FROM attempts WHERE key = ? AND time > ?', [$key, $now - $windowSeconds]);
        if ($used >= $limit) return false;
        $store->query('INSERT INTO attempts(key, time) VALUES(?, ?)', [$key, $now]);
        return true;
    }

    /** How many attempts the window holds now (counting none). */
    public function used(string $key, int $windowSeconds): int
    {
        return (int) $this->app->store()->value('SELECT COUNT(*) FROM attempts WHERE key = ? AND time > ?', [$key, $this->app->clock->now() - $windowSeconds]);
    }

    /** When the window has room again (unix seconds): the moment the attempt that fills it drops out. */
    public function freeAt(string $key, int $limit, int $windowSeconds): int
    {
        $now = $this->app->clock->now();
        $over = max(0, $this->used($key, $windowSeconds) - $limit);
        $time = $this->app->store()->value('SELECT time FROM attempts WHERE key = ? AND time > ? ORDER BY time LIMIT 1 OFFSET ?', [$key, $now - $windowSeconds, $over]);
        return $time === null ? $now : (int) $time + $windowSeconds;
    }

    public function ipKey(?string $ip = null): string
    {
        $ip ??= (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        // /64 for IPv6: one household often rotates through a whole prefix.
        if (str_contains($ip, ':')) $ip = implode(':', array_slice(explode(':', $ip), 0, 4));
        return 'ip:' . substr(hash_hmac('sha256', $ip, $this->app->identities()->pepper()), 0, 24);
    }
}

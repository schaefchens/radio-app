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

    public function ipKey(?string $ip = null): string
    {
        $ip ??= (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        // /64 for IPv6: one household often rotates through a whole prefix.
        if (str_contains($ip, ':')) $ip = implode(':', array_slice(explode(':', $ip), 0, 4));
        return 'ip:' . substr(hash_hmac('sha256', $ip, $this->app->identities()->pepper()), 0, 24);
    }
}

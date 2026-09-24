<?php
declare(strict_types=1);

namespace Arche\Support;

/**
 * Named, non-blocking file locks. Two exist on purpose: `publish` (fast, local,
 * never calls the network) and `jobs` (Claude, TTS, Hetzner). A slow AI call
 * must never keep the minute files from being written.
 */
final class Lock
{
    public function __construct(private string $dir) {}

    /**
     * Run $fn while holding the lock, or return null at once if someone else
     * holds it — overlapping ticks simply skip.
     *
     * @template T
     * @param callable():T $fn
     * @return T|null
     */
    public function run(string $name, callable $fn): mixed
    {
        if (!is_dir($this->dir)) @mkdir($this->dir, 0700, true);
        $handle = @fopen($this->dir . '/' . $name . '.lock', 'c');
        if ($handle === false) throw new \RuntimeException('Cannot open lock file ' . $name);
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }
        try {
            return $fn();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}

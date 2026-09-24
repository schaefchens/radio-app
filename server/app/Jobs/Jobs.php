<?php
declare(strict_types=1);

namespace Arche\Jobs;

use Arche\App;
use Arche\Program\Timing;

/**
 * A small leased job queue in SQLite. A job advances one phase per run; the
 * lease means a phase killed mid-call (host time limit, crash) is picked up
 * again once the lease expires, up to MAX_ATTEMPTS.
 */
final class Jobs
{
    private const MAX_ATTEMPTS = 4;
    private const LEASE_SECONDS = 120;
    /** Beyond the commit horizon, how soon a job counts as urgent. */
    private const URGENT_MARGIN_MS = 5 * 60_000;

    public function __construct(private App $app) {}

    public function enqueue(string $type, int $refId, int $priority = 100, int $dueMs = 0): void
    {
        $now = $this->app->clock->now();
        $this->app->store()->query(
            "INSERT OR IGNORE INTO jobs(type, ref_id, phase, status, priority, due_ms, created, updated)
             VALUES(?, ?, 'start', 'queued', ?, ?, ?, ?)",
            [$type, $refId, $priority, $dueMs, $now, $now],
        );
    }

    public function cancel(string $type, int $refId): void
    {
        $this->app->store()->query(
            "UPDATE jobs SET status = 'done', last_error = 'cancelled', updated = ? WHERE type = ? AND ref_id = ? AND status IN ('queued', 'running')",
            [$this->app->clock->now(), $type, $refId],
        );
    }

    /**
     * The next job, now leased to the caller. Urgent work first — a host break
     * that airs within the commit horizon (plus a margin) must be voiced
     * before it is committed, and a listener waits for their submission's
     * verdict — then by priority. A break planned half an hour ahead must not
     * keep a listener's submission waiting: with real AI a tick has time for
     * only a few phases.
     *
     * @return array<string,mixed>|null
     */
    public function lease(): ?array
    {
        $store = $this->app->store();
        $now = $this->app->clock->now();
        $urgent = $this->app->clock->nowMs() + Timing::COMMIT + self::URGENT_MARGIN_MS;
        return $store->tx(function () use ($store, $now, $urgent) {
            $job = $store->one(
                "SELECT * FROM jobs WHERE (status = 'queued' OR (status = 'running' AND lease_until < ?)) AND attempts < ?
                 ORDER BY CASE WHEN due_ms <= ? THEN 0 ELSE 1 END, priority, due_ms, id LIMIT 1",
                [$now, self::MAX_ATTEMPTS, $urgent],
            );
            if ($job === null) return null;
            $store->update('jobs', [
                'status' => 'running',
                'lease_until' => $now + self::LEASE_SECONDS,
                'attempts' => (int) $job['attempts'] + 1,
                'updated' => $now,
            ], 'id = ?', [$job['id']]);
            $job['attempts'] = (int) $job['attempts'] + 1;
            return $job;
        });
    }

    /** The phase succeeded; continue with $next (null = finished). */
    public function advance(array $job, ?string $next): void
    {
        $set = $next === null
            ? ['status' => 'done', 'updated' => $this->app->clock->now()]
            : ['status' => 'queued', 'phase' => $next, 'attempts' => 0, 'lease_until' => 0, 'updated' => $this->app->clock->now()];
        $this->app->store()->update('jobs', $set, 'id = ?', [$job['id']]);
    }

    /** The phase failed; retry later, or give up after MAX_ATTEMPTS. */
    public function retry(array $job, string $error, bool $countAttempt = true): bool
    {
        $attempts = (int) $job['attempts'] - ($countAttempt ? 0 : 1);
        $final = $attempts >= self::MAX_ATTEMPTS;
        $this->app->store()->update('jobs', [
            'status' => $final ? 'failed' : 'queued',
            'attempts' => max(0, $attempts),
            'lease_until' => 0,
            'last_error' => mb_substr($error, 0, 300),
            'updated' => $this->app->clock->now(),
        ], 'id = ?', [$job['id']]);
        return $final;
    }

    /** @return array<string,int> status → count (for /mod status) */
    public function counts(): array
    {
        $out = [];
        foreach ($this->app->store()->all('SELECT status, COUNT(*) AS n FROM jobs GROUP BY status') as $r) $out[(string) $r['status']] = (int) $r['n'];
        return $out;
    }

    public function purgeBefore(int $ts): int
    {
        return $this->app->store()->query("DELETE FROM jobs WHERE status IN ('done', 'failed') AND updated < ?", [$ts])->rowCount();
    }
}

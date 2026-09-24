<?php
declare(strict_types=1);

namespace Arche\Presence;

use Arche\App;

/**
 * Reactions become a soft ranking signal for gap-fill. They arrive already
 * aggregated — per pulse from a device, or per 20 s report from a realtime
 * node — so writes scale with time, not with the number of taps. The score
 * decays with a 72-hour half-life: it reflects recent reception, not a
 * lifetime tally, and it is never shown as a leaderboard.
 */
final class Trends
{
    public const WEIGHTS = ['heart' => 1.0, 'pray' => 1.2, 'smile' => 0.6, 'raise' => 0.8, 'peace' => 0.8, 'fire' => 0.8];
    private const HALF_LIFE_HOURS = 72.0;

    public function __construct(private App $app) {}

    /** @param array<string,array<string,int>> $deltas item uid → kind → count */
    public function applyItemReactions(array $deltas, int $maxPerItem = 1000): int
    {
        if (!$deltas) return 0;
        $store = $this->app->store();
        $day = gmdate('Y-m-d', $this->app->clock->now());
        $applied = 0;
        $store->tx(function () use ($store, $deltas, $day, $maxPerItem, &$applied) {
            foreach ($deltas as $uid => $counts) {
                $item = $store->one("SELECT library_id FROM timeline_items WHERE uid = ? AND type = 'song' AND state = 'committed'", [(string) $uid]);
                if ($item === null || $item['library_id'] === null) continue;
                $score = 0.0;
                foreach ($counts as $kind => $n) {
                    $n = max(0, min($maxPerItem, (int) $n));
                    if ($n === 0 || !isset(self::WEIGHTS[$kind])) continue;
                    $store->query(
                        'INSERT INTO reactions(library_id, day, kind, n) VALUES(?, ?, ?, ?)
                         ON CONFLICT(library_id, day, kind) DO UPDATE SET n = n + excluded.n',
                        [(int) $item['library_id'], $day, $kind, $n],
                    );
                    $score += self::WEIGHTS[$kind] * $n;
                }
                if ($score > 0) {
                    $store->query('UPDATE library_items SET trend_score = trend_score + ? WHERE id = ?', [$score, (int) $item['library_id']]);
                    $applied++;
                }
            }
        });
        return $applied;
    }

    /** @param array<string,array<string,int>> $deltas voice id → kind → count */
    public function applyVoiceReactions(array $deltas): void
    {
        foreach ($deltas as $voice => $counts) {
            $n = array_sum(array_map(fn($c) => max(0, min(1000, (int) $c)), $counts));
            if ($n > 0) $this->app->store()->query('UPDATE highlights SET reactions = reactions + ? WHERE uid = ?', [$n, (string) $voice]);
        }
    }

    public function decay(): int
    {
        $last = (int) ($this->app->store()->get('trend_decay_at') ?? 0);
        $now = $this->app->clock->now();
        $hours = $last === 0 ? 1.0 : max(0.0, ($now - $last) / 3600);
        $this->app->store()->set('trend_decay_at', $now);
        $factor = 0.5 ** ($hours / self::HALF_LIFE_HOURS);
        return $this->app->store()->query('UPDATE library_items SET trend_score = trend_score * ? WHERE trend_score > 0.01', [$factor])->rowCount();
    }
}

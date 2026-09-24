<?php
declare(strict_types=1);

namespace Arche\Program;

use Arche\App;

/**
 * Gap-fill: which library song plays next when no request is waiting.
 *
 * Weighted toward the program's themes and moods and toward what listeners
 * have been reacting to (trend score), with enough randomness that the same
 * program does not become the same playlist. Repeat protection loosens step by
 * step instead of leaving a hole: a pool of ten songs still plays, it just
 * repeats sooner.
 */
final class Selector
{
    /** [song repeat window, same-artist gap] in ms, strictest first. */
    private const WINDOWS = [
        [6 * 3_600_000, 3_600_000],
        [3 * 3_600_000, 1_800_000],
        [3_600_000, 900_000],
        [1_200_000, 0],
        [0, 0],
    ];

    /** @var \Closure(int,int):int */
    private \Closure $rand;

    public function __construct(private App $app)
    {
        $this->rand = static fn(int $min, int $max): int => random_int($min, $max);
    }

    /** Deterministic randomness for tests. @param \Closure(int,int):int $rand */
    public function useRandom(\Closure $rand): void
    {
        $this->rand = $rand;
    }

    /**
     * @param array<string,mixed> $channel
     * @param array<string,mixed> $program decoded program
     * @return array<string,mixed>|null library item
     */
    public function pick(array $channel, array $program, int $atMs, int $maxMs): ?array
    {
        $cid = (int) $channel['id'];
        $candidates = $this->app->library()->candidates($cid, (int) $program['id'], $maxMs);
        if (!$candidates) return null;
        $timeline = $this->app->timeline();
        foreach (self::WINDOWS as [$repeat, $artistGap]) {
            $recent = $repeat > 0 ? $timeline->recentLibraryIds($cid, $atMs - $repeat) : [];
            $artists = $artistGap > 0 ? $timeline->recentArtists($cid, $atMs - $artistGap) : [];
            $pool = array_values(array_filter($candidates, function (array $c) use ($recent, $artists): bool {
                if (isset($recent[$c['id']])) return false;
                $artist = strtolower(trim((string) $c['artist']));
                return $artist === '' || !isset($artists[$artist]);
            }));
            if ($pool) return $this->weighted($pool, $program);
        }
        return null;
    }

    /** @param list<array<string,mixed>> $pool @param array<string,mixed> $program @return array<string,mixed> */
    private function weighted(array $pool, array $program): array
    {
        $themes = (array) $program['themes'];
        $moods = (array) $program['moods'];
        $maxTrend = max(1.0, ...array_map(fn($c) => (float) $c['trend_score'], $pool));
        $weights = [];
        foreach ($pool as $i => $c) {
            $w = 10.0;
            $w += 10.0 * min(2, count(array_intersect($themes, (array) $c['themes'])));
            $w += 5.0 * min(2, count(array_intersect($moods, (array) $c['moods'])));
            $w *= 1.0 + max(0.0, (float) $c['trend_score']) / $maxTrend;
            $weights[$i] = max(1, (int) round($w * 10));
        }
        $roll = ($this->rand)(1, array_sum($weights));
        foreach ($weights as $i => $w) {
            $roll -= $w;
            if ($roll <= 0) return $pool[$i];
        }
        return $pool[array_key_last($pool)];
    }

    /** A jingle, rotating through what exists. @return array<string,mixed>|null */
    public function jingle(int $maxMs): ?array
    {
        $jingles = array_values(array_filter($this->app->library()->jingles(), fn($j) => $j['duration_ms'] <= $maxMs));
        if (!$jingles) return null;
        return $jingles[($this->rand)(0, count($jingles) - 1)];
    }
}

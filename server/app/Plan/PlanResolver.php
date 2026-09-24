<?php
declare(strict_types=1);

namespace Arche\Plan;

use Arche\App;

/**
 * Which program is on a channel at a given instant.
 *
 * Plans are written in the channel's station time ("Prayer 07:00–08:00") and
 * resolved to UTC here, per station-local calendar day:
 *
 *   special day (fixed date, or an Easter offset) → weekday → default plan
 *
 * Gaps a plan leaves are filled with the channel's fallback program, so every
 * instant resolves to exactly one block. Local wall-clock times go through
 * DateTimeImmutable in the channel's zone, which makes the DST days come out
 * right: on the spring day 02:30 does not exist and lands on 03:30, in autumn
 * 02:30 is taken at its first (summer-time) occurrence, and those days are 23
 * and 25 hours long.
 */
final class PlanResolver
{
    /** @var array<string,list<array{start:int,end:int,program_id:int}>> */
    private array $cache = [];
    private int $cacheVersion = -1;

    public function __construct(private App $app) {}

    private function checkCache(): void
    {
        $v = $this->app->catalog()->version();
        if ($v !== $this->cacheVersion) {
            $this->cache = [];
            $this->cacheVersion = $v;
        }
    }

    /** @param array<string,mixed> $channel */
    public function zone(array $channel): \DateTimeZone
    {
        try {
            return new \DateTimeZone((string) $channel['timezone']);
        } catch (\Throwable) {
            return new \DateTimeZone('Europe/Berlin');
        }
    }

    /** Station-local date (Y-m-d) of an instant. @param array<string,mixed> $channel */
    public function localDate(array $channel, int $tMs): string
    {
        return (new \DateTimeImmutable('@' . intdiv($tMs, 1000)))->setTimezone($this->zone($channel))->format('Y-m-d');
    }

    /** @param array<string,mixed> $channel */
    public function dayPlanIdFor(array $channel, string $date): ?int
    {
        $store = $this->app->store();
        [$y, $m, $d] = array_map('intval', explode('-', $date));
        $cid = (int) $channel['id'];

        // An exact-year entry beats a yearly one; either beats an Easter rule
        // only when both exist on the same day, which a moderator would notice.
        $special = $store->one(
            "SELECT day_plan_id FROM special_days WHERE channel_id = ? AND kind = 'date' AND month = ? AND day = ?
             AND (year IS NULL OR year = ?) ORDER BY year IS NULL, id LIMIT 1",
            [$cid, $m, $d, $y],
        );
        if ($special !== null) return (int) $special['day_plan_id'];

        foreach ($store->all("SELECT easter_offset, day_plan_id FROM special_days WHERE channel_id = ? AND kind = 'easter' ORDER BY id", [$cid]) as $e) {
            if (Easter::offset($y, (int) $e['easter_offset']) === $date) return (int) $e['day_plan_id'];
        }

        $weekday = (int) (new \DateTimeImmutable($date . ' 12:00', $this->zone($channel)))->format('N');
        $week = $store->one('SELECT day_plan_id FROM week_plan WHERE channel_id = ? AND weekday = ?', [$cid, $weekday]);
        if ($week !== null) return (int) $week['day_plan_id'];

        return $channel['default_day_plan_id'] !== null ? (int) $channel['default_day_plan_id'] : null;
    }

    /**
     * Blocks covering the whole station-local day, in UTC milliseconds.
     *
     * @param array<string,mixed> $channel
     * @return list<array{start:int,end:int,program_id:int}>
     */
    public function blocksForDate(array $channel, string $date): array
    {
        $this->checkCache();
        $key = $channel['id'] . '|' . $date;
        if (isset($this->cache[$key])) return $this->cache[$key];

        $tz = $this->zone($channel);
        $at = function (int $minute) use ($date, $tz): int {
            if ($minute >= 1440) {
                $next = (new \DateTimeImmutable($date . ' 12:00', $tz))->modify('+1 day')->format('Y-m-d');
                return (new \DateTimeImmutable($next . ' 00:00', $tz))->getTimestamp() * 1000;
            }
            return (new \DateTimeImmutable(sprintf('%s %02d:%02d', $date, intdiv($minute, 60), $minute % 60), $tz))->getTimestamp() * 1000;
        };

        $fallback = $this->fallbackProgramId($channel);
        $planId = $this->dayPlanIdFor($channel, $date);
        $blocks = $planId !== null ? $this->app->catalog()->blocks($planId) : [];

        $out = [];
        $cursor = 0;
        foreach ($blocks as $b) {
            if ($b['start_min'] > $cursor) $out[] = ['start' => $at($cursor), 'end' => $at($b['start_min']), 'program_id' => $fallback];
            $out[] = ['start' => $at($b['start_min']), 'end' => $at($b['end_min']), 'program_id' => $b['program_id']];
            $cursor = $b['end_min'];
        }
        if ($cursor < 1440) $out[] = ['start' => $at($cursor), 'end' => $at(1440), 'program_id' => $fallback];

        // A block that collapsed to nothing (both ends inside the skipped
        // spring-forward hour) is dropped; neighbours stay contiguous.
        $out = array_values(array_filter($out, fn($b) => $b['end'] > $b['start']));
        // Merge neighbours running the same program, so "Worship 06–07" plus
        // "Worship 07–09" is one block with one intro, not two.
        $merged = [];
        foreach ($out as $b) {
            $last = count($merged) - 1;
            if ($last >= 0 && $merged[$last]['program_id'] === $b['program_id'] && $merged[$last]['end'] === $b['start']) {
                $merged[$last]['end'] = $b['end'];
            } else {
                $merged[] = $b;
            }
        }
        return $this->cache[$key] = $merged;
    }

    /**
     * The block containing $tMs. Consecutive days are consulted so that a block
     * spanning local midnight is still found from either side.
     *
     * @param array<string,mixed> $channel
     * @return array{start:int,end:int,program_id:int}
     */
    public function blockAt(array $channel, int $tMs): array
    {
        $date = $this->localDate($channel, $tMs);
        foreach ($this->blocksForDate($channel, $date) as $b) {
            if ($tMs >= $b['start'] && $tMs < $b['end']) {
                // Extend across midnight when tomorrow starts with the same program.
                if ($b['end'] - $tMs < 3_600_000) {
                    $next = $this->blocksForDate($channel, $this->localDate($channel, $b['end']))[0] ?? null;
                    if ($next !== null && $next['program_id'] === $b['program_id'] && $next['start'] === $b['end']) {
                        $b['end'] = $next['end'];
                    }
                }
                return $b;
            }
        }
        // Unreachable when blocksForDate covers the day; keep the generator alive anyway.
        return ['start' => $tMs, 'end' => $tMs + 3_600_000, 'program_id' => $this->fallbackProgramId($channel)];
    }

    /** @param array<string,mixed> $channel */
    public function fallbackProgramId(array $channel): int
    {
        if (!empty($channel['fallback_program_id'])) return (int) $channel['fallback_program_id'];
        $first = $this->app->store()->value('SELECT id FROM programs WHERE channel_id = ? ORDER BY id LIMIT 1', [(int) $channel['id']]);
        if ($first === null) throw new \RuntimeException('Channel ' . $channel['slug'] . ' has no program');
        return (int) $first;
    }
}

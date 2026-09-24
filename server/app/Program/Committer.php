<?php
declare(strict_types=1);

namespace Arche\Program;

use Arche\App;

/**
 * Turns drafts into the fixed timeline, contiguously, up to now + COMMIT.
 * Committed items are what minute files publish, so everything here errs on
 * the side of "play something now" over "wait":
 *
 *  - A plain host break whose voice is not ready is dropped; music continues.
 *  - A listener's announced request (a unit) is delayed instead — a filler
 *    song goes first — and only loses its announcement after UNIT_TIMEOUT.
 *  - Drafts of a program whose block ended more than SOFT_OVERRUN ago are
 *    skipped; if the timeline runs early into a block, it is filled first.
 *  - After an outage (frontier in the past) the timeline restarts at the next
 *    minute + REANCHOR_LEAD, with a `gap` item clients bridge with the
 *    evergreen loop. Nothing is ever written for a minute that has passed.
 */
final class Committer
{
    public function __construct(private App $app) {}

    public function frontier(int $channelId): ?int
    {
        $v = $this->app->store()->get("frontier:$channelId");
        return $v === null ? null : (int) $v;
    }

    public static function anchor(int $nowMs): int
    {
        return Timing::floorMinute($nowMs) + Timing::MINUTE + Timing::REANCHOR_LEAD;
    }

    /** @param array<string,mixed> $channel @return int items committed */
    public function commit(array $channel): int
    {
        $cid = (int) $channel['id'];
        $now = $this->app->clock->nowMs();
        $timeline = $this->app->timeline();
        $frontier = $this->frontier($cid);
        $committed = 0;

        if ($frontier === null || $frontier < $now + Timing::REANCHOR_LEAD) {
            $anchor = self::anchor($now);
            $gapStart = $frontier === null ? Timing::floorMinute($now) : max($frontier, Timing::floorMinute($now));
            if ($anchor > $gapStart) {
                $block = $this->app->resolver()->blockAt($channel, $gapStart);
                $gap = $timeline->addDraft($cid, [
                    'type' => 'gap', 'dur_ms' => $anchor - $gapStart, 'est_start' => $gapStart,
                    'program_id' => $block['program_id'], 'block_start' => $block['start'], 'block_end' => $block['end'],
                    'payload' => new \stdClass(),
                ], $this->seqBeforeDrafts($cid));
                $timeline->commit($gap['id'], $gapStart, $anchor - $gapStart);
                $committed++;
            }
            $frontier = $anchor;
            $this->app->store()->audit('generator', 'Timeline re-anchored', $channel['slug'] . ' at ' . gmdate('c', intdiv($anchor, 1000)));
        }

        $target = $now + Timing::COMMIT;
        for ($guard = 0; $frontier < $target && $guard < 120; $guard++) {
            $item = $timeline->drafts($cid, 1)[0] ?? null;
            if ($item === null) {
                if ($this->app->drafter()->draft($channel) === 0) break;
                continue;
            }

            // Late: this draft's program is already over.
            if ($item['block_end'] <= $frontier - Timing::SOFT_OVERRUN && $item['submission_id'] === null) {
                $this->dropItem($item);
                continue;
            }
            // Early: its program has not started yet — fill the difference.
            if ($item['block_start'] > $frontier + Timing::SOFT_OVERRUN) {
                $filler = $this->app->drafter()->filler($channel, $frontier, $item['block_start'] - $frontier, $item['seq']);
                $frontier = $this->commitItem($filler, $frontier, $filler['dur_ms'], $filler['payload']);
                $committed++;
                continue;
            }

            if ($item['type'] === 'host') {
                $result = $this->commitHost($channel, $item, $frontier, $now);
                if ($result === null) continue;             // dropped
                if ($result === -1) {                        // delayed unit: play a filler first
                    $filler = $this->app->drafter()->filler($channel, $frontier, 240_000, $item['seq']);
                    $frontier = $this->commitItem($filler, $frontier, $filler['dur_ms'], $filler['payload']);
                } else {
                    $frontier = $result;
                }
                $committed++;
                continue;
            }

            $frontier = $this->commitItem($item, $frontier, $item['dur_ms'], $item['payload']);
            $committed++;
        }

        $this->app->store()->set("frontier:$cid", $frontier);
        $this->reestimate($cid, $frontier);
        return $committed;
    }

    /**
     * @param array<string,mixed> $channel
     * @param array<string,mixed> $item
     * @return int|null|-1 new frontier; null = dropped; -1 = unit delayed
     */
    private function commitHost(array $channel, array $item, int $frontier, int $now): int|null
    {
        $breaks = $this->app->hostBreaks();
        $hb = $item['host_break_id'] !== null ? $breaks->get($item['host_break_id']) : null;
        $ready = $hb !== null && $hb['state'] === 'ready';

        if (!$ready) {
            $waited = $now - $item['created'] * 1000;
            $failed = $hb === null || in_array($hb['state'], ['failed', 'cancelled'], true);
            if ($item['unit'] !== null && !$failed && $waited < Timing::UNIT_TIMEOUT) return -1;
            // A plain break that is late, or a unit that waited too long: the
            // break goes, whatever it introduced still plays.
            if ($hb !== null) $breaks->cancel((int) $hb['id']);
            $this->dropItem($item);
            return null;
        }

        // A break that names the next song must be followed by that song.
        $announcedNext = (string) ($hb['context']['next_uid'] ?? '');
        if ($announcedNext !== '') {
            $next = $this->app->timeline()->after((int) $channel['id'], $item['seq']);
            if ($next === null || $next['uid'] !== $announcedNext) {
                $breaks->cancel((int) $hb['id']);
                $this->dropItem($item);
                return null;
            }
        }
        return $this->commitItem($item, $frontier, $breaks->airDuration($hb), $breaks->payload($hb));
    }

    /** @param array<string,mixed> $item @param array<string,mixed> $payload */
    private function commitItem(array $item, int $start, int $dur, array $payload): int
    {
        $this->app->timeline()->commit($item['id'], $start, $dur, $payload);
        if ($item['type'] === 'song' && $item['library_id'] !== null) {
            $this->app->library()->recordPlay($item['library_id'], $start);
        }
        if ($item['submission_id'] !== null) {
            $this->app->submissions()->markScheduled($item['submission_id'], $start);
        }
        return $start + $dur;
    }

    /** @param array<string,mixed> $item */
    private function dropItem(array $item): void
    {
        $this->app->timeline()->drop($item['id']);
        if ($item['submission_id'] !== null && $item['type'] !== 'host') {
            // The submission's own item (not its announcement) went: give it back.
            $this->app->submissions()->requeue($item['submission_id']);
        }
    }

    private function seqBeforeDrafts(int $channelId): float
    {
        $first = $this->app->timeline()->drafts($channelId, 1)[0] ?? null;
        if ($first === null) {
            $max = $this->app->store()->value('SELECT MAX(seq) FROM timeline_items WHERE channel_id = ?', [$channelId]);
            return $max === null ? 1.0 : floor((float) $max) + 1.0;
        }
        $prev = $this->app->timeline()->before($channelId, $first['seq']);
        return $prev === null ? $first['seq'] - 1.0 : ($prev['seq'] + $first['seq']) / 2;
    }

    /** Refresh the drafts' estimated starts from where the timeline now ends. */
    private function reestimate(int $channelId, int $frontier): void
    {
        $t = $frontier;
        foreach ($this->app->timeline()->drafts($channelId) as $d) {
            if ($d['est_start'] !== $t) {
                $this->app->store()->update('timeline_items', ['est_start' => $t], 'id = ?', [$d['id']]);
            }
            $t += $d['dur_ms'];
        }
    }
}

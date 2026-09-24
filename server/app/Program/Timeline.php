<?php
declare(strict_types=1);

namespace Arche\Program;

use Arche\App;
use Arche\Support\Ids;

/**
 * timeline_items: one row per program item, in three states.
 *
 *   draft      planned, start only estimated, may still be dropped
 *   committed  fixed; start_ms set; published files reference it — immutable
 *   dropped    was drafted, never aired (late host break, stale program)
 *
 * `seq` orders the whole sequence (committed then drafts). Commit never
 * renumbers, so "the item before this one" means the same thing to the host
 * script written ten minutes ago and to the committer now.
 */
final class Timeline
{
    public function __construct(private App $app) {}

    /** @param array<string,mixed> $row @return array<string,mixed> */
    public static function decode(array $row): array
    {
        $p = json_decode((string) $row['payload'], true);
        $row['payload'] = is_array($p) ? $p : [];
        foreach (['id', 'channel_id', 'est_start', 'dur_ms', 'block_start', 'block_end'] as $k) $row[$k] = (int) $row[$k];
        foreach (['program_id', 'start_ms', 'library_id', 'submission_id', 'host_break_id'] as $k) {
            $row[$k] = $row[$k] === null ? null : (int) $row[$k];
        }
        $row['seq'] = (float) $row['seq'];
        return $row;
    }

    /** @return array<string,mixed>|null */
    public function tail(int $channelId): ?array
    {
        $row = $this->app->store()->one(
            "SELECT * FROM timeline_items WHERE channel_id = ? AND state != 'dropped' ORDER BY seq DESC LIMIT 1",
            [$channelId],
        );
        return $row ? self::decode($row) : null;
    }

    /** @return array<string,mixed>|null */
    public function draftTail(int $channelId): ?array
    {
        $row = $this->app->store()->one(
            "SELECT * FROM timeline_items WHERE channel_id = ? AND state = 'draft' ORDER BY seq DESC LIMIT 1",
            [$channelId],
        );
        return $row ? self::decode($row) : null;
    }

    /** @return list<array<string,mixed>> drafts in air order */
    public function drafts(int $channelId, int $limit = 200): array
    {
        return array_map([self::class, 'decode'], $this->app->store()->all(
            "SELECT * FROM timeline_items WHERE channel_id = ? AND state = 'draft' ORDER BY seq LIMIT ?",
            [$channelId, $limit],
        ));
    }

    /** @return list<array<string,mixed>> the last $n non-dropped items, newest first */
    public function recent(int $channelId, int $n = 20): array
    {
        return array_map([self::class, 'decode'], $this->app->store()->all(
            "SELECT * FROM timeline_items WHERE channel_id = ? AND state != 'dropped' ORDER BY seq DESC LIMIT ?",
            [$channelId, $n],
        ));
    }

    /** @return array<string,mixed>|null */
    public function get(int $id): ?array
    {
        $row = $this->app->store()->one('SELECT * FROM timeline_items WHERE id = ?', [$id]);
        return $row ? self::decode($row) : null;
    }

    /** @return array<string,mixed>|null */
    public function byUid(string $uid): ?array
    {
        $row = $this->app->store()->one('SELECT * FROM timeline_items WHERE uid = ?', [$uid]);
        return $row ? self::decode($row) : null;
    }

    /** @return array<string,mixed>|null the item right after $seq in the sequence */
    public function after(int $channelId, float $seq): ?array
    {
        $row = $this->app->store()->one(
            "SELECT * FROM timeline_items WHERE channel_id = ? AND state != 'dropped' AND seq > ? ORDER BY seq LIMIT 1",
            [$channelId, $seq],
        );
        return $row ? self::decode($row) : null;
    }

    /** @return array<string,mixed>|null the item right before $seq */
    public function before(int $channelId, float $seq): ?array
    {
        $row = $this->app->store()->one(
            "SELECT * FROM timeline_items WHERE channel_id = ? AND state != 'dropped' AND seq < ? ORDER BY seq DESC LIMIT 1",
            [$channelId, $seq],
        );
        return $row ? self::decode($row) : null;
    }

    /**
     * Append a draft (or, with $seq, place one between two existing items).
     *
     * @param array<string,mixed> $item type, dur_ms, est_start, program_id, block_start, block_end, payload, …
     * @return array<string,mixed>
     */
    public function addDraft(int $channelId, array $item, ?float $seq = null): array
    {
        if ($seq === null) {
            $max = $this->app->store()->value('SELECT MAX(seq) FROM timeline_items WHERE channel_id = ?', [$channelId]);
            $seq = $max === null ? 1.0 : floor((float) $max) + 1.0;
        }
        $row = [
            'uid' => 'i' . Ids::short(9),
            'channel_id' => $channelId,
            'program_id' => $item['program_id'] ?? null,
            'state' => 'draft',
            'seq' => $seq,
            'est_start' => (int) $item['est_start'],
            'start_ms' => null,
            'dur_ms' => max(1000, (int) $item['dur_ms']),
            'type' => (string) $item['type'],
            'unit' => $item['unit'] ?? null,
            'block_start' => (int) $item['block_start'],
            'block_end' => (int) $item['block_end'],
            'library_id' => $item['library_id'] ?? null,
            'submission_id' => $item['submission_id'] ?? null,
            'host_break_id' => $item['host_break_id'] ?? null,
            'payload' => json_encode($item['payload'] ?? new \stdClass(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'created' => $this->app->clock->now(),
        ];
        $row['id'] = $this->app->store()->insert('timeline_items', $row);
        return self::decode($row);
    }

    /** @param array<string,mixed>|null $payload replaces the payload when given */
    public function commit(int $id, int $startMs, int $durMs, ?array $payload = null): void
    {
        $set = ['state' => 'committed', 'start_ms' => $startMs, 'dur_ms' => $durMs];
        if ($payload !== null) $set['payload'] = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->app->store()->update('timeline_items', $set, "id = ? AND state = 'draft'", [$id]);
    }

    public function drop(int $id): void
    {
        $this->app->store()->update('timeline_items', ['state' => 'dropped'], "id = ? AND state = 'draft'", [$id]);
    }

    /** Move a draft (and its unit) behind everything else — "delay the unit". */
    public function moveToEnd(int $id): void
    {
        $max = (float) $this->app->store()->value('SELECT MAX(seq) FROM timeline_items WHERE channel_id = (SELECT channel_id FROM timeline_items WHERE id = ?)', [$id]);
        $this->app->store()->update('timeline_items', ['seq' => floor($max) + 1.0], "id = ? AND state = 'draft'", [$id]);
    }

    /**
     * Committed items overlapping [from, to), in air order — the body of a
     * minute file.
     *
     * @return list<array<string,mixed>>
     */
    public function committedOverlapping(int $channelId, int $from, int $to): array
    {
        return array_map([self::class, 'decode'], $this->app->store()->all(
            "SELECT * FROM timeline_items WHERE channel_id = ? AND state = 'committed' AND start_ms < ? AND start_ms + dur_ms > ?
             ORDER BY start_ms",
            [$channelId, $to, $from],
        ));
    }

    /** Library ids scheduled (drafted or committed) since $sinceMs. @return array<int,true> */
    public function recentLibraryIds(int $channelId, int $sinceMs): array
    {
        $out = [];
        foreach ($this->app->store()->all(
            "SELECT library_id FROM timeline_items WHERE channel_id = ? AND state != 'dropped' AND library_id IS NOT NULL
             AND COALESCE(start_ms, est_start) >= ?",
            [$channelId, $sinceMs],
        ) as $r) {
            $out[(int) $r['library_id']] = true;
        }
        return $out;
    }

    /** Lower-cased artists scheduled since $sinceMs. @return array<string,true> */
    public function recentArtists(int $channelId, int $sinceMs): array
    {
        $out = [];
        foreach ($this->app->store()->all(
            "SELECT payload FROM timeline_items WHERE channel_id = ? AND state != 'dropped' AND type = 'song'
             AND COALESCE(start_ms, est_start) >= ?",
            [$channelId, $sinceMs],
        ) as $r) {
            $artist = strtolower(trim((string) (json_decode((string) $r['payload'], true)['artist'] ?? '')));
            if ($artist !== '') $out[$artist] = true;
        }
        return $out;
    }

    /**
     * Forget every draft (the plan changed under them). Their host breaks are
     * cancelled so no more money is spent writing scripts nobody will hear.
     */
    public function discardDrafts(int $channelId): int
    {
        $store = $this->app->store();
        return $store->tx(function () use ($store, $channelId) {
            $breaks = $store->all("SELECT host_break_id FROM timeline_items WHERE channel_id = ? AND state = 'draft' AND host_break_id IS NOT NULL", [$channelId]);
            foreach ($breaks as $b) $this->app->hostBreaks()->cancel((int) $b['host_break_id']);
            // Submissions return to the queue so they are drafted again.
            $store->query(
                "UPDATE submissions SET status = 'approved' WHERE status = 'scheduled' AND id IN
                 (SELECT submission_id FROM timeline_items WHERE channel_id = ? AND state = 'draft' AND submission_id IS NOT NULL)",
                [$channelId],
            );
            return $store->query("UPDATE timeline_items SET state = 'dropped' WHERE channel_id = ? AND state = 'draft'", [$channelId])->rowCount();
        });
    }

    /**
     * A song pulled from air leaves the drafts — and with it the announcement
     * of a listener's request for it (a unit is never split), or the host
     * would announce a song that does not come. That request cannot air any
     * more. Committed airings are blocked through live.json instead.
     *
     * @return int drafts dropped
     */
    public function dropDraftsOf(int $libraryId): int
    {
        $store = $this->app->store();
        return $store->tx(function () use ($store, $libraryId): int {
            $drafts = $store->all("SELECT id, unit, submission_id FROM timeline_items WHERE library_id = ? AND state = 'draft'", [$libraryId]);
            $n = 0;
            foreach ($drafts as $d) {
                $ids = $d['unit'] === null ? [(int) $d['id']]
                    : array_map('intval', array_column($store->all("SELECT id FROM timeline_items WHERE unit = ? AND state = 'draft'", [$d['unit']]), 'id'));
                foreach ($ids as $id) {
                    $hb = $store->value('SELECT host_break_id FROM timeline_items WHERE id = ?', [$id]);
                    if ($hb !== null) $this->app->hostBreaks()->cancel((int) $hb);
                    $n += $store->update('timeline_items', ['state' => 'dropped'], "id = ? AND state = 'draft'", [$id]);
                }
                if ($d['submission_id'] !== null) {
                    $store->update('submissions', ['status' => 'missed', 'updated' => $this->app->clock->now()], "id = ? AND status = 'scheduled'", [(int) $d['submission_id']]);
                }
            }
            return $n;
        });
    }

    /** @return list<array<string,mixed>> songs and contributions that started in [from, to) */
    public function played(int $channelId, int $from, int $to): array
    {
        return array_map([self::class, 'decode'], $this->app->store()->all(
            "SELECT * FROM timeline_items WHERE channel_id = ? AND state = 'committed' AND type IN ('song', 'contrib')
             AND start_ms >= ? AND start_ms < ? ORDER BY start_ms",
            [$channelId, $from, $to],
        ));
    }

    public function purgeBefore(int $ms): int
    {
        return $this->app->store()->query(
            "DELETE FROM timeline_items WHERE COALESCE(start_ms, est_start) < ? AND state != 'draft'",
            [$ms],
        )->rowCount();
    }
}

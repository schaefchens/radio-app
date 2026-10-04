<?php
declare(strict_types=1);

namespace Arche\Program;

use Arche\App;
use Arche\Host\HostBreaks;
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
            foreach ($breaks as $b) {
                $this->app->hostBreaks()->cancel((int) $b['host_break_id']);
                // A prayer break's requests wait again, voiced or not.
                $hb = $this->app->hostBreaks()->get((int) $b['host_break_id']);
                foreach ($hb !== null ? HostBreaks::prayerIds($hb) : [] as $id) $this->app->submissions()->requeue($id);
            }
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

    /**
     * A request taken off the prayer wall: the planned moments that would take
     * it up again from the wall go. The committed ones (the next five
     * minutes) cannot; the plan fills the rest again.
     *
     * @return int drafts dropped
     */
    public function dropRepeatsOf(int $submissionId): int
    {
        $store = $this->app->store();
        $n = 0;
        foreach ($store->all(
            "SELECT t.id, t.host_break_id FROM timeline_items t JOIN host_breaks h ON h.id = t.host_break_id
             WHERE t.state = 'draft' AND h.kind = 'prayer' AND json_extract(h.context, '$.again_id') = ?",
            [$submissionId],
        ) as $d) {
            $this->app->hostBreaks()->cancel((int) $d['host_break_id']);
            $n += $store->update('timeline_items', ['state' => 'dropped'], "id = ? AND state = 'draft'", [(int) $d['id']]);
        }
        return $n;
    }

    /**
     * An account deleted by its owner (Identity\Erasure): what it handed in
     * leaves the program. Its drafts go, with the rest of their unit (a unit
     * is never split); a shared prayer break gives the other requests back.
     * Its committed items that have not finished are blocked through
     * live.json, as pull from air does. Every payload of its — committed,
     * drafted or dropped earlier: rows are kept a month — loses the name,
     * place and recording, so the minute files written from now on no longer
     * carry them; files already written stay as they are (immutable — kept
     * 48 hours). The rows keep no reference to the deleted submissions:
     * SQLite reuses row ids, and a stale one would one day point at someone
     * else's request. Runs under the publish lock.
     *
     * @param list<int> $subIds the account's submissions
     * @param list<int> $breakIds host breaks that name them: its own, and committed ones of others that react to it
     * @return array{dropped:int,blocked:int,breaks:list<int>} breaks: those of the items withdrawn
     */
    public function withdraw(array $subIds, array $breakIds): array
    {
        $store = $this->app->store();
        $now = $this->app->clock->nowMs();
        $S = $subIds ? implode(',', array_map('intval', $subIds)) : '0';
        $H = $breakIds ? implode(',', array_map('intval', $breakIds)) : '0';
        return $store->tx(function () use ($store, $now, $S, $H, $subIds): array {
            $units = array_values(array_filter(array_column($store->all(
                "SELECT DISTINCT unit FROM timeline_items WHERE submission_id IN ($S) AND unit IS NOT NULL",
            ), 'unit')));
            $inUnits = $units ? ' OR unit IN (' . implode(',', array_fill(0, count($units), '?')) . ')' : '';
            $rows = array_map([self::class, 'decode'], $store->all(
                "SELECT * FROM timeline_items WHERE submission_id IN ($S) OR host_break_id IN ($H)$inUnits",
                $units,
            ));
            $dropped = 0;
            $blocked = 0;
            $breaks = [];
            foreach ($rows as $it) {
                $breaks[] = $it['host_break_id'];
                if ($it['state'] === 'draft') {
                    $dropped += $store->update('timeline_items', ['state' => 'dropped'], "id = ? AND state = 'draft'", [$it['id']]);
                    $this->giveBackOthers($it['host_break_id'], $subIds, null);
                } elseif ($it['state'] === 'committed' && (int) $it['start_ms'] + $it['dur_ms'] > $now) {
                    $blocked += $store->update('timeline_items', ['blocked' => 1], 'id = ?', [$it['id']]);
                    // Prayed for at that airing no more: the others wait again.
                    $this->giveBackOthers($it['host_break_id'], $subIds, (int) $it['start_ms']);
                }
                $store->update('timeline_items', ['payload' => json_encode(self::scrubbed($it['payload'], $it['type']), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)], 'id = ?', [$it['id']]);
            }
            $store->query("UPDATE timeline_items SET submission_id = NULL WHERE submission_id IN ($S)");
            return ['dropped' => $dropped, 'blocked' => $blocked, 'breaks' => array_values(array_unique(array_filter($breaks, fn($b) => $b !== null)))];
        });
    }

    /**
     * A deleted account's recording kept for replays (library kind contrib)
     * leaves the program like its own items: drafts dropped, committed ones
     * not yet over blocked, every payload without its name, place and audio,
     * and no row pointing at the library item any more. Under the publish lock.
     */
    public function withdrawReplays(int $libraryId): void
    {
        $store = $this->app->store();
        $now = $this->app->clock->nowMs();
        $this->dropDraftsOf($libraryId);
        foreach (array_map([self::class, 'decode'], $store->all('SELECT * FROM timeline_items WHERE library_id = ?', [$libraryId])) as $it) {
            if ($it['state'] === 'committed' && (int) $it['start_ms'] + $it['dur_ms'] > $now) $store->update('timeline_items', ['blocked' => 1], 'id = ?', [$it['id']]);
            $store->update('timeline_items', ['payload' => json_encode(self::scrubbed($it['payload'], $it['type']), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)], 'id = ?', [$it['id']]);
        }
        $store->query('UPDATE timeline_items SET library_id = NULL WHERE library_id = ?', [$libraryId]);
    }

    /**
     * A prayer break's other requests, which no longer air with it, go back
     * to the queue — also when it is blocked on air (it started at $startMs).
     *
     * @param list<int> $erased
     */
    private function giveBackOthers(?int $breakId, array $erased, ?int $startMs): void
    {
        $hb = $breakId !== null ? $this->app->hostBreaks()->get($breakId) : null;
        foreach ($hb !== null ? HostBreaks::prayerIds($hb) : [] as $id) {
            if (!in_array($id, $erased, true)) $this->app->submissions()->giveBack($id, $startMs);
        }
    }

    /**
     * A deleted account's community voices leave the host moments that showed
     * them (`voices`, copied into the payload at commit): minute files written
     * from now on no longer carry them.
     *
     * @param list<string> $tags their author's marks (Presence::voiceTag)
     * @param list<string> $uids the voices' ids, for payloads from before the marks
     */
    public function scrubVoices(array $tags, array $uids): int
    {
        if (!$tags && !$uids) return 0;
        $store = $this->app->store();
        // '-' is no mark and no id: an empty list would be no valid IN ().
        $tags = $tags ?: ['-'];
        $uids = $uids ?: ['-'];
        $rows = $store->all(
            "SELECT id, payload FROM timeline_items WHERE type = 'host' AND json_valid(payload) AND EXISTS (
               SELECT 1 FROM json_each(timeline_items.payload, '$.voices') v
               WHERE json_extract(v.value, '$.by') IN (" . implode(',', array_fill(0, count($tags), '?')) . ")
                  OR json_extract(v.value, '$.id') IN (" . implode(',', array_fill(0, count($uids), '?')) . '))',
            [...$tags, ...$uids],
        );
        foreach ($rows as $r) {
            $p = json_decode((string) $r['payload'], true);
            $p['voices'] = array_values(array_filter(
                (array) $p['voices'],
                fn($v) => !in_array((string) ($v['by'] ?? ''), $tags, true) && !in_array((string) ($v['id'] ?? ''), $uids, true),
            ));
            $store->update('timeline_items', ['payload' => json_encode($p, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)], 'id = ?', [(int) $r['id']]);
        }
        return count($rows);
    }

    /**
     * A committed payload without what a deleted account handed in: the
     * request's name and place, a recording and its caption, a host
     * moment's script and clips.
     *
     * @param array<string,mixed> $p
     * @return array<string,mixed>
     */
    private static function scrubbed(array $p, string $type): array
    {
        if ($type === 'song') $p['request'] = null;
        if ($type === 'contrib') {
            $p['name'] = '';
            $p['place'] = '';
            $p['audio'] = '';
            $p['caption'] = new \stdClass();
        }
        if ($type === 'host') {
            $p['text'] = new \stdClass();
            $p['audio'] = new \stdClass();
            $p['prayers'] = [];
        }
        return $p;
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

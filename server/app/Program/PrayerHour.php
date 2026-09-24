<?php
declare(strict_types=1);

namespace Arche\Program;

use Arche\App;
use Arche\Support\Ids;

/**
 * The running order of a program with the prayer format:
 *
 *   intro → opening prayer → invitation → collection time (background music
 *   for N minutes, or N songs, while listeners think and send their prayer
 *   requests) → the host reads the requests and prays for them → silent
 *   prayer (a new request is announced and prayed for; after a few quiet
 *   minutes the host prays one request from the wall again) → outro with a
 *   blessing → songs until the next program, if the program wants them.
 *
 * Like the rest of the Drafter, one step appends the next item. Where the
 * program stands is read from its current run — its items since the last
 * item of another program — not from the block's start, which changes at
 * midnight for a program running across it (PlanResolver::blockAt).
 *
 * A prayer moment takes the requests approved when it is drafted. So from
 * the reading on, the plan reaches only PRAYER_LEAD ahead instead of DRAFT
 * (the step returns null: "later"), and newer requests go on air sooner. A
 * moment a listener waits for is a unit: not voiced in time, it waits behind
 * a minute of silence instead of being dropped (Committer::commitHost).
 */
final class PrayerHour
{
    private const SILENT = ['en' => 'Silent prayer', 'de' => 'Stilles Gebet'];
    private const COLLECT = ['en' => 'What can we pray for?', 'de' => 'Wofür dürfen wir beten?'];
    /** The opening prayer and the invitation belong to the first minutes only. */
    private const OPENING_WITHIN = 5 * 60_000;
    private const INVITE_WITHIN = 8 * 60_000;
    private const FALLBACK_SONG_MS = 240_000;

    /** @var array<int,int> program id → average song length */
    private array $avgSong = [];

    public function __construct(private App $app) {}

    public static function applies(?array $program): bool
    {
        return $program !== null && ($program['settings']['format'] ?? 'music') === 'prayer';
    }

    /**
     * The next item of the running order at $cursor.
     *
     * @param array<string,mixed> $channel
     * @param array<string,mixed> $program decoded, prayer format
     * @param array{start:int,end:int,program_id:int} $block
     * @param array<string,mixed> $base
     * @return array{0:int,1:int}|null items added and the new cursor; null: draft the rest later
     */
    public function step(array $channel, array $program, array $block, int $cursor, array $base, bool $hostOn): ?array
    {
        $drafter = $this->app->drafter();
        $cid = (int) $channel['id'];
        $now = $this->app->clock->nowMs();
        $s = $program['settings']['prayer'];
        $run = $this->run($cid, (int) $program['id']);
        $closeAt = $this->closingAt($channel, $program, $block);
        $end = $this->realEnd($channel, $block);
        $from = $run['from'] ?? $cursor;

        // The closing: the outro at its time, then songs until the next program.
        // One attempt only — dropped later, it is not tried again mid-song.
        if ($run['phase'] >= 5 || $cursor >= $closeAt + 60_000) return $this->music($channel, $program, $cursor, $base, $end);
        if ($cursor >= $closeAt - 5_000) {
            if (!$hostOn || !$program['settings']['host']['outro']) return $this->music($channel, $program, $cursor, $base, $end);
            return $drafter->addHost($channel, $program, 'outro', $cursor, $base, [], self::unit(), Timing::OUTRO_ESTIMATE);
        }

        // The opening: prayer — a moderator's, when one is prepared — then
        // the invitation to send requests.
        if ($run['phase'] < 1 && $cursor < $from + self::OPENING_WITHIN) {
            $prepared = $this->app->preparedPrayers()->next((int) $program['id']);
            if ($prepared !== null && $prepared['mode'] === 'audio') return $this->preparedRecording($cid, $prepared, $cursor, $base);
            if ($hostOn) {
                $context = $prepared === null ? [] : [
                    'prepared_id' => (int) $prepared['id'], 'by' => (string) $prepared['name'],
                    'fixed' => array_filter(['en' => (string) $prepared['text_en'], 'de' => (string) $prepared['text_de']], fn($t) => trim($t) !== ''),
                ];
                return $drafter->addHost($channel, $program, 'opening', $cursor, $base, $context, self::unit(), Timing::PRAYER_ESTIMATE);
            }
        }
        if ($hostOn && $run['phase'] < 2 && $cursor < $from + self::INVITE_WITHIN) {
            return $drafter->addHost($channel, $program, 'invite', $cursor, $base, [], self::unit());
        }

        // The collection time: requests arrive and appear on the wall; nobody reads them yet.
        if ($run['phase'] <= 3 && ($item = $this->collecting($channel, $program, $run, $cursor, $closeAt, $base)) !== null) return $item;

        // From here on, every moment should take the newest requests.
        if ($closeAt - $now > Timing::DRAFT && $cursor >= $now + Timing::PRAYER_LEAD) return null;
        $room = $closeAt - $cursor;

        // The reading, then silent prayer. Each request is prayed for as soon
        // as the plan can take it (about PRAYER_LEAD after its approval, so
        // what came in during the collection time is read over the minutes
        // after it): read from the wall if it came during the collection
        // time, announced as new if later. The first moment opens the time
        // of prayer even when nothing has been approved yet.
        if ($hostOn && $room >= Timing::PRAYER_ESTIMATE) {
            $ids = $this->app->submissions()->takePrayers($channel, $program, Timing::BLOCK_MAX);
            if ($ids !== [] || $run['moments'] === 0) {
                $phase = $ids !== [] && $this->sentAfter($ids, $run['collect_end'] ?? $cursor) ? 'new' : 'read';
                return $drafter->addHost($channel, $program, 'prayer', $cursor, $base,
                    ['phase' => $phase, 'first' => $run['moments'] === 0, 'prayer_ids' => $ids], $ids !== [] ? self::unit() : null, Timing::PRAYER_ESTIMATE);
            }
        }
        if (($unit = $drafter->addRecording($channel, $program, $cursor, $base, $hostOn, $room)) !== null) return $unit;
        if ($hostOn && $room >= Timing::PRAYER_ESTIMATE && $cursor - ($run['last_word'] ?? $from) >= (int) $s['quiet_min'] * 60_000) {
            $again = $this->again($cid, (int) $program['id'], $run);
            return $drafter->addHost($channel, $program, 'prayer', $cursor, $base,
                $again !== null ? ['phase' => 'again', 'again_id' => $again] : ['phase' => 'general'], null, Timing::PRAYER_ESTIMATE);
        }
        return $this->silence($cid, $cursor, $room, $base);
    }

    /**
     * When the host speaks the outro: early enough for the songs after it to
     * end with the program. A program running on past midnight ends later
     * than its block says.
     *
     * @param array<string,mixed> $channel
     * @param array<string,mixed> $program
     * @param array{start:int,end:int,program_id:int} $block
     */
    public function closingAt(array $channel, array $program, array $block): int
    {
        $songs = (int) $program['settings']['prayer']['after_songs'];
        return $this->realEnd($channel, $block) - Timing::OUTRO_ESTIMATE - ($songs > 0 ? $songs * $this->averageSong($channel, $program) : 0);
    }

    /**
     * What a delayed prayer moment waits behind — silence, not a song — or
     * null where the usual filler applies (another format, after the outro).
     *
     * @param array<string,mixed> $channel
     * @param array<string,mixed>|null $program
     * @return array<string,mixed>|null
     */
    public function pause(array $channel, ?array $program, int $gapMs): ?array
    {
        // What has aired decides: the delayed moment may be the outro itself.
        if (!self::applies($program) || $this->run((int) $channel['id'], (int) $program['id'], true)['phase'] >= 5) return null;
        return ['type' => 'silence', 'dur_ms' => max(1000, min($gapMs, Timing::SILENT_CHUNK)), 'payload' => ['label' => self::SILENT]];
    }

    /** How many requests the host prayed for in the program's current run (the outro may say so). */
    public function prayedCount(int $channelId, int $programId): int
    {
        return (int) $this->app->store()->value(
            "SELECT COALESCE(SUM(json_array_length(h.context, '$.prayer_ids')), 0) FROM host_breaks h JOIN timeline_items t ON t.host_break_id = h.id
             WHERE t.channel_id = ? AND t.state != 'dropped' AND t.seq > ? AND h.kind = 'prayer' AND json_type(h.context, '$.prayer_ids') = 'array'",
            [$channelId, $this->app->timeline()->runStart($channelId, $programId)],
        );
    }

    /**
     * Where the program's current run stands:
     *   phase 0 start · 1 opening · 2 invitation · 3 collection · 4 prayer · 5 outro
     *
     * @return array{phase:int,from:?int,collect_ms:int,collect_songs:int,collect_end:?int,moments:int,last_word:?int}
     */
    private function run(int $channelId, int $programId, bool $committedOnly = false): array
    {
        $r = ['phase' => 0, 'from' => null, 'collect_ms' => 0, 'collect_songs' => 0, 'collect_end' => null, 'moments' => 0, 'last_word' => null];
        $rows = $this->app->store()->all(
            "SELECT type, payload, est_start, start_ms, dur_ms FROM timeline_items WHERE channel_id = ? AND seq > ? AND "
            . ($committedOnly ? "state = 'committed'" : "state != 'dropped'") . ' ORDER BY seq',
            [$channelId, $this->app->timeline()->runStart($channelId, $programId)],
        );
        foreach ($rows as $row) {
            $start = (int) ($row['start_ms'] ?? $row['est_start']);
            $r['from'] ??= $start;
            $payload = json_decode((string) $row['payload'], true) ?: [];
            // A moderator's recorded opening prayer is a recording, but the opening.
            $kind = $row['type'] === 'host' ? (string) ($payload['kind'] ?? '') : ($row['type'] === 'contrib' && !empty($payload['opening']) ? 'opening' : '');
            if ($row['type'] === 'host' || $row['type'] === 'contrib') $r['last_word'] = $start + (int) $row['dur_ms'];
            if ($kind === 'opening') {
                $r['phase'] = max($r['phase'], 1);
            } elseif ($kind === 'invite') {
                $r['phase'] = max($r['phase'], 2);
            } elseif ($kind === 'outro') {
                $r['phase'] = 5;
            } elseif ($r['phase'] <= 3 && ($row['type'] === 'bed' || $row['type'] === 'song')) {
                $r['phase'] = 3;
                if ($row['type'] === 'bed') $r['collect_ms'] += (int) $row['dur_ms'];
                else $r['collect_songs']++;
                $r['collect_end'] = $start + (int) $row['dur_ms'];
            } elseif ($r['phase'] < 5 && ($kind === 'prayer' || $row['type'] === 'contrib' || $row['type'] === 'silence')) {
                $r['phase'] = 4;
                if ($kind === 'prayer') $r['moments']++;
            }
        }
        return $r;
    }

    /** Whether every one of these requests was sent after $ms (after the collection time). @param list<int> $ids */
    private function sentAfter(array $ids, int $ms): bool
    {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        return (int) $this->app->store()->value("SELECT MIN(created) FROM submissions WHERE id IN ($marks)", $ids) * 1000 >= $ms;
    }

    /**
     * The next piece of the collection time, or null once it is complete:
     * background music (never longer than its file, so the app never loops
     * it), or songs when the program asks for songs or has no music to play.
     *
     * @param array<string,mixed> $channel
     * @param array<string,mixed> $program
     * @param array<string,mixed> $run
     * @param array<string,mixed> $base
     * @return array{0:int,1:int}|null
     */
    private function collecting(array $channel, array $program, array $run, int $cursor, int $closeAt, array $base): ?array
    {
        $c = $program['settings']['prayer']['collect'];
        $bed = $c['with'] === 'music' && $c['bed_id'] > 0 ? $this->app->library()->get((int) $c['bed_id']) : null;
        $room = $closeAt - $cursor;
        if ($bed !== null && $bed['kind'] === 'bed' && $bed['active']) {
            $left = (int) $c['minutes'] * 60_000 - $run['collect_ms'];
            $dur = min($left, $bed['duration_ms'], $room);
            if ($dur < 5_000) return null;
            $this->app->timeline()->addDraft((int) $channel['id'], $base + [
                'type' => 'bed', 'dur_ms' => $dur, 'est_start' => $cursor, 'library_id' => $bed['id'],
                'payload' => ['audio' => $bed['audio'], 'label' => self::COLLECT],
            ]);
            return [1, $cursor + $dur];
        }
        if ($run['collect_songs'] >= (int) $c['songs'] || $room < Timing::MIN_SONG) return null;
        $song = $this->app->selector()->pick($channel, $program, $cursor, $room);
        return $song !== null ? $this->app->drafter()->addSong($channel, $song, $cursor, $base) : null;
    }

    /**
     * A moderator's recorded opening prayer, as it is: like a listener's
     * recording, marked as the opening (the committer marks it aired).
     *
     * @param array<string,mixed> $prepared
     * @param array<string,mixed> $base
     * @return array{0:int,1:int}
     */
    private function preparedRecording(int $channelId, array $prepared, int $cursor, array $base): array
    {
        $dur = (int) $prepared['audio_ms'] + 400;
        $this->app->timeline()->addDraft($channelId, $base + [
            'type' => 'contrib', 'dur_ms' => $dur, 'est_start' => $cursor,
            'payload' => [
                'kind' => 'prayer', 'audio' => (string) $prepared['audio'], 'caption' => ['en' => 'Opening prayer', 'de' => 'Eröffnungsgebet'],
                'name' => (string) $prepared['name'], 'place' => '', 'opening' => true, 'prepared_id' => (int) $prepared['id'],
            ],
        ]);
        return [1, $cursor + $dur];
    }

    /**
     * Silent prayer, in pieces, ending exactly at the outro.
     *
     * @param array<string,mixed> $base
     * @return array{0:int,1:int}
     */
    private function silence(int $channelId, int $cursor, int $room, array $base): array
    {
        $dur = max(1000, min(Timing::SILENT_CHUNK, $room));
        $this->app->timeline()->addDraft($channelId, $base + [
            'type' => 'silence', 'dur_ms' => $dur, 'est_start' => $cursor, 'payload' => ['label' => self::SILENT],
        ]);
        return [1, $cursor + $dur];
    }

    /**
     * After the outro: songs until the next program, then padding.
     *
     * @param array<string,mixed> $channel
     * @param array<string,mixed> $program
     * @param array<string,mixed> $base
     * @return array{0:int,1:int}
     */
    private function music(array $channel, array $program, int $cursor, array $base, int $end): array
    {
        $drafter = $this->app->drafter();
        $remaining = $end - $cursor;
        $next = $this->app->resolver()->blockAt($channel, $end)['program_id'];
        if ($remaining >= Timing::MIN_SONG && ($song = $this->app->selector()->pick($channel, $program, $cursor, $remaining + Timing::SOFT_OVERRUN)) !== null) {
            return $drafter->addSong($channel, $song, $cursor, $base);
        }
        return $drafter->pad($channel, $cursor, max(1000, $remaining), $base, $next);
    }

    /**
     * A request from the wall to pray for once more: one its sender agreed
     * to show, already prayed for, the least often taken up again this hour.
     *
     * @param array<string,mixed> $run
     */
    private function again(int $channelId, int $programId, array $run): ?int
    {
        $store = $this->app->store();
        $since = $this->app->timeline()->runStart($channelId, $programId);
        $used = [];
        foreach ($store->all(
            "SELECT json_extract(h.context, '$.again_id') AS id FROM host_breaks h JOIN timeline_items t ON t.host_break_id = h.id
             WHERE t.channel_id = ? AND t.state != 'dropped' AND t.seq > ? AND h.kind = 'prayer' AND json_extract(h.context, '$.again_id') IS NOT NULL",
            [$channelId, $since],
        ) as $r) $used[(int) $r['id']] = ($used[(int) $r['id']] ?? 0) + 1;

        $rows = $store->all(
            "SELECT id FROM submissions WHERE channel_id = ? AND program_id = ? AND type = 'prayer' AND mode = 'text' AND consent_air = 1
             AND status IN ('scheduled', 'aired') AND aired_at IS NOT NULL AND aired_at <= ? AND created >= ? ORDER BY aired_at, id",
            [$channelId, $programId, $this->app->clock->nowMs(), intdiv((int) ($run['from'] ?? 0), 1000) - 3600],
        );
        $best = null;
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            if ($best === null || ($used[$id] ?? 0) < ($used[$best] ?? 0)) $best = $id;
        }
        return $best;
    }

    /** @param array{start:int,end:int,program_id:int} $block */
    private function realEnd(array $channel, array $block): int
    {
        $end = $block['end'];
        // A program running across midnight goes on in the next day's first block.
        for ($i = 0; $i < 2; $i++) {
            $next = $this->app->resolver()->blockAt($channel, $end);
            if ($next['program_id'] !== $block['program_id'] || $next['end'] <= $end) break;
            $end = $next['end'];
        }
        return $end;
    }

    /** @param array<string,mixed> $channel @param array<string,mixed> $program */
    private function averageSong(array $channel, array $program): int
    {
        $pid = (int) $program['id'];
        if (!isset($this->avgSong[$pid])) {
            $songs = $this->app->library()->candidates((int) $channel['id'], $pid, 30 * 60_000);
            $this->avgSong[$pid] = $songs ? (int) (array_sum(array_column($songs, 'duration_ms')) / count($songs)) : self::FALLBACK_SONG_MS;
        }
        return $this->avgSong[$pid];
    }

    /**
     * A unit id for a moment the running order needs: not voiced when its
     * time comes, the committer lets a minute of silence go first instead of
     * dropping it. A plan changed at the last minute drafts the welcome, the
     * opening prayer and the invitation right at the committed edge — as
     * plain breaks all three went and the hour began with its music.
     */
    public static function unit(): string
    {
        return 'u' . Ids::short(8);
    }
}

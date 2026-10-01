<?php
declare(strict_types=1);

namespace Arche\Program;

use Arche\App;
use Arche\Host\HostBreaks;
use Arche\Support\Ids;

/**
 * The running order of a program with the prayer format — the prayer hour:
 *
 *   welcome → opening prayer → invitation → the collection (prayer music for
 *   N minutes, or N songs, while listeners send requests; they appear on the
 *   wall only) → the prayer time (a moment for what was approved since the
 *   last one, a pause of silence after each; a recorded request is played; after a few
 *   quiet minutes one request from the wall again, or a prayer for everyone;
 *   otherwise silence) → the outro with a blessing at C → songs until the
 *   next program, if it asks for them.
 *
 * Like the rest of the Drafter, one step appends the next item. Where the
 * hour stands is read from its own items (state()), never from a block's
 * start: blockAt() begins a new block at midnight. A moment its gate or its
 * time refused counts as tried, so it is not drafted again and again.
 *
 * In the prayer time the plan reaches only PRAYER_LEAD ahead (the step
 * returns null: "later"), so a moment takes the requests approved up to
 * about seven minutes before it airs. Every moment a listener waits for is a
 * unit: not voiced in time, it waits behind silence (filler()) instead of
 * going, and if it waits too long its requests go back to the queue.
 */
final class PrayerHour
{
    public const COLLECT = ['en' => 'What can we pray for?', 'de' => 'Wofür dürfen wir beten?'];
    public const SILENT = ['en' => 'Silent prayer', 'de' => 'Stilles Gebet'];
    private const WAIT = ['en' => 'Stay with us', 'de' => 'Bleib dran'];

    public function __construct(private App $app) {}

    /** @param array<string,mixed>|null $program decoded */
    public static function applies(?array $program): bool
    {
        return $program !== null && ($program['settings']['format'] ?? 'music') === 'prayer';
    }

    /**
     * When the outro begins (C): early enough for the songs after it, and for
     * itself, before the program's real end — the end of its run, which goes
     * on across midnight. An estimate per song: C follows from the plan
     * alone, so the intake times published in minute files never move.
     *
     * @param array<string,mixed> $channel
     * @param array<string,mixed> $program
     */
    public function closingAt(array $channel, array $program, int $t): int
    {
        $run = $this->app->resolver()->runAt($channel, $t);
        return $run['end'] - (int) $program['settings']['prayer']['after_songs'] * Timing::AFTER_SONG - Timing::OUTRO_ESTIMATE;
    }

    /**
     * The next item of the running order at $cursor. Every result moves the
     * cursor at least MIN_CHUNK (or to the outro), so the Drafter's and the
     * Committer's loop guards are never reached.
     *
     * @param array<string,mixed> $channel
     * @param array<string,mixed> $program decoded, prayer format
     * @return array{0:int,1:int}|null items added and the new cursor; null: plan the rest later
     */
    public function step(array $channel, array $program, int $cursor): ?array
    {
        $block = $this->app->resolver()->blockAt($channel, $cursor);
        $base = ['program_id' => (int) $program['id'], 'block_start' => $block['start'], 'block_end' => $block['end']];
        try {
            $run = $this->app->resolver()->runAt($channel, $cursor);
            // The run's bounds, not the block's: an item after midnight still
            // belongs to the hour that began before it.
            $base = ['program_id' => (int) $program['id'], 'block_start' => $run['start'], 'block_end' => $run['end']];
            return $this->next($channel, $program, $run, $base, $cursor);
        } catch (\Throwable $e) {
            // One broken step must not take the channel off air: a minute of
            // silence now, and the next step tries again.
            $this->app->store()->audit('generator', 'Prayer hour step failed', $e::class . ': ' . $e->getMessage());
            return $this->silence($channel, $base, $cursor, Timing::SILENT_CHUNK, self::SILENT);
        }
    }

    /**
     * @param array<string,mixed> $channel
     * @param array<string,mixed> $program
     * @param array{start:int,end:int,program_id:int} $run
     * @param array<string,mixed> $base
     * @return array{0:int,1:int}|null
     */
    private function next(array $channel, array $program, array $run, array $base, int $cursor): ?array
    {
        $s = $program['settings'];
        $drafter = $this->app->drafter();
        $close = $run['end'] - (int) $s['prayer']['after_songs'] * Timing::AFTER_SONG - Timing::OUTRO_ESTIMATE;
        $hostOn = $s['host']['enabled'] && $this->app->hostBreaks()->available();
        $st = $this->state((int) $channel['id'], (int) $program['id']);

        // 1. The closing: the outro at its time, then music until the next program.
        if ($st['outro'] || $cursor >= $close + Timing::OUTRO_LATE) return $this->after($channel, $program, $run, $base, $cursor);
        if ($cursor >= $close - 5_000) {
            if (!$hostOn || !$s['host']['outro']) return $this->after($channel, $program, $run, $base, $cursor);
            return $drafter->addHost($channel, $program, 'outro', $cursor, $base, [], self::unit(), Timing::OUTRO_ESTIMATE);
        }

        // 2. The opening: the welcome, the opening prayer, the invitation —
        //    each once, in the hour's first minutes, counted from its first
        //    item (an outage, or a plan changed at the last minute, moves the
        //    start). All three wait for their voice rather than go.
        if (!$st['collecting'] && $st['moments'] === 0) {
            if ($st['empty'] && $hostOn && $s['host']['intro']) {
                return $drafter->addHost($channel, $program, 'intro', $cursor, $base, [], self::unit());
            }
            $from = $st['from'] ?? $cursor;
            if (!$st['opening'] && !$st['invite'] && $cursor < $from + Timing::OPENING_WITHIN && $close - $cursor >= Timing::MIN_PRAYER + 120_000) {
                $opening = $this->opening($channel, $program, $base, $cursor, $hostOn);
                if ($opening !== null) return $opening;
            }
            if (!$st['invite'] && $hostOn && $cursor < $from + Timing::INVITE_WITHIN && $this->intakeOpen($channel, $program, $run, $cursor)) {
                return $drafter->addHost($channel, $program, 'invite', $cursor, $base, [], self::unit());
            }
        }

        // 3. The collection: requests come in and appear on the wall; nobody reads them yet.
        if ($st['moments'] === 0 && ($item = $this->collect($channel, $program, $st, $base, $cursor, $close)) !== null) return $item;

        // 4. The prayer time, planned late, so each moment takes the newest requests.
        if ($cursor >= $this->app->clock->nowMs() + Timing::PRAYER_LEAD) return null;
        $room = $close - $cursor;
        if ($room < Timing::MIN_CHUNK) return $this->silence($channel, $base, $cursor, $room, self::SILENT);
        if ($st['lastMomentEnd'] !== null && $cursor < $st['lastMomentEnd'] + Timing::PRAYER_PAUSE) {
            return $this->silence($channel, $base, $cursor, self::chunk($st['lastMomentEnd'] + Timing::PRAYER_PAUSE - $cursor, $room), self::SILENT);
        }
        if ($hostOn && $room >= Timing::momentEstimate(1) && ($moment = $this->moment($channel, $program, $st, $base, $cursor)) !== null) return $moment;
        if (($recording = $drafter->addRecording($channel, $program, $cursor, $base, $hostOn, $room)) !== null) return $recording;
        $quietMs = (int) $s['prayer']['quiet_min'] * 60_000;
        if ($hostOn && $room >= Timing::momentEstimate(1) && $st['lastWord'] !== null && $cursor - $st['lastWord'] >= $quietMs) {
            $again = $this->again($channel, $program, $run, $st);
            return $drafter->addHost($channel, $program, 'prayer', $cursor, $base,
                $again !== null ? ['phase' => 'again', 'again_id' => $again] : ['phase' => 'general'], null, Timing::momentEstimate(1));
        }
        return $this->silence($channel, $base, $cursor, self::chunk(Timing::SILENT_CHUNK, $room), self::SILENT);
    }

    /**
     * A prayer moment for the typed requests approved since the last one (up
     * to three). The prayer time's first moment opens it even with none yet.
     * Claimed in one transaction with the moment: taken without it, they would
     * never air and never come back.
     *
     * @param array<string,mixed> $st
     * @param array<string,mixed> $base
     * @return array{0:int,1:int}|null
     */
    private function moment(array $channel, array $program, array $st, array $base, int $cursor): ?array
    {
        return $this->app->store()->tx(function () use ($channel, $program, $st, $base, $cursor): ?array {
            $ids = $this->app->submissions()->takePrayers($channel, $program, Timing::BLOCK_MAX);
            if ($ids === [] && $st['moments'] > 0) return null;
            // Sent before the prayer time began: read from the wall; later: new.
            $phase = $ids === [] ? 'open' : ($this->sentBefore($ids, $st['firstMoment'] ?? $cursor) ? 'read' : 'new');
            return $this->app->drafter()->addHost($channel, $program, 'prayer', $cursor, $base,
                ['phase' => $phase, 'first' => $st['moments'] === 0, 'prayer_ids' => $ids], $ids !== [] ? self::unit() : null, Timing::momentEstimate(count($ids)));
        });
    }

    /**
     * The opening prayer: the oldest one a moderator prepared (a recording
     * plays as it is, even without the host; a text is read word for word in
     * the host voice), else the AI host's own.
     *
     * @param array<string,mixed> $base
     * @return array{0:int,1:int}|null
     */
    private function opening(array $channel, array $program, array $base, int $cursor, bool $hostOn): ?array
    {
        $prepared = $this->app->openingPrayers()->next((int) $program['id']);
        if ($prepared !== null && $prepared['mode'] === 'audio') {
            $dur = (int) $prepared['audio_ms'] + 400;
            $this->app->timeline()->addDraft((int) $channel['id'], $base + [
                'type' => 'contrib', 'dur_ms' => $dur, 'est_start' => $cursor,
                'payload' => [
                    'kind' => 'prayer', 'audio' => (string) $prepared['audio'], 'caption' => ['en' => 'Opening prayer', 'de' => 'Eröffnungsgebet'],
                    'name' => (string) $prepared['name'], 'place' => '', 'opening' => true, 'prepared_id' => (int) $prepared['id'],
                ],
            ]);
            return [1, $cursor + $dur];
        }
        if (!$hostOn) return null;
        if ($prepared === null) {
            return $this->app->drafter()->addHost($channel, $program, 'opening', $cursor, $base, [], self::unit(), Timing::OPENING_ESTIMATE);
        }
        $fixed = array_filter(['en' => trim((string) $prepared['text_en']), 'de' => trim((string) $prepared['text_de'])], fn($t) => $t !== '');
        // Planned by the text's length (about 14 characters a second), not a moment's estimate.
        $estimate = max(Timing::OPENING_ESTIMATE, intdiv(max(array_map('mb_strlen', $fixed)) * 1000, 14));
        return $this->app->drafter()->addHost($channel, $program, 'opening', $cursor, $base,
            ['fixed' => $fixed, 'by' => (string) $prepared['name'], 'prepared_id' => (int) $prepared['id']], self::unit(), $estimate);
    }

    /**
     * The next piece of the collection, or null once it is complete: songs
     * (when the program asks for songs), or prayer music going on with the
     * file, or quiet when there is no music to play.
     *
     * @param array<string,mixed> $st
     * @param array<string,mixed> $base
     * @return array{0:int,1:int}|null
     */
    private function collect(array $channel, array $program, array $st, array $base, int $cursor, int $close): ?array
    {
        $c = $program['settings']['prayer']['collect'];
        // However short the hour, the prayer time keeps its minimum.
        $latest = $close - Timing::MIN_PRAYER;
        if ($c['with'] === 'songs') {
            if ($st['collectSongs'] >= (int) $c['songs'] || $latest - $cursor < Timing::MIN_SONG) return null;
            $song = $this->app->selector()->pick($channel, $program, $cursor, $latest - $cursor + Timing::SOFT_OVERRUN);
            return $song !== null ? $this->app->drafter()->addSong($channel, $song, $cursor, $base, null, null, null, ['collect' => true]) : null;
        }
        $until = min(($st['segEnd'] ?? $st['from'] ?? $cursor) + (int) $c['minutes'] * 60_000, $latest);
        $left = $until - $cursor;
        if ($left < Timing::MIN_CHUNK) return null;
        $bed = $this->bed($program);
        if ($bed === null) return $this->silence($channel, $base, $cursor, self::chunk(Timing::SILENT_CHUNK, $left), self::COLLECT, ['collect' => true]);
        $offset = $this->bedOffset((int) $channel['id'], (int) $program['id'], $bed);
        $dur = min($left, (int) $bed['duration_ms'] - $offset);
        $this->app->timeline()->addDraft((int) $channel['id'], $base + [
            'type' => 'bed', 'dur_ms' => $dur, 'est_start' => $cursor, 'library_id' => (int) $bed['id'],
            'payload' => ['audio' => (string) $bed['audio'], 'label' => self::COLLECT, 'offset' => $offset, 'collect' => true],
        ]);
        return [1, $cursor + $dur];
    }

    /**
     * After the outro: songs until the next program, when the program wants
     * them, then the usual padding.
     *
     * @param array{start:int,end:int,program_id:int} $run
     * @param array<string,mixed> $base
     * @return array{0:int,1:int}
     */
    private function after(array $channel, array $program, array $run, array $base, int $cursor): array
    {
        $remaining = $run['end'] - $cursor;
        if ((int) $program['settings']['prayer']['after_songs'] > 0 && $remaining >= Timing::MIN_SONG) {
            $song = $this->app->selector()->pick($channel, $program, $cursor, $remaining + Timing::SOFT_OVERRUN);
            if ($song !== null) return $this->app->drafter()->addSong($channel, $song, $cursor, $base);
        }
        $next = $this->app->resolver()->blockAt($channel, $run['end'])['program_id'];
        return $this->app->drafter()->pad($channel, $cursor, max(1000, $remaining), $base, $next);
    }

    /**
     * What a prayer hour's unit waits behind when its voice is late: never a
     * song — before a late outro one would push the blessing past the end,
     * where the committer drops it. Prayer music (going on with the file)
     * before the opening, silence in the prayer time. In the waiting unit's
     * program and bounds, not blockAt() at the frontier, which is still the
     * previous program while the timeline runs early. Marked, so the run's
     * state ignores it.
     *
     * @param array<string,mixed> $channel
     * @param array<string,mixed> $waiting the delayed unit's draft
     * @return array<string,mixed> the filler's draft
     */
    public function filler(array $channel, array $waiting, int $atMs, int $gapMs, float $seq): array
    {
        $cid = (int) $channel['id'];
        $program = $this->app->catalog()->program((int) $waiting['program_id']) ?? throw new \LogicException('no program for a prayer hour unit');
        $base = ['program_id' => (int) $program['id'], 'block_start' => $waiting['block_start'], 'block_end' => $waiting['block_end'], 'est_start' => $atMs];
        $dur = max(Timing::MIN_CHUNK, min($gapMs, Timing::SILENT_CHUNK));
        $kind = $waiting['type'] === 'host' ? (string) ($waiting['payload']['kind'] ?? '') : '';
        $opening = in_array($kind, ['intro', 'opening', 'invite'], true);
        $bed = $opening ? $this->bed($program) : null;
        if ($bed !== null) {
            $offset = $this->bedOffset($cid, (int) $program['id'], $bed, (float) $waiting['seq']);
            return $this->app->timeline()->addDraft($cid, $base + [
                'type' => 'bed', 'dur_ms' => min($dur, (int) $bed['duration_ms'] - $offset), 'library_id' => (int) $bed['id'],
                'payload' => ['audio' => (string) $bed['audio'], 'label' => ['en' => (string) $program['title_en'], 'de' => (string) $program['title_de']],
                    'offset' => $offset, 'filler' => true],
            ], $seq);
        }
        return $this->app->timeline()->addDraft($cid, $base + [
            'type' => 'silence', 'dur_ms' => $dur, 'payload' => ['label' => $opening ? self::WAIT : self::SILENT, 'filler' => true],
        ], $seq);
    }

    /**
     * Where the program's current run stands, from its items: those after the
     * last item of another program (by seq: times move with every estimate),
     * before $beforeSeq, without gaps and fillers. A host item dropped because
     * its gate refused it or its time came first counts as tried; one a plan
     * change threw away does not — it is planned again.
     *
     * @return array{empty:bool,from:?int,intro:bool,opening:bool,invite:bool,outro:bool,segEnd:?int,collecting:bool,collectSongs:int,moments:int,firstMoment:?int,lastMomentEnd:?int,lastWord:?int,again:array<int,int>}
     */
    public function state(int $channelId, int $programId, ?float $beforeSeq = null): array
    {
        $st = ['empty' => true, 'from' => null, 'intro' => false, 'opening' => false, 'invite' => false, 'outro' => false, 'segEnd' => null,
            'collecting' => false, 'collectSongs' => 0, 'moments' => 0, 'firstMoment' => null, 'lastMomentEnd' => null, 'lastWord' => null, 'again' => []];
        $args = [$channelId, $programId, $this->runStartSeq($channelId, $programId, $beforeSeq)];
        if ($beforeSeq !== null) $args[] = $beforeSeq;
        $rows = $this->app->store()->all(
            "SELECT t.type, t.payload, t.est_start, t.start_ms, t.dur_ms, h.kind AS hb_kind, h.context AS hb_context
             FROM timeline_items t LEFT JOIN host_breaks h ON h.id = t.host_break_id
             WHERE t.channel_id = ? AND t.program_id = ? AND t.seq > ?" . ($beforeSeq !== null ? ' AND t.seq < ?' : '') . "
               AND t.type != 'gap' AND (t.state != 'dropped' OR (t.type = 'host' AND (h.state = 'failed' OR h.source = 'skipped:late')))
             ORDER BY t.seq",
            $args,
        );
        foreach ($rows as $r) {
            $payload = json_decode((string) $r['payload'], true) ?: [];
            if (!empty($payload['filler'])) continue;
            $start = (int) ($r['start_ms'] ?? $r['est_start']);
            $end = $start + (int) $r['dur_ms'];
            $st['empty'] = false;
            $st['from'] = min($st['from'] ?? $start, $start);
            if (!empty($payload['collect'])) {
                $st['collecting'] = true;
                if ($r['type'] === 'song') $st['collectSongs']++;
            }
            $kind = $r['type'] === 'host' ? (string) ($payload['kind'] ?? $r['hb_kind'] ?? '') : '';
            // A moderator's recorded opening prayer is a recording, but the opening.
            if ($r['type'] === 'contrib' && !empty($payload['opening'])) $kind = 'opening';
            if (in_array($kind, ['intro', 'opening', 'invite'], true)) {
                $st[$kind] = true;
                $st['segEnd'] = max($st['segEnd'] ?? $end, $end);
            }
            if ($kind === 'outro') $st['outro'] = true;
            if ($kind === 'prayer') {
                $st['moments']++;
                $st['firstMoment'] = min($st['firstMoment'] ?? $start, $start);
                $again = (json_decode((string) ($r['hb_context'] ?? ''), true) ?: [])['again_id'] ?? null;
                if (is_int($again)) $st['again'][$again] = ($st['again'][$again] ?? 0) + 1;
            }
            if ($kind === 'prayer' || ($r['type'] === 'contrib' && $kind !== 'opening')) $st['lastMomentEnd'] = max($st['lastMomentEnd'] ?? $end, $end);
            if ($r['type'] === 'host' || $r['type'] === 'contrib') $st['lastWord'] = max($st['lastWord'] ?? $end, $end);
        }
        return $st;
    }

    /**
     * The requests the run prayed for before $beforeSeq (the outro thanks for
     * them), each once.
     *
     * @return list<int> submission ids
     */
    public function prayedFor(int $channelId, int $programId, float $beforeSeq): array
    {
        $ids = [];
        foreach ($this->app->store()->all(
            "SELECT h.context FROM timeline_items t JOIN host_breaks h ON h.id = t.host_break_id
             WHERE t.channel_id = ? AND t.program_id = ? AND t.seq > ? AND t.seq < ? AND t.state != 'dropped' AND h.kind = 'prayer'",
            [$channelId, $programId, $this->runStartSeq($channelId, $programId, $beforeSeq), $beforeSeq],
        ) as $r) {
            foreach (HostBreaks::prayerIds(['context' => json_decode((string) $r['context'], true) ?: []]) as $id) $ids[$id] = true;
        }
        return array_keys($ids);
    }

    /**
     * The seq after which the program's current run begins: its last item of
     * another program (gaps aside — an outage does not end the hour), before
     * $beforeSeq when given (the next program's drafts may follow already).
     */
    private function runStartSeq(int $channelId, int $programId, ?float $beforeSeq = null): float
    {
        $args = [$channelId, $programId];
        if ($beforeSeq !== null) $args[] = $beforeSeq;
        return (float) ($this->app->store()->value(
            "SELECT MAX(seq) FROM timeline_items WHERE channel_id = ? AND state != 'dropped' AND type != 'gap' AND (program_id IS NULL OR program_id != ?)"
            . ($beforeSeq !== null ? ' AND seq < ?' : ''),
            $args,
        ) ?? 0);
    }

    /**
     * A request from this hour's wall to pray for once more: already prayed
     * for, still shown, the one taken up least so far (the longest ago first).
     *
     * @param array{start:int,end:int,program_id:int} $run
     * @param array<string,mixed> $st
     */
    private function again(array $channel, array $program, array $run, array $st): ?int
    {
        $rows = $this->app->store()->all(
            "SELECT id FROM submissions WHERE channel_id = ? AND program_id = ? AND type = 'prayer' AND mode = 'text' AND consent_air = 1 AND hidden = 0
             AND status IN ('scheduled', 'aired') AND aired_at IS NOT NULL AND aired_at <= ? AND created >= ? ORDER BY aired_at, id",
            [(int) $channel['id'], (int) $program['id'], $this->app->clock->nowMs(), intdiv($run['start'], 1000)],
        );
        $best = null;
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            if ($best === null || ($st['again'][$id] ?? 0) < ($st['again'][$best] ?? 0)) $best = $id;
        }
        return $best;
    }

    /** @param list<int> $ids */
    private function sentBefore(array $ids, int $ms): bool
    {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        return (int) $this->app->store()->value("SELECT MIN(created) FROM submissions WHERE id IN ($marks)", $ids) * 1000 < $ms;
    }

    /** Whether prayer requests are still taken at $t (the invitation is pointless otherwise). @param array{start:int,end:int,program_id:int} $run */
    private function intakeOpen(array $channel, array $program, array $run, int $t): bool
    {
        $states = SubmissionWindow::states($this->app, $channel, $program, $run, $t);
        return is_array($states) && ($states['prayer'] ?? 'closed') !== 'closed';
    }

    /** The prayer music this program plays, if it can play. @param array<string,mixed> $program @return array<string,mixed>|null */
    private function bed(array $program): ?array
    {
        $c = $program['settings']['prayer']['collect'];
        if ($c['with'] !== 'music' || (int) $c['bed_id'] <= 0) return null;
        $bed = $this->app->library()->get((int) $c['bed_id']);
        return $bed !== null && $bed['kind'] === 'bed' && $bed['active'] && (string) $bed['audio'] !== '' ? $bed : null;
    }

    /**
     * Where in the file the next piece of prayer music goes on: after the
     * run's last piece before $beforeSeq (fillers too), from the top once
     * little of the file is left.
     *
     * @param array<string,mixed> $bed
     */
    private function bedOffset(int $channelId, int $programId, array $bed, ?float $beforeSeq = null): int
    {
        $args = [$channelId, $programId, $this->runStartSeq($channelId, $programId, $beforeSeq)];
        if ($beforeSeq !== null) $args[] = $beforeSeq;
        $row = $this->app->store()->one(
            "SELECT payload, dur_ms FROM timeline_items WHERE channel_id = ? AND program_id = ? AND type = 'bed' AND state != 'dropped' AND seq > ?"
            . ($beforeSeq !== null ? ' AND seq < ?' : '') . ' ORDER BY seq DESC LIMIT 1',
            $args,
        );
        if ($row === null) return 0;
        $payload = json_decode((string) $row['payload'], true) ?: [];
        if (($payload['audio'] ?? '') !== $bed['audio']) return 0;
        $offset = (int) ($payload['offset'] ?? 0) + (int) $row['dur_ms'];
        return (int) $bed['duration_ms'] - $offset < Timing::MIN_CHUNK ? 0 : $offset;
    }

    /**
     * Silence (or the quiet collection), labelled for the stage.
     *
     * @param array<string,mixed> $base
     * @param array<string,string> $label
     * @param array<string,mixed> $extra
     * @return array{0:int,1:int}
     */
    private function silence(array $channel, array $base, int $cursor, int $ms, array $label, array $extra = []): array
    {
        $dur = max(1000, $ms);
        $this->app->timeline()->addDraft((int) $channel['id'], $base + [
            'type' => 'silence', 'dur_ms' => $dur, 'est_start' => $cursor, 'payload' => ['label' => $label] + $extra,
        ]);
        return [1, $cursor + $dur];
    }

    /** $wantMs, at least MIN_CHUNK, never leaving less than that before $roomMs runs out. */
    private static function chunk(int $wantMs, int $roomMs): int
    {
        $d = max(Timing::MIN_CHUNK, min($wantMs, $roomMs));
        return $roomMs - $d < Timing::MIN_CHUNK ? $roomMs : $d;
    }

    /**
     * A unit id for a moment the hour needs: not voiced when its time comes,
     * the committer lets silence (or prayer music) go first instead of
     * dropping it.
     */
    private static function unit(): string
    {
        return 'u' . Ids::short(8);
    }
}

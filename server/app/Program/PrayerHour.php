<?php
declare(strict_types=1);

namespace Arche\Program;

use Arche\App;
use Arche\Host\HostBreaks;
use Arche\Support\Ids;

/**
 * The running order of a program with the prayer format — the prayer hour,
 * where the listeners pray and the host never does:
 *
 *   welcome → a moderator's opening prayer, if one is prepared → the
 *   collection (N songs, then prayer music, while listeners send requests)
 *   → the presentation (Open Doors' request of the day, then the requests
 *   sent so far, each read out word for word, appearing on the wall as it is
 *   read) → the prayer time, announced
 *   by the host (listeners' prayers: a written one read out word for word, a
 *   spoken one played as it is; requests sent since are read too; silence
 *   in between, and after a few quiet minutes a word of encouragement) →
 *   the outro at C → songs until the next program, if it asks for them.
 *
 * Like the rest of the Drafter, one step appends the next item. Where the
 * hour stands is read from its own items (state()), never from a block's
 * start: blockAt() begins a new block at midnight. A moment its gate or its
 * time refused counts as tried, so it is not drafted again and again.
 *
 * From the presentation on the plan reaches only PRAYER_LEAD ahead (the step
 * returns null: "later"), so a reading takes what was approved up to about
 * seven minutes before it airs. Every reading is a unit: not voiced in time,
 * it waits briefly behind a short filler (READING_WAIT), then gives its
 * request back — which is tried twice at most. A step may move the plan on
 * by less than MIN_CHUNK (a short reading, at least 8 s): within the
 * seven minutes ahead that is still far below the Drafter's and the
 * Committer's loop guards.
 */
final class PrayerHour
{
    public const COLLECT = ['en' => 'What can we pray for?', 'de' => 'Wofür dürfen wir beten?'];
    public const PRAY = ['en' => 'Prayer time', 'de' => 'Gebetszeit'];
    private const WAIT = ['en' => 'Stay with us', 'de' => 'Bleib dran'];
    /** A listener's spoken prayer, as the stage names it. */
    public const PRAYER_CAPTION = ['en' => 'Prayer', 'de' => 'Gebet'];
    /** How often a request is tried in one run before it is left to wait (a failing voice must not loop on it). */
    public const MAX_TRIES = 2;

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
        return self::closeOf($program, $this->app->resolver()->runAt($channel, $t));
    }

    /** @param array<string,mixed> $program @param array{start:int,end:int,program_id:int} $run */
    private static function closeOf(array $program, array $run): int
    {
        return $run['end'] - (int) $program['settings']['prayer']['after_songs'] * Timing::AFTER_SONG - Timing::OUTRO_ESTIMATE;
    }

    /**
     * When the prayer time of the run at $t began: the start of its
     * `prayertime` moment, committed — or tried, when its gate or its time
     * refused it (the prayer time began all the same) — or null before.
     * Drafts never count: published minute files (up to LEAD ahead) and the
     * check at submit time (now) must agree, and everything that starts by
     * then is committed — even when the generator has stalled and its drafts
     * carry starts already past.
     *
     * @param array<string,mixed> $channel
     * @param array<string,mixed> $program
     */
    public function prayerTimeFrom(array $channel, array $program, int $t): ?int
    {
        $run = $this->app->resolver()->runAt($channel, $t);
        $at = $this->app->store()->value(
            "SELECT COALESCE(t.start_ms, t.est_start) FROM timeline_items t JOIN host_breaks h ON h.id = t.host_break_id
             WHERE t.channel_id = ? AND t.program_id = ? AND t.block_start = ? AND h.kind = 'prayertime'
               AND (t.state = 'committed' OR (t.state = 'dropped' AND (h.state = 'failed' OR h.source = 'skipped:late')))
             ORDER BY t.seq LIMIT 1",
            [(int) $channel['id'], (int) $program['id'], $run['start']],
        );
        return $at === null ? null : (int) $at;
    }

    /**
     * The next item of the running order at $cursor.
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
            return $this->silence($channel, $base, $cursor, Timing::SILENT_CHUNK, self::PRAY);
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
        $close = self::closeOf($program, $run);
        $hostOn = $s['host']['enabled'] && $this->app->hostBreaks()->available($channel, $program);
        // The hour's own words from recorded lines (Host\Lines) need no voice that speaks now; people's words do.
        $hostFor = fn(string $kind): bool => $hostOn || ($s['host']['enabled'] && $this->app->hostBreaks()->available($channel, $program, $kind));
        $st = $this->state((int) $channel['id'], (int) $program['id']);

        // 1. The closing: the outro at its time, then music until the next program.
        if ($st['outro'] || $cursor >= $close + Timing::OUTRO_LATE) return $this->after($channel, $program, $run, $base, $cursor);
        if ($cursor >= $close - 5_000) {
            if (!$hostFor('outro') || !$s['host']['outro']) return $this->after($channel, $program, $run, $base, $cursor);
            return $drafter->addHost($channel, $program, 'outro', $cursor, $base, [], self::unit(), Timing::OUTRO_ESTIMATE);
        }

        // 2. The opening: the welcome, and a moderator's opening prayer when
        //    one is prepared — each once, in the hour's first minutes, counted
        //    from its first item (an outage, or a plan changed at the last
        //    minute, moves the start). Both wait for their voice rather than go.
        if (!$st['collecting'] && !$st['presenting']) {
            if ($st['empty'] && $hostFor('intro') && $s['host']['intro']) {
                return $drafter->addHost($channel, $program, 'intro', $cursor, $base, [], self::unit());
            }
            $from = $st['from'] ?? $cursor;
            if (!$st['opening'] && $cursor < $from + Timing::OPENING_WITHIN && $close - $cursor >= Timing::MIN_PRAYER + 120_000) {
                $opening = $this->opening($channel, $program, $base, $cursor, $hostOn);
                if ($opening !== null) return $opening;
            }
        }

        // 3. The collection: requests come in; nobody reads them yet.
        if (!$st['presenting'] && ($item = $this->collect($channel, $program, $st, $base, $cursor, $close)) !== null) return $item;

        // 4. From here on planned late, so each reading takes the newest requests.
        $now = $this->app->clock->nowMs();
        if ($cursor >= $now + Timing::PRAYER_LEAD) return null;
        $room = $close - $cursor;
        if ($room < Timing::MIN_CHUNK) return $this->silence($channel, $base, $cursor, $room, self::PRAY);

        // 5. The presentation — the requests sent until it began, word for
        //    word — and the announcement of the prayer time, which always
        //    comes: at the latest early enough to leave time for prayers.
        //    Without the host's voice nothing can be read: straight on.
        if (!$st['prayertime'] && $hostOn) {
            if (!$st['present']) {
                // Open Doors' request of the day, first of all.
                if (!empty($s['prayer']['opendoors']) && ($daily = $this->app->openDoors()->current()) !== null) {
                    $this->app->submissions()->addStationRequest($channel, $program, $run, $daily);
                }
                if ($this->app->submissions()->waitingRequests((int) $channel['id'], (int) $program['id'], $now, $st['tries'], self::MAX_TRIES) > 0) {
                    return $drafter->addHost($channel, $program, 'present', $cursor, $base, ['until' => $now], self::unit());
                }
            } elseif ($cursor < $close - Timing::PRAYER_CLOSING - 120_000) {
                $reading = $this->read($channel, $program, $base, $cursor, $room, $st, $st['until'] ?? $now, true);
                if ($reading !== null) return $reading;
            }
            return $drafter->addHost($channel, $program, 'prayertime', $cursor, $base, [], self::unit());
        }

        // 6. The prayer time: what listeners sent, in the order it came; quiet otherwise.
        if (($item = $this->read($channel, $program, $base, $cursor, $room, $st, null, $hostOn)) !== null) return $item;
        $quietMs = (int) $s['prayer']['quiet_min'] * 60_000;
        if ($hostFor('encourage') && $room >= Timing::HOST_ESTIMATE + Timing::MIN_CHUNK && $st['lastWord'] !== null && $cursor - $st['lastWord'] >= $quietMs) {
            return $drafter->addHost($channel, $program, 'encourage', $cursor, $base);
        }
        return $this->silence($channel, $base, $cursor, self::chunk(Timing::SILENT_CHUNK, $room), self::PRAY);
    }

    /**
     * The next of the listeners' words, claimed in one transaction with its
     * draft (taken without it, they would never air and never come back):
     * the oldest approved typed request — in the presentation only those
     * sent until it began ($until) — or, in the prayer time, a request or a
     * prayer, written or spoken, whichever came first. Text is read out word
     * for word (a unit; only with the host's voice), a recording plays as it
     * is, without a word from the host, with a few seconds of quiet after it.
     *
     * @param array<string,mixed> $st
     * @param array<string,mixed> $base
     * @return array{0:int,1:int}|null null: nothing waits that fits before C
     */
    private function read(array $channel, array $program, array $base, int $cursor, int $room, array $st, ?int $until, bool $voice): ?array
    {
        return $this->app->store()->tx(function () use ($channel, $program, $base, $cursor, $room, $st, $until, $voice): ?array {
            $sub = $this->app->submissions()->takeNext($channel, $program, $until, $st['tries'], self::MAX_TRIES, $room, $voice);
            if ($sub === null) return null;
            if ($sub['mode'] === 'audio') {
                $meta = json_decode((string) $sub['meta'], true) ?: [];
                $dur = (int) $sub['audio_ms'] + Timing::PRAYER_GAP;
                $this->app->timeline()->addDraft((int) $channel['id'], $base + [
                    'type' => 'contrib', 'dur_ms' => $dur, 'est_start' => $cursor, 'submission_id' => (int) $sub['id'],
                    'payload' => [
                        // `prayer` for both: an app that does not know newer kinds knows this one.
                        'kind' => 'prayer',
                        'audio' => (string) $sub['audio'],
                        'caption' => $sub['type'] === 'intercession' ? self::PRAYER_CAPTION
                            : ['en' => (string) ($meta['caption_en'] ?? ''), 'de' => (string) ($meta['caption_de'] ?? '')],
                        'name' => (string) $sub['name'],
                        'place' => (string) $sub['place'],
                    ],
                ]);
                return [1, $cursor + $dur];
            }
            return $this->app->drafter()->addReading($channel, $program, $sub['type'] === 'intercession' ? 'intercession' : 'reading',
                (int) $sub['id'], $cursor, $base, self::unit(), ['phase' => $until !== null ? 'read' : 'new']);
        });
    }

    /**
     * The opening prayer: the oldest one a moderator prepared — a recording
     * plays as it is, even without the host; a text is read word for word in
     * the host voice. None prepared: no opening prayer. The AI host never
     * prays.
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
        if ($prepared === null || !$hostOn) return null;
        $fixed = array_filter(['en' => trim((string) $prepared['text_en']), 'de' => trim((string) $prepared['text_de'])], fn($t) => $t !== '');
        // Planned by the text's length (about 14 characters a second), not a moment's estimate.
        $estimate = max(Timing::OPENING_ESTIMATE, intdiv(max(array_map('mb_strlen', $fixed)) * 1000, 14));
        return $this->app->drafter()->addHost($channel, $program, 'opening', $cursor, $base,
            ['fixed' => $fixed, 'by' => (string) $prepared['name'], 'prepared_id' => (int) $prepared['id']], self::unit(), $estimate);
    }

    /**
     * The next piece of the collection, or null once it is complete: first
     * the songs the program asks for, then prayer music going on with the
     * file (or quiet without music) until it has lasted its minutes.
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
        if ($st['collectSongs'] < (int) $c['songs'] && $latest - $cursor >= Timing::MIN_SONG) {
            $song = $this->app->selector()->pick($channel, $program, $cursor, $latest - $cursor + Timing::SOFT_OVERRUN);
            if ($song !== null) return $this->app->drafter()->addSong($channel, $song, $cursor, $base, null, null, null, ['collect' => true]);
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
     * song — before a late outro one would push it past the end, where the
     * committer drops it. Prayer music (going on with the file) before the
     * prayer time, silence in it — short pieces, so a reading that is ready
     * a little late follows soon. In the waiting unit's program and bounds,
     * not blockAt() at the frontier, which is still the previous program
     * while the timeline runs early. Marked, so the run's state ignores it.
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
        $dur = max(Timing::MIN_CHUNK, min($gapMs, Timing::PRAYER_FILLER));
        $kind = $waiting['type'] === 'host' ? (string) ($waiting['payload']['kind'] ?? '') : '';
        $opening = in_array($kind, ['intro', 'opening'], true);
        $bed = !$this->state($cid, (int) $program['id'], (float) $waiting['seq'])['prayertime'] ? $this->bed($program) : null;
        if ($bed !== null) {
            $offset = $this->bedOffset($cid, (int) $program['id'], $bed, (float) $waiting['seq']);
            return $this->app->timeline()->addDraft($cid, $base + [
                'type' => 'bed', 'dur_ms' => min($dur, (int) $bed['duration_ms'] - $offset), 'library_id' => (int) $bed['id'],
                'payload' => ['audio' => (string) $bed['audio'], 'label' => ['en' => (string) $program['title_en'], 'de' => (string) $program['title_de']],
                    'offset' => $offset, 'filler' => true],
            ], $seq);
        }
        return $this->app->timeline()->addDraft($cid, $base + [
            'type' => 'silence', 'dur_ms' => $dur, 'payload' => ['label' => $opening ? self::WAIT : self::PRAY, 'filler' => true],
        ], $seq);
    }

    /**
     * Where the program's current run stands, from its items: those after the
     * last item of another program (by seq: times move with every estimate),
     * before $beforeSeq, without gaps and fillers. A host item dropped because
     * its gate refused it or its time came first counts as tried; one a plan
     * change threw away does not — it is planned again. `tries` counts, per
     * request, the readings that were tried and did not air.
     *
     * @return array{empty:bool,from:?int,opening:bool,outro:bool,segEnd:?int,collecting:bool,collectSongs:int,presenting:bool,present:bool,until:?int,prayertime:bool,lastWord:?int,tries:array<int,int>}
     */
    public function state(int $channelId, int $programId, ?float $beforeSeq = null): array
    {
        $st = ['empty' => true, 'from' => null, 'opening' => false, 'outro' => false, 'segEnd' => null, 'collecting' => false, 'collectSongs' => 0,
            'presenting' => false, 'present' => false, 'until' => null, 'prayertime' => false, 'lastWord' => null, 'tries' => []];
        $args = [$channelId, $programId, $this->runStartSeq($channelId, $programId, $beforeSeq)];
        if ($beforeSeq !== null) $args[] = $beforeSeq;
        $rows = $this->app->store()->all(
            "SELECT t.type, t.state, t.payload, t.est_start, t.start_ms, t.dur_ms, h.kind AS hb_kind, h.context AS hb_context
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
            $context = json_decode((string) ($r['hb_context'] ?? ''), true) ?: [];
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
                if ($kind === 'opening') $st['opening'] = true;
                $st['segEnd'] = max($st['segEnd'] ?? $end, $end);
            }
            if ($kind === 'outro') $st['outro'] = true;
            if ($kind === 'present') {
                $st['present'] = true;
                $st['until'] = isset($context['until']) ? (int) $context['until'] : $start;
            }
            // An hour that began before this order existed prayed in moments: it is in its prayer time.
            if ($kind === 'prayertime' || $kind === 'prayer') $st['prayertime'] = true;
            if (in_array($kind, ['present', 'prayertime', 'prayer', 'reading', 'intercession'], true) || ($r['type'] === 'contrib' && $kind !== 'opening')) {
                $st['presenting'] = true;
            }
            if ($r['state'] === 'dropped' && in_array($kind, ['reading', 'intercession'], true)) {
                foreach (HostBreaks::prayerIds(['context' => $context]) as $id) $st['tries'][$id] = ($st['tries'][$id] ?? 0) + 1;
            }
            if ($r['type'] === 'host' || $r['type'] === 'contrib') $st['lastWord'] = max($st['lastWord'] ?? $end, $end);
        }
        return $st;
    }

    /**
     * What the run aired before $beforeSeq, for the outro's thanks: the
     * listeners' requests read out or played, their prayers, and how often
     * listeners prayed along with the requests in the app.
     *
     * @return array{requests:int,prayers:int,prayed_along:int}
     */
    public function counts(int $channelId, int $programId, float $beforeSeq): array
    {
        $ids = [];
        foreach ($this->app->store()->all(
            "SELECT t.submission_id, h.context FROM timeline_items t LEFT JOIN host_breaks h ON h.id = t.host_break_id
             WHERE t.channel_id = ? AND t.program_id = ? AND t.seq > ? AND t.seq < ? AND t.state != 'dropped'
               AND (h.kind IN ('reading', 'intercession', 'prayer') OR (t.type = 'contrib' AND t.submission_id IS NOT NULL))",
            [$channelId, $programId, $this->runStartSeq($channelId, $programId, $beforeSeq), $beforeSeq],
        ) as $r) {
            if ($r['submission_id'] !== null) $ids[(int) $r['submission_id']] = true;
            foreach (HostBreaks::prayerIds(['context' => json_decode((string) ($r['context'] ?? ''), true) ?: []]) as $id) $ids[$id] = true;
        }
        $out = ['requests' => 0, 'prayers' => 0, 'prayed_along' => 0];
        if (!$ids) return $out;
        $marks = implode(',', array_fill(0, count($ids), '?'));
        foreach ($this->app->store()->all("SELECT type, meta, prayed_count FROM submissions WHERE id IN ($marks)", array_keys($ids)) as $s) {
            // Open Doors' request is the station's, not a listener's.
            $station = (json_decode((string) $s['meta'], true) ?: [])['source'] ?? null;
            if ($s['type'] === 'intercession') $out['prayers']++;
            elseif ($station === null) $out['requests']++;
            if ($s['type'] === 'prayer') $out['prayed_along'] += (int) $s['prayed_count'];
        }
        return $out;
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

    /** The prayer music this program plays, if it can play. @param array<string,mixed> $program @return array<string,mixed>|null */
    private function bed(array $program): ?array
    {
        $id = (int) $program['settings']['prayer']['collect']['bed_id'];
        if ($id <= 0) return null;
        $bed = $this->app->library()->get($id);
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

<?php
declare(strict_types=1);

namespace Arche\Program;

use Arche\App;
use Arche\Plan\Catalog;
use Arche\Submission\Submissions;

/**
 * Plans the next ~8 minutes of a channel as drafts (Timing::DRAFT). Drafting
 * is local and cheap (no network); the expensive part — host scripts and
 * their voice — is queued as jobs for the drafted host breaks and runs in the
 * gap between drafting and commit.
 *
 * Within a program block, one step appends the next thing the program needs,
 * in this order of precedence:
 *   intro (program changed) → end-of-block outro/padding → (a video
 *   program — preaching, testimony, mission, film: its next video, when one
 *   is due and fits) → the listeners' requests and contributions waiting (one
 *   block) → a prayer break → a moment of silence → a host break every N
 *   songs → a jingle every M songs → a song.
 *
 * A program with the prayer format follows its own running order instead
 * (PrayerHour), and plans its prayer time only PRAYER_LEAD ahead.
 */
final class Drafter
{
    private const RECENT = 40;

    public function __construct(private App $app) {}

    public function draft(array $channel): int
    {
        $cid = (int) $channel['id'];
        $store = $this->app->store();
        $timeline = $this->app->timeline();
        $now = $this->app->clock->nowMs();

        $version = $this->app->catalog()->version();
        $first = $timeline->drafts($cid, 1)[0] ?? null;
        $stale = $first !== null && $first['est_start'] < $now - 600_000;
        if ((int) ($store->get("draft_version:$cid") ?? -1) !== $version || $stale) {
            $timeline->discardDrafts($cid);
            $store->set("draft_version:$cid", $version);
        }
        // Drafted while the library was empty, the plan is placeholders ("The
        // program is being prepared"). Once there are songs, plan again — and
        // let clients skip the placeholders already committed: they play the
        // fallback loop of the new songs until the real program begins.
        // Drafted from a library too small to avoid repeats (a draft song that
        // plays twice within the hour — the Selector allows that only when
        // nothing else is left) and the library has changed since:
        // plan again, so the new songs play before the repeats are committed.
        // A plan without repeats is kept (its host breaks may be voiced already).
        $library = $this->app->library()->fingerprint();
        if ($store->get("draft_library:$cid") !== $library) {
            $repeats = (int) $store->value(
                "SELECT COUNT(*) FROM timeline_items d WHERE d.channel_id = ? AND d.state = 'draft' AND d.type = 'song'
                 AND d.library_id IS NOT NULL AND EXISTS (SELECT 1 FROM timeline_items o WHERE o.channel_id = d.channel_id
                 AND o.id != d.id AND o.state != 'dropped' AND o.library_id = d.library_id AND COALESCE(o.start_ms, o.est_start) >= ?)",
                [$cid, $now - 3_600_000],
            );
            if ($repeats > 0) $timeline->discardDrafts($cid);
            $store->set("draft_library:$cid", $library);
        }
        if ($store->get("waiting:$cid") && $this->app->library()->count('song') > 0) {
            $timeline->discardDrafts($cid);
            $store->query(
                "UPDATE timeline_items SET blocked = 1 WHERE channel_id = ? AND state = 'committed' AND type = 'stage'
                 AND start_ms + dur_ms > ? AND json_extract(payload, '$.waiting') = 1",
                [$cid, $now],
            );
            $store->set("waiting:$cid", null);
        }

        $cursor = $this->cursor($channel, $now);
        $until = $now + Timing::DRAFT;
        $added = 0;
        for ($guard = 0; $cursor < $until && $guard < 80; $guard++) {
            $step = $this->step($channel, $cursor);
            // A prayer hour plans its prayer time only PRAYER_LEAD ahead, to
            // take the newest requests; the commit never waits on that
            // (PRAYER_LEAD > COMMIT).
            if ($step === null) break;
            [$n, $cursor] = $step;
            $added += $n;
        }
        return $added;
    }

    /**
     * Estimated end of the sequence — where the next draft goes: where the
     * committed timeline goes on, plus the drafts waiting to follow it. That
     * is what Committer::reestimate() makes of them. After an outage the
     * committer restarts the timeline at the anchor; the drafts' old
     * estimates lie in the past then, and planning from them would plan the
     * outage over again (a prayer hour would air every new request a quarter
     * of an hour late).
     */
    private function cursor(array $channel, int $now): int
    {
        $cid = (int) $channel['id'];
        $frontier = $this->app->committer()->frontier($cid);
        $from = $frontier === null || $frontier < $now + Timing::REANCHOR_LEAD ? Committer::anchor($now) : $frontier;
        return $from + (int) $this->app->store()->value(
            "SELECT COALESCE(SUM(dur_ms), 0) FROM timeline_items WHERE channel_id = ? AND state = 'draft'",
            [$cid],
        );
    }

    /** @return array{0:int,1:int}|null items added and the new cursor; null: the rest is planned later */
    private function step(array $channel, int $cursor): ?array
    {
        $cid = (int) $channel['id'];
        $block = $this->app->resolver()->blockAt($channel, $cursor);
        $program = $this->app->catalog()->program($block['program_id']);
        if ($program === null) return [0, $block['end']];
        // A prayer hour follows its own running order, its welcome included.
        if (PrayerHour::applies($program)) return $this->app->prayerHour()->step($channel, $program, $cursor);
        $settings = $program['settings'];
        $base = ['program_id' => (int) $program['id'], 'block_start' => $block['start'], 'block_end' => $block['end']];
        $recent = $this->app->timeline()->recent($cid, self::RECENT);
        $prev = $recent[0] ?? null;
        // Only with someone in its lineup who can speak: a request nobody can
        // read out would be taken, fail and be given back over and over.
        $hostOn = $settings['host']['enabled'] && $this->app->hostBreaks()->available($channel, $program);
        // A moment the program takes from recorded lines (Host\Lines) needs no voice that speaks now.
        $hostFor = fn(string $kind): bool => $hostOn || ($settings['host']['enabled'] && $this->app->hostBreaks()->available($channel, $program, $kind));

        // 1. A new program starts: the host opens it.
        if (($prev['program_id'] ?? null) !== (int) $program['id'] && $hostFor('intro') && $settings['host']['intro']
            && !$this->isHost($prev)) {
            return $this->addHost($channel, $program, 'intro', $cursor, $base);
        }

        $remaining = $block['end'] - $cursor;
        $nextProgram = $this->app->resolver()->blockAt($channel, $block['end'])['program_id'];
        $blockEnds = $nextProgram !== (int) $program['id'];

        // 2. Not enough time left for a song: close the block.
        if ($blockEnds && $remaining < Timing::MIN_SONG) {
            if ($hostFor('outro') && $settings['host']['outro'] && $remaining >= 30_000 && !$this->isHost($prev, 'outro')) {
                return $this->addHost($channel, $program, 'outro', $cursor, $base);
            }
            return $this->pad($channel, $cursor, max(1000, $remaining), $base, $nextProgram);
        }

        // 2b. A video program: a video when one is due — first thing, then
        //     after a few songs each time — a listener's suggestion before the
        //     program's own selection. When none fits into the time left,
        //     songs fill it.
        if (Catalog::isVideoFormat($settings['format'])) {
            // Without songs to put between them, one video follows the next.
            // (Every video format's setting lives in the `preaching` group.)
            $between = $this->app->library()->count('song') > 0 ? (int) $settings['preaching']['songs_between'] : 0;
            if (self::videoDue($recent, (int) $program['id'], $between)) {
                // Up to the end of the program's whole run. blockAt() starts a
                // new block at midnight, and "the same program goes on" says
                // nothing about how long: a two-hour film taken at 22:30 ran
                // until 01:00 in a program that ends at 00:30.
                $roomMs = $this->app->resolver()->runAt($channel, $cursor)['end'] - $cursor + Timing::SOFT_OVERRUN;
                $taken = $this->addVideo($channel, $program, (string) $settings['format'], $cursor, $base, $hostOn, $blockEnds, $roomMs, $prev);
                if ($taken !== null) return $taken;
            }
        }

        // 3. Listener requests and contributions waiting for this program: as
        //    soon as they are approved, and together when several wait — but
        //    with regular songs between two blocks, so the program keeps its
        //    own character.
        if ($this->songsSinceRequest($recent) >= Timing::BLOCK_GAP_SONGS) {
            $taken = $this->addBlock($channel, $program, $cursor, $base, $hostOn, $blockEnds);
            if ($taken !== null) return $taken;
        }

        // 4. Text prayer requests: each read out word for word, then the host
        //    invites everyone to pray for them — it never prays itself. Not
        //    a unit: a reading not voiced in time gives its request back for
        //    a later break, and no song comes between a reading and the next.
        //    One transaction, as for a block: claimed without their readings
        //    in the plan, they would never air and never come back.
        if ($hostOn && in_array('prayer', $program['allowed'], true) && !$this->isHost($prev)) {
            $taken = $this->app->store()->tx(function () use ($channel, $program, $cursor, $base): ?array {
                $ids = $this->app->submissions()->takePrayers($channel, $program, Timing::BLOCK_MAX, Timing::READ_CHARS);
                if (!$ids) return null;
                $added = 0;
                foreach ($ids as $id) {
                    [$n, $cursor] = $this->addReading($channel, $program, 'reading', $id, $cursor, $base);
                    $added += $n;
                }
                [$n, $cursor] = $this->addHost($channel, $program, 'prayer', $cursor, $base, ['requests' => count($ids)]);
                return [$added + $n, $cursor];
            });
            if ($taken !== null) return $taken;
        }

        // 5. A moment of silence, when the program asks for them.
        $silenceEvery = (int) $settings['silence']['every_min'];
        if ($silenceEvery > 0 && $this->minutesSince($recent, 'silence', $cursor, $block['start']) >= $silenceEvery) {
            $dur = (int) $settings['silence']['dur_s'] * 1000;
            $this->app->timeline()->addDraft($cid, $base + [
                'type' => 'silence', 'dur_ms' => $dur, 'est_start' => $cursor,
                'payload' => ['label' => ['en' => 'A moment of silence', 'de' => 'Ein Moment der Stille']],
            ]);
            return [1, $cursor + $dur];
        }

        // 6. The host speaks every N songs.
        if ($hostFor('break') && $this->songsSince($recent, 'host') >= (int) $settings['host']['every_songs']) {
            return $this->addHost($channel, $program, 'break', $cursor, $base);
        }

        // 7. A jingle every M songs.
        $jingleEvery = (int) $settings['jingle_every_songs'];
        if ($jingleEvery > 0 && $this->songsSince($recent, 'jingle') >= $jingleEvery) {
            $jingle = $this->app->selector()->jingle(60_000);
            if ($jingle !== null) {
                $this->app->timeline()->addDraft($cid, $base + [
                    'type' => 'jingle', 'dur_ms' => $jingle['duration_ms'], 'est_start' => $cursor,
                    'library_id' => $jingle['id'], 'payload' => ['audio' => $jingle['audio']],
                ]);
                return [1, $cursor + $jingle['duration_ms']];
            }
        }

        // 8. A song from the library.
        $maxMs = $blockEnds ? $remaining + Timing::SOFT_OVERRUN : 30 * 60_000;
        $song = $this->app->selector()->pick($channel, $program, $cursor, $maxMs);
        if ($song !== null) return $this->addSong($channel, $song, $cursor, $base);

        if ($this->app->library()->count('song') === 0) {
            $dur = $blockEnds ? min(Timing::STAGE_ITEM, max(1000, $remaining)) : Timing::STAGE_ITEM;
            $this->app->timeline()->addDraft($cid, $base + [
                'type' => 'stage', 'dur_ms' => $dur, 'est_start' => $cursor,
                'payload' => ['label' => ['en' => 'The program is being prepared', 'de' => 'Das Programm wird vorbereitet'], 'waiting' => true],
            ]);
            $this->app->store()->set("waiting:$cid", true);
            return [1, $cursor + $dur];
        }
        return $this->pad($channel, $cursor, min(Timing::FILLER_SILENCE_MAX, max(1000, $remaining)), $base, $nextProgram);
    }

    /**
     * Something to play right now for $gapMs — used by the committer when an
     * item is not ready (a delayed request) or the timeline runs early.
     *
     * @return array<string,mixed> a draft placed before $beforeSeq
     */
    public function filler(array $channel, int $atMs, int $gapMs, float $beforeSeq, ?array $waiting = null): array
    {
        $cid = (int) $channel['id'];
        $prev = $this->app->timeline()->before($cid, $beforeSeq);
        $seq = $prev === null ? $beforeSeq - 1.0 : ($prev['seq'] + $beforeSeq) / 2;
        // A prayer hour's moment waits behind its own music or silence.
        if ($waiting !== null && PrayerHour::applies($this->app->catalog()->program((int) $waiting['program_id']))) {
            return $this->app->prayerHour()->filler($channel, $waiting, $atMs, $gapMs, $seq);
        }
        $block = $this->app->resolver()->blockAt($channel, $atMs);
        $program = $this->app->catalog()->program($block['program_id']);
        $base = ['program_id' => $block['program_id'], 'block_start' => $block['start'], 'block_end' => $block['end'], 'est_start' => $atMs];

        $song = $program ? $this->app->selector()->pick($channel, $program, $atMs, $gapMs + Timing::SOFT_OVERRUN) : null;
        if ($song !== null) {
            return $this->app->timeline()->addDraft($cid, $base + $this->songItem($song), $seq);
        }
        $jingle = $this->app->selector()->jingle(min($gapMs, 60_000));
        if ($jingle !== null) {
            return $this->app->timeline()->addDraft($cid, $base + [
                'type' => 'jingle', 'dur_ms' => $jingle['duration_ms'], 'library_id' => $jingle['id'],
                'payload' => ['audio' => $jingle['audio']],
            ], $seq);
        }
        return $this->app->timeline()->addDraft($cid, $base + [
            'type' => 'stage', 'dur_ms' => max(1000, min($gapMs, Timing::STAGE_ITEM)),
            'payload' => ['label' => ['en' => 'Stay with us', 'de' => 'Bleib dran']],
        ], $seq);
    }

    /**
     * A host moment; its script and voice are queued as a job. (Also the
     * prayer hour's.)
     *
     * @param array<string,mixed> $base
     * @param array<string,mixed> $context
     * @param ?string $unit set: when its voice is late, the committer lets a
     *   filler go first instead of dropping it
     * @return array{0:int,1:int}
     */
    public function addHost(array $channel, array $program, string $kind, int $cursor, array $base, array $context = [], ?string $unit = null, int $estimate = Timing::HOST_ESTIMATE): array
    {
        $item = $this->app->timeline()->addDraft((int) $channel['id'], $base + [
            'type' => 'host', 'dur_ms' => $estimate, 'est_start' => $cursor, 'unit' => $unit,
            'payload' => ['kind' => $kind],
        ]);
        $breakId = $this->app->hostBreaks()->create($channel, $program, $kind, $item, $context);
        $this->app->store()->update('timeline_items', ['host_break_id' => $breakId], 'id = ?', [$item['id']]);
        return [1, $cursor + $estimate];
    }

    /**
     * A listener's prayer request (`reading`) or prayer (`intercession`), to
     * be read out word for word (HostWriter::reading()) — planned by its
     * length. `n` is its place among the channel's readings, so the lead-in
     * changes from one to the next.
     *
     * @param array<string,mixed> $base
     * @param array<string,mixed> $context more context (a prayer hour's phase)
     * @return array{0:int,1:int}
     */
    public function addReading(array $channel, array $program, string $kind, int $submissionId, int $cursor, array $base, ?string $unit = null, array $context = []): array
    {
        $text = (string) ($this->app->submissions()->get($submissionId)['text'] ?? '');
        $n = (int) $this->app->store()->value(
            "SELECT COUNT(*) FROM host_breaks WHERE channel_id = ? AND kind IN ('reading', 'intercession')",
            [(int) $channel['id']],
        );
        return $this->addHost($channel, $program, $kind, $cursor, $base, ['prayer_ids' => [$submissionId], 'n' => $n] + $context,
            $unit, Timing::readingEstimate(mb_strlen($text)));
    }

    /**
     * A song from the library on air — or a video of a video program (a
     * preaching, testimony, mission video or film), which plays exactly like
     * one (a YouTube video); `kind` tells the app and the host which.
     *
     * @param array<string,mixed> $song library item
     * @return array<string,mixed>
     */
    private function songItem(array $song, ?array $request = null): array
    {
        $jingle = $this->app->selector()->jingle(60_000);
        return [
            'type' => 'song',
            'dur_ms' => $song['duration_ms'],
            'library_id' => $song['id'],
            'payload' => [
                'kind' => Catalog::isVideoFormat($song['kind'] ?? 'song') ? $song['kind'] : 'song',
                'yt' => $song['yt_id'],
                'title' => $song['title'],
                'artist' => $song['artist'],
                'thumb' => $song['thumb'],
                'request' => $request,
                'fallback' => $jingle['audio'] ?? null,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $base
     * @param array<string,mixed> $extra more payload (never published), e.g. a prayer hour's `collect`
     * @return array{0:int,1:int}
     */
    public function addSong(array $channel, array $song, int $cursor, array $base, ?array $request = null, ?string $unit = null, ?int $submissionId = null, array $extra = []): array
    {
        $item = $this->songItem($song, $request);
        $item['payload'] += $extra;
        $this->app->timeline()->addDraft((int) $channel['id'], $base + $item + [
            'est_start' => $cursor, 'unit' => $unit, 'submission_id' => $submissionId,
        ]);
        return [1, $cursor + (int) $song['duration_ms']];
    }

    /**
     * What waits for this program, presented by the host as one block: an
     * announcement before each submission — from the second on it first
     * reacts to the one before (HostWriter reads the neighbours) — and a word
     * after the last. Up to BLOCK_MAX, longest-waiting first, as long as they
     * fit into the program's remaining time; in the order that puts fitting
     * ones next to each other.
     *
     * @param array<string,mixed> $base
     * @param bool $blockEnds another program follows this block
     * @return array{0:int,1:int}|null null when nothing waits or fits
     */
    private function addBlock(array $channel, array $program, int $cursor, array $base, bool $hostOn, bool $blockEnds): ?array
    {
        // One transaction: a submission marked scheduled without its items
        // in the plan would never air, and never come back to the queue.
        return $this->app->store()->tx(function () use ($channel, $program, $cursor, $base, $hostOn, $blockEnds): ?array {
            $subs = $this->app->submissions();
            $roomMs = $blockEnds ? $base['block_end'] - $cursor + Timing::SOFT_OVERRUN : PHP_INT_MAX;
            $units = [];
            $used = 0;
            foreach ($subs->waiting($channel, $program, Timing::BLOCK_MAX * 3) as $sub) {
                if (count($units) >= Timing::BLOCK_MAX) break;
                $unit = $this->unitOf($sub);
                if ($unit === null) {
                    $subs->markMissed((int) $sub['id']);
                    continue;
                }
                $ms = $unit['dur_ms'] + ($hostOn ? Timing::HOST_ESTIMATE : 0);
                if ($used + $ms > $roomMs || !$subs->schedule((int) $sub['id'])) continue;
                $used += $ms;
                $units[] = $unit;
            }
            if (!$units) return null;

            $added = 0;
            foreach (self::byFit($units) as $unit) {
                [$n, $cursor] = $this->addUnit($channel, $program, $unit, $cursor, $base, $hostOn);
                $added += $n;
            }
            // The word after the last one — unless the program closes right
            // after: its outro follows and reacts instead.
            if ($hostOn && !($blockEnds && $base['block_end'] - $cursor < Timing::MIN_SONG)) {
                [$n, $cursor] = $this->addHost($channel, $program, 'break', $cursor, $base);
                $added += $n;
            }
            return [$added, $cursor];
        });
    }

    /**
     * A video program's next video, if one fits into $roomMs: the
     * longest-waiting listener's suggestion (of any kind the program took),
     * announced by the host like a request (a unit), or else one of the
     * program's own $kind from the library, which the host introduces —
     * unless its intro or a break comes right before: pinned to what
     * follows, those introduce it. Then a word from the host after it, as
     * after a block of requests.
     *
     * @param string $kind the program's format: the library kind it plays
     * @param array<string,mixed> $base
     * @param bool $blockEnds another program follows this block
     * @param array<string,mixed>|null $prev the item before (newest of the sequence)
     * @return array{0:int,1:int}|null null when none waits or fits
     */
    private function addVideo(array $channel, array $program, string $kind, int $cursor, array $base, bool $hostOn, bool $blockEnds, int $roomMs, ?array $prev): ?array
    {
        // One transaction: a suggestion marked scheduled without its items in
        // the plan would never air, and never come back to the queue.
        return $this->app->store()->tx(function () use ($channel, $program, $kind, $cursor, $base, $hostOn, $blockEnds, $roomMs, $prev): ?array {
            $subs = $this->app->submissions();
            $added = 0;
            $unit = null;
            // Aired or planned within six hours (as the selection's shortest
            // repeat): a second listener suggesting the same video does not
            // make it air twice in one program.
            $recent = $this->app->timeline()->recentLibraryIds((int) $channel['id'], $cursor - 6 * 3_600_000);
            foreach ($subs->waitingVideos($channel, $program, Timing::BLOCK_MAX) as $sub) {
                $candidate = $this->unitOf($sub);
                if ($candidate === null) {
                    $subs->markMissed((int) $sub['id']);
                    continue;
                }
                if ($candidate['song'] !== null && isset($recent[(int) $candidate['song']['id']])) {
                    $subs->shelve((int) $sub['id']);
                    continue;
                }
                // Too long for the time left: it waits; at the program's end it stays in the library.
                if ($candidate['dur_ms'] + ($hostOn ? Timing::HOST_ESTIMATE : 0) > $roomMs || !$subs->schedule((int) $sub['id'])) continue;
                $unit = $candidate;
                break;
            }
            if ($unit !== null) {
                [$added, $cursor] = $this->addUnit($channel, $program, $unit, $cursor, $base, $hostOn);
            } else {
                // Any other moment of the host (a prayer, another program's
                // outro) does not name what follows: the video needs its own.
                $introduce = $hostOn && !$this->isHost($prev, 'intro') && !$this->isHost($prev, 'break');
                $video = $this->app->selector()->video($channel, $program, $kind, $cursor, $roomMs - ($introduce ? Timing::HOST_ESTIMATE : 0));
                if ($video === null) return null;
                if ($introduce) {
                    [, $cursor] = $this->addHost($channel, $program, $kind, $cursor, $base);
                    $added++;
                }
                [, $cursor] = $this->addSong($channel, $video, $cursor, $base);
                $added++;
            }
            // The word after it — unless the program closes right after: its
            // outro follows and reacts instead.
            if ($hostOn && !($blockEnds && $base['block_end'] - $cursor < Timing::MIN_SONG)) {
                [, $cursor] = $this->addHost($channel, $program, 'break', $cursor, $base);
                $added++;
            }
            return [$added, $cursor];
        });
    }

    /**
     * What a submission airs as, or null when it cannot air any more (its
     * song or video was removed from the library or switched off).
     *
     * @param array<string,mixed> $sub
     * @return array{sub:array<string,mixed>,song:array<string,mixed>|null,dur_ms:int,tags:list<string>}|null
     */
    private function unitOf(array $sub): ?array
    {
        if (in_array($sub['type'], Submissions::VIDEO_TYPES, true)) {
            $song = $sub['library_id'] !== null ? $this->app->library()->get((int) $sub['library_id']) : null;
            if ($song === null || !$song['active'] || $this->app->groups()->isBlocked($song['group_id'])) return null;
            return ['sub' => $sub, 'song' => $song, 'dur_ms' => (int) $song['duration_ms'], 'tags' => Catalog::tags([...$song['themes'], ...$song['moods']])];
        }
        if ((string) ($sub['audio'] ?? '') === '') return null;
        $verdict = json_decode((string) $sub['verdict'], true) ?: [];
        return ['sub' => $sub, 'song' => null, 'dur_ms' => (int) $sub['audio_ms'] + 400,
            'tags' => Catalog::tags([...(array) ($verdict['themes'] ?? []), ...(array) ($verdict['moods'] ?? [])])];
    }

    /**
     * The block's order: the longest-waiting first, then each time the one
     * that shares most themes and moods with the one before (a story about
     * hope, then a song about hope). Ties keep the waiting order.
     *
     * @param list<array{tags:list<string>}> $units longest-waiting first
     * @return list<array{tags:list<string>}>
     */
    private static function byFit(array $units): array
    {
        $order = [array_shift($units)];
        while ($units) {
            $last = end($order)['tags'];
            $best = 0;
            foreach ($units as $i => $u) {
                if (count(array_intersect($last, $u['tags'])) > count(array_intersect($last, $units[$best]['tags']))) $best = $i;
            }
            $order[] = $units[$best];
            array_splice($units, $best, 1);
        }
        return $order;
    }

    /**
     * A listener's submission as a unit: the host's announcement (while the
     * host is on, every request is announced — by name and place, with the
     * dedication when there is one) and the song, video or recording it
     * introduces. The committer keeps the two together — delayed if the
     * announcement is late, never split.
     *
     * @param array{sub:array<string,mixed>,song:array<string,mixed>|null,dur_ms:int} $unit
     * @param array<string,mixed> $base
     * @return array{0:int,1:int}
     */
    private function addUnit(array $channel, array $program, array $unit, int $cursor, array $base, bool $hostOn): array
    {
        $sub = $unit['sub'];
        $uid = 'u' . \Arche\Support\Ids::short(8);
        $cid = (int) $channel['id'];
        $added = 0;
        if ($hostOn) {
            $kind = in_array($sub['type'], Submissions::VIDEO_TYPES, true) ? 'announce' : 'contrib';
            [, $cursor] = $this->addHost($channel, $program, $kind, $cursor, $base, ['submission_id' => (int) $sub['id']], $uid);
            $added++;
        }
        if ($unit['song'] !== null) {
            $name = trim((string) $sub['name']);
            $request = $name !== '' ? ['name' => $name, 'place' => trim((string) $sub['place'])] : null;
            // It airs as what the listener sent it as, even when its video was
            // already in the library as another kind: a suggested testimony
            // filed as a song would be named a song on the stage, not count as
            // the program's video (another would follow at once), and be
            // announced as a song.
            $song = ['kind' => Submissions::libraryKind((string) $sub['type'])] + $unit['song'];
            [, $cursor] = $this->addSong($channel, $song, $cursor, $base, $request, $uid, (int) $sub['id']);
        } else {
            $meta = json_decode((string) $sub['meta'], true) ?: [];
            $dur = $unit['dur_ms'];
            $this->app->timeline()->addDraft($cid, $base + [
                'type' => 'contrib', 'dur_ms' => $dur, 'est_start' => $cursor, 'unit' => $uid,
                'submission_id' => (int) $sub['id'],
                'payload' => [
                    'kind' => $sub['type'] === 'prayer' ? 'prayer' : $sub['type'],
                    'audio' => $sub['audio'],
                    'caption' => ['en' => (string) ($meta['caption_en'] ?? ''), 'de' => (string) ($meta['caption_de'] ?? '')],
                    'name' => (string) $sub['name'],
                    'place' => (string) $sub['place'],
                ],
            ]);
            $cursor += $dur;
        }
        return [$added + 1, $cursor];
    }

    /** Fill to the end of a block: a jingle if one fits, else a stage card for what comes next. @param array<string,mixed> $base @return array{0:int,1:int} */
    public function pad(array $channel, int $cursor, int $ms, array $base, int $nextProgramId): array
    {
        $cid = (int) $channel['id'];
        $jingle = $this->app->selector()->jingle($ms);
        if ($jingle !== null && $jingle['duration_ms'] >= $ms - 20_000) {
            $this->app->timeline()->addDraft($cid, $base + [
                'type' => 'jingle', 'dur_ms' => $jingle['duration_ms'], 'est_start' => $cursor, 'library_id' => $jingle['id'],
                'payload' => ['audio' => $jingle['audio']],
            ]);
            return [1, $cursor + $jingle['duration_ms']];
        }
        $next = $this->app->catalog()->program($nextProgramId);
        $label = $next !== null
            ? ['en' => 'Up next: ' . $next['title_en'], 'de' => 'Gleich: ' . $next['title_de']]
            : ['en' => 'Stay with us', 'de' => 'Bleib dran'];
        $this->app->timeline()->addDraft($cid, $base + [
            'type' => 'stage', 'dur_ms' => $ms, 'est_start' => $cursor, 'payload' => ['label' => $label],
        ]);
        return [1, $cursor + $ms];
    }

    /** @param array<string,mixed>|null $item */
    private function isHost(?array $item, ?string $kind = null): bool
    {
        if ($item === null || $item['type'] !== 'host') return false;
        return $kind === null || ($item['payload']['kind'] ?? '') === $kind;
    }

    /**
     * A video of a video program on air: a song item of a video kind (of
     * $kind, when given — 'preaching', 'testimony', 'mission', 'film').
     *
     * @param array<string,mixed>|null $item
     */
    public static function isVideo(?array $item, ?string $kind = null): bool
    {
        if ($item === null || $item['type'] !== 'song') return false;
        $k = (string) ($item['payload']['kind'] ?? 'song');
        return $kind === null ? Catalog::isVideoFormat($k) : $k === $kind;
    }

    /**
     * A video program's next video is due: none yet since the program began
     * (an item of another program comes first), or $between songs since the
     * last one.
     *
     * @param list<array<string,mixed>> $recent newest first
     */
    private static function videoDue(array $recent, int $programId, int $between): bool
    {
        $songs = 0;
        foreach ($recent as $item) {
            if ($item['program_id'] !== $programId) return true;
            if (self::isVideo($item)) return $songs >= $between;
            if ($item['type'] === 'song') $songs++;
        }
        return true;
    }

    /**
     * Regular songs since the last listener submission aired or planned
     * (newest-first list); as good as unlimited when none is in sight.
     *
     * @param list<array<string,mixed>> $recent
     */
    private function songsSinceRequest(array $recent): int
    {
        $n = 0;
        foreach ($recent as $item) {
            if ($item['submission_id'] !== null) return $n;
            if ($item['type'] === 'song') $n++;
        }
        return PHP_INT_MAX;
    }

    /** Songs since the last item of $type (newest-first list). @param list<array<string,mixed>> $recent */
    private function songsSince(array $recent, string $type): int
    {
        $n = 0;
        foreach ($recent as $item) {
            if ($item['type'] === $type) return $n;
            if ($item['type'] === 'song') $n++;
        }
        return $n;
    }

    /** @param list<array<string,mixed>> $recent */
    private function minutesSince(array $recent, string $type, int $cursor, int $blockStart): int
    {
        foreach ($recent as $item) {
            if ($item['type'] === $type) return intdiv($cursor - (int) ($item['start_ms'] ?? $item['est_start']), 60_000);
        }
        return intdiv($cursor - $blockStart, 60_000);
    }
}

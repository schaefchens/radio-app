<?php
declare(strict_types=1);

namespace Arche\Host;

use Arche\App;
use Arche\Audio\Mp3;
use Arche\Program\PrayerHour;
use Arche\Program\Timing;
use Arche\Submission\Submissions;
use Arche\Support\Ids;

/**
 * A host break is one job with one network call per phase:
 *
 *   script → tts:en → tts:de → ready
 *
 * so each fits one tick's budget, and a phase lost to a killed request is
 * retried by the job lease on the next tick. The cost gates run at script
 * time, not at drafting time: a listener who tunes in now should hear the
 * host in the next break already planned, not only in the ones planned later.
 */
final class HostBreaks
{
    public function __construct(private App $app) {}

    /**
     * Whether host breaks can be produced at all: a text model (Claude or
     * OpenAI) and a voice (OpenAI, or ElevenLabs when opted in) — or stub mode.
     */
    public function available(): bool
    {
        $c = $this->app->config;
        return $c->stubAi() || ($c->textProvider() !== '' && ($c->openaiKey() !== '' || $c->has('ELEVENLABS_API_KEY')));
    }

    /**
     * @param array<string,mixed> $channel
     * @param array<string,mixed> $program
     * @param array<string,mixed> $item the draft timeline row
     * @param array<string,mixed> $context
     */
    public function create(array $channel, array $program, string $kind, array $item, array $context = []): int
    {
        $now = $this->app->clock->now();
        $id = $this->app->store()->insert('host_breaks', [
            'channel_id' => (int) $channel['id'],
            'program_id' => (int) $program['id'],
            'kind' => $kind,
            'state' => 'pending',
            'context' => json_encode($context ?: new \stdClass(), JSON_UNESCAPED_UNICODE),
            'created' => $now,
            'updated' => $now,
        ]);
        // Earliest airtime first; announcements, listeners' words read out,
        // prayer moments and every moment the committer would rather delay
        // than drop (a unit) before plain breaks.
        $priority = in_array($kind, ['announce', 'contrib', 'prayer', 'reading', 'intercession', 'opening', 'invite'], true) || ($item['unit'] ?? null) !== null ? 10 : 20;
        $this->app->jobs()->enqueue('host', $id, $priority, (int) $item['est_start']);
        return $id;
    }

    /** @return array<string,mixed>|null */
    public function get(int $id): ?array
    {
        $row = $this->app->store()->one('SELECT * FROM host_breaks WHERE id = ?', [$id]);
        if ($row === null) return null;
        foreach (['context', 'texts', 'audio', 'durations'] as $k) {
            $v = json_decode((string) $row[$k], true);
            $row[$k] = is_array($v) ? $v : [];
        }
        $row['id'] = (int) $row['id'];
        return $row;
    }

    /** @param string $why 'late': its time came before its voice (a plan change gives none) */
    public function cancel(int $id, string $why = ''): void
    {
        $this->app->store()->query(
            "UPDATE host_breaks SET state = 'cancelled', source = CASE WHEN ? = '' THEN source ELSE 'skipped:' || ? END, updated = ?
             WHERE id = ? AND state = 'pending'",
            [$why, $why, $this->app->clock->now(), $id],
        );
        $this->app->jobs()->cancel('host', $id);
    }

    /**
     * The typed prayer requests a prayer break was drafted for. They have a
     * key of their own: the script phase stores the writer's context over the
     * drafted one, and there `prayers` are the texts, not the ids. A break
     * drafted before the key existed keeps its ids under `prayers` until its
     * script is written.
     *
     * @param array<string,mixed> $hb decoded host break
     * @return list<int>
     */
    public static function prayerIds(array $hb): array
    {
        $ids = $hb['context']['prayer_ids'] ?? $hb['context']['prayers'] ?? [];
        return array_values(array_filter((array) $ids, 'is_int'));
    }

    /**
     * Host breaks whose context names one of these submissions: the request
     * a moment announces, a recording it introduces, the prayers it prays
     * for, the request a prayer hour takes up again.
     *
     * @param list<int> $subIds
     * @return list<int>
     */
    public function referencing(array $subIds): array
    {
        if (!$subIds) return [];
        $in = implode(',', array_map('intval', $subIds));
        return array_map('intval', array_column($this->app->store()->all(
            "SELECT id FROM host_breaks WHERE json_valid(context) AND (
               json_extract(context, '$.submission_id') IN ($in) OR json_extract(context, '$.again_id') IN ($in)
               OR EXISTS (SELECT 1 FROM json_each(host_breaks.context, '$.prayer_ids') j WHERE j.value IN ($in)))",
        ), 'id'));
    }

    /** What a script is written from that names listeners (HostWriter::context). */
    private const PERSONAL = ['request', 'contribution', 'previous_request', 'previous_id', 'prayers', 'community', 'community_by'];

    /**
     * An account deleted by its owner (Identity\Erasure): these host breaks
     * forget it — its own, and others' that react to it or were given its
     * community voice. Cancelled unless they aired (a ready one must not be
     * committed empty); their clips deleted, here and at the edge; the
     * script, the clips' addresses and what the script was written from
     * (names, places, prayer texts, the request before, the voices) emptied.
     * What a prayer hour reads of its own moments (`phase`) stays.
     *
     * @param list<int> $breakIds
     * @param list<int> $erased the account's submissions
     */
    public function forget(array $breakIds, array $erased): int
    {
        $n = 0;
        foreach ($breakIds as $id) {
            // Cancelled first: a job still voicing it can no longer save a clip (saveIfPending).
            $this->cancel($id);
            $this->app->store()->update('host_breaks', ['state' => 'cancelled'], "id = ? AND state = 'ready'", [$id]);
            $hb = $this->get($id);
            if ($hb === null) continue;
            foreach ($hb['audio'] as $url) $this->app->media()->delete(is_string($url) ? $url : null);
            $ctx = array_diff_key($hb['context'], array_flip(self::PERSONAL));
            foreach (['submission_id', 'again_id'] as $k) {
                if (isset($ctx[$k]) && in_array((int) $ctx[$k], $erased, true)) unset($ctx[$k]);
            }
            if (isset($ctx['prayer_ids'])) $ctx['prayer_ids'] = array_values(array_filter((array) $ctx['prayer_ids'], fn($p) => !in_array((int) $p, $erased, true)));
            $this->save($id, [
                'context' => json_encode($ctx ?: new \stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'texts' => '{}',
                'audio' => '{}',
                'durations' => '{}',
            ]);
            $n++;
        }
        return $n;
    }

    /**
     * A drafted break that reacts to a deleted account's request (or was
     * written with its voice): a new break takes its place in the plan and is
     * written afresh, so the unit it belongs to keeps its place — another
     * listener's announcement is not lost with it. The old one is left to
     * forget(). Null when it has no draft (committed ones are blocked instead).
     */
    public function rewrite(int $id): ?int
    {
        $hb = $this->get($id);
        $row = $this->app->store()->one("SELECT * FROM timeline_items WHERE host_break_id = ? AND state = 'draft' ORDER BY id DESC LIMIT 1", [$id]);
        if ($hb === null || $row === null) return null;
        $channel = $this->app->catalog()->channel((int) $hb['channel_id']);
        $program = $hb['program_id'] !== null ? $this->app->catalog()->program((int) $hb['program_id']) : null;
        if ($channel === null || $program === null) return null;
        $new = $this->create($channel, $program, (string) $hb['kind'], \Arche\Program\Timeline::decode($row), array_diff_key($hb['context'], array_flip(self::PERSONAL)));
        $this->app->store()->update('timeline_items', ['host_break_id' => $new], 'id = ?', [(int) $row['id']]);
        return $new;
    }

    /**
     * A break given a deleted account's community voice whose script does not
     * use it keeps its script; the voice leaves its context.
     *
     * @param callable(array<string,mixed>, ?string): bool $theirs a voice and its author's mark
     */
    public function dropVoices(int $id, callable $theirs): void
    {
        $hb = $this->get($id);
        if ($hb === null) return;
        $ctx = $hb['context'];
        $voices = array_values((array) ($ctx['community'] ?? []));
        $tags = array_values((array) ($ctx['community_by'] ?? []));
        $keep = [];
        foreach ($voices as $i => $v) {
            if (!$theirs((array) $v, isset($tags[$i]) ? (string) $tags[$i] : null)) $keep[$i] = true;
        }
        $ctx['community'] = array_values(array_intersect_key($voices, $keep));
        if (isset($ctx['community_by'])) $ctx['community_by'] = array_values(array_intersect_key($tags, $keep));
        $this->save($id, ['context' => json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }

    /** @param array<string,mixed> $hb */
    public function airDuration(array $hb): int
    {
        $max = 0;
        foreach ($hb['durations'] as $ms) $max = max($max, (int) $ms);
        // After a listener's words a few seconds of quiet, to take them in.
        $pad = in_array($hb['kind'], HostWriter::READINGS, true) ? Timing::PRAYER_GAP : Timing::HOST_PAD;
        return max(2000, $max + $pad);
    }

    /** @param array<string,mixed> $hb @return array<string,mixed> */
    public function payload(array $hb, ?array $before = null): array
    {
        return [
            'kind' => (string) $hb['kind'],
            'audio' => $hb['audio'],
            'text' => array_intersect_key($hb['texts'], $hb['audio']),
            'voices' => $hb['kind'] === 'break' ? array_slice($this->app->presence()->voices($this->channelSlug($hb)), 0, 3) : [],
            'prayers' => $this->wallRefs($hb),
        ] + $this->notice($hb, $before);
    }

    /**
     * The group the script pointed to (HostWriter::context()), for the stage:
     * only while the committed item before is still theirs — after anything
     * else the links would name the wrong ones.
     *
     * @param array<string,mixed> $hb
     * @param array<string,mixed>|null $before the committed item right before
     * @return array{notice?:array<string,mixed>}
     */
    private function notice(array $hb, ?array $before): array
    {
        $gid = (int) ($hb['context']['group_id'] ?? 0);
        if ($gid === 0 || $before === null || $before['type'] !== 'song' || $before['library_id'] === null) return [];
        $lib = $this->app->library()->get((int) $before['library_id']);
        if ($lib === null || $lib['group_id'] !== $gid) return [];
        $notice = $this->app->groups()->notice($gid);
        return $notice !== null ? ['notice' => $notice] : [];
    }

    /**
     * The requests on the wall this moment is about, by their wall id ('p' +
     * public id): a request read out (in any program), or those a prayer
     * hour's moment takes up. The app marks them "On air now". Only ids —
     * what may be shown of them is live.json's business, where a moderator's
     * takedown applies after publishing too.
     *
     * @param array<string,mixed> $hb
     * @return list<string>
     */
    private function wallRefs(array $hb): array
    {
        if ($hb['kind'] !== 'reading' && ($hb['kind'] !== 'prayer' || !$this->inPrayerHour($hb))) return [];
        $ids = self::prayerIds($hb);
        if (isset($hb['context']['again_id'])) $ids[] = (int) $hb['context']['again_id'];
        $refs = [];
        foreach ($ids as $id) {
            $sub = $this->app->submissions()->get($id);
            if ($sub !== null && Submissions::onWall($sub, $this->inPrayerHour($hb))) $refs[] = 'p' . $sub['public_id'];
        }
        return $refs;
    }

    /**
     * Run one phase. Returns the next phase, or null when the job is finished
     * (ready, failed or cancelled).
     *
     * @param array<string,mixed> $job
     */
    public function runPhase(array $job): ?string
    {
        $hb = $this->get((int) $job['ref_id']);
        if ($hb === null || $hb['state'] !== 'pending') return null;
        $phase = $job['phase'] === 'start' ? 'script' : (string) $job['phase'];

        if ($phase === 'script') {
            $gate = $this->gate($hb);
            if ($gate !== null) {
                $this->fail($hb, $gate);
                return null;
            }
            $context = $this->app->hostWriter()->context($hb);
            $written = $this->app->hostWriter()->write($hb, $context);
            // The model takes seconds: an account deleted meanwhile has had this break forgotten.
            if (!$this->saveIfPending($hb['id'], [
                'context' => json_encode($context + $hb['context'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'texts' => json_encode($written['texts'] ?: new \stdClass(), JSON_UNESCAPED_UNICODE),
                'source' => $written['source'],
            ])) {
                return null;
            }
            // Straight to the first language there is something to say in: a
            // reading is voiced once, and a phase that does nothing still costs
            // one of the runner's runs this tick.
            $first = $this->nextLang($written['texts'], null);
            if ($first === null) {
                // A listener's words gone since it was planned, or no prayer prepared: nothing to say.
                $this->fail($hb, 'no_text');
                return null;
            }
            return 'tts:' . $first;
        }

        if (str_starts_with($phase, 'tts:')) {
            $lang = substr($phase, 4);
            $channel = $this->app->catalog()->channel((int) $hb['channel_id']) ?? [];
            $text = (string) ($hb['texts'][$lang] ?? '');
            if ($text !== '') {
                $spoken = $this->app->voice()->speak($text, $lang, $channel, (string) ($channel['host_style'] ?? ''));
                $name = sprintf('%d-%s.%s.mp3', $hb['id'], Ids::short(6), $lang);
                $url = $this->app->media()->put('host/' . gmdate('Ymd', $this->app->clock->now()), $name, $spoken['bytes']);
                $check = Mp3::inspect((string) $this->app->media()->path($url));
                if (!$check['ok']) throw new \RuntimeException('TTS returned no playable audio');
                $hb['audio'][$lang] = $url;
                $hb['durations'][$lang] = $check['ms'];
                if (!$this->saveIfPending($hb['id'], ['audio' => json_encode($hb['audio']), 'durations' => json_encode($hb['durations'])])) {
                    // Forgotten while it was spoken: the clip of the old script goes too.
                    $this->app->media()->delete($url);
                    return null;
                }
            }
            $next = $this->nextLang($hb['texts'], $lang);
            if ($next !== null) return 'tts:' . $next;
            if (!$hb['audio']) {
                $this->fail($hb, 'no_audio');
                return null;
            }
            $this->saveIfPending($hb['id'], ['state' => 'ready']);
            return null;
        }
        return null;
    }

    /** Why this break should not cost anything right now, or null. @param array<string,mixed> $hb */
    private function gate(array $hb): ?string
    {
        $c = $this->app->config;
        if (!$this->available()) return 'unavailable';
        $slug = $this->channelSlug($hb);
        $prayerHour = $this->inPrayerHour($hb);
        // People's own words, read out (and a moderator's prepared opening prayer).
        $theirs = in_array($hb['kind'], HostWriter::READINGS, true) || $hb['kind'] === 'opening';
        $owed = $theirs || in_array($hb['kind'], ['announce', 'contrib'], true)
            // Outside a prayer hour the invitation after requests read out; in it, a moment with requests.
            || ($hb['kind'] === 'prayer' && (self::prayerIds($hb) !== [] || !$prayerHour))
            || (in_array($hb['kind'], ['intro', 'invite', 'present', 'prayertime'], true) && $prayerHour);
        // A listener who handed something in gets it read out, announced or
        // presented even when they are the only one listening. A prayer hour's
        // welcome is written about eight minutes before the hour, before its
        // listeners tune in: gated, the hour opened without them for everyone
        // who came on time — and its order (the requests presented, the prayer
        // time opened) must not depend on who happens to listen. Everything
        // else — breaks, an encouragement, the outro — needs an audience.
        if (!$owed && $this->app->presence()->listeners($slug) < $c->int('HOST_MIN_LISTENERS', 1)) return 'no_listeners';
        // The daily cap is for the host's own words: a reading costs one voice
        // call, and someone sent it — it is neither stopped nor counted.
        if (!$theirs) {
            $today = (int) $this->app->store()->value(
                "SELECT COUNT(*) FROM host_breaks WHERE channel_id = ? AND state = 'ready' AND updated >= ? AND source NOT IN ('listener', 'moderator')",
                [(int) $hb['channel_id'], $this->app->clock->now() - 86400],
            );
            if ($today >= $c->int('HOST_MAX_BREAKS_PER_DAY', 300)) return 'daily_cap';
        }
        if (!$this->app->usage()->withinBudget()) return 'budget';
        return null;
    }

    /**
     * The station language after $after (or the first) that has something to
     * say in $texts, or null.
     *
     * @param array<string,mixed> $texts
     */
    private function nextLang(array $texts, ?string $after): ?string
    {
        $langs = $this->app->config->stationLangs();
        $from = $after === null ? 0 : ((int) array_search($after, $langs, true)) + 1;
        foreach (array_slice($langs, $from) as $l) {
            if (trim((string) ($texts[$l] ?? '')) !== '') return $l;
        }
        return null;
    }

    /** @param array<string,mixed> $hb */
    private function inPrayerHour(array $hb): bool
    {
        return PrayerHour::applies($hb['program_id'] !== null ? $this->app->catalog()->program((int) $hb['program_id']) : null);
    }

    /** @param array<string,mixed> $hb */
    private function fail(array $hb, string $why): void
    {
        $this->saveIfPending($hb['id'], ['state' => 'failed', 'source' => 'skipped:' . $why]);
    }

    /** @param array<string,mixed> $set */
    private function save(int $id, array $set): void
    {
        $this->app->store()->update('host_breaks', $set + ['updated' => $this->app->clock->now()], 'id = ?', [$id]);
    }

    /**
     * A job's result, unless the break was cancelled meanwhile (a plan change,
     * a deleted account): one statement, so nothing slips in between.
     *
     * @param array<string,mixed> $set
     */
    private function saveIfPending(int $id, array $set): bool
    {
        return $this->app->store()->update('host_breaks', $set + ['updated' => $this->app->clock->now()], "id = ? AND state = 'pending'", [$id]) === 1;
    }

    /** @param array<string,mixed> $hb */
    private function channelSlug(array $hb): string
    {
        return (string) ($this->app->catalog()->channel((int) $hb['channel_id'])['slug'] ?? 'main');
    }
}

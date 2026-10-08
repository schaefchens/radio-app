<?php
declare(strict_types=1);

namespace Arche\Host;

use Arche\App;
use Arche\Ai\Voice;
use Arche\Ai\VoiceError;
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
 *
 * Who speaks is decided at script time too (Hosts::forBreak: the show's
 * host, else one who can), and one host voices every language. When its
 * voice fails, the moment goes to the next host who can speak: voiced again
 * by them — or, when they have another name and the model wrote the words
 * (it may have said its name), written again first. At most MAX_TRIED hosts.
 */
final class HostBreaks
{
    /** Hosts one moment may go through before it gives up. */
    private const MAX_TRIED = 4;
    /**
     * A script the model did not answer: asked again this often, while its
     * moment is this far beyond the commit. Scripts are written about eight
     * minutes ahead (DRAFT), so a lead of three minutes left a first attempt
     * no retry at all; two still leave a voice worker over a minute.
     */
    private const SCRIPT_RETRIES = 2;
    private const RETRY_LEAD_MS = 120_000;
    /** What a script pass wrote into the context, gone before the next pass writes its own. */
    private const WRITTEN = ['community', 'community_by', 'previous_request', 'previous_id', 'prayers', 'previous_group', 'group_id', 'host_name', 'request', 'contribution', 'delivery', 'fact_refs', 'fact_told', 'cite'];

    public function __construct(private App $app) {}

    /**
     * Whether host breaks can be produced: a text model (Claude or OpenAI, or
     * stub mode) and a host who can speak — in this program's lineup when one
     * is given (Hosts::effective), anywhere on the station otherwise. Given a
     * kind the program takes from recorded lines (Host\Lines), a host with
     * such lines is enough: a recorded line needs no voice now.
     *
     * @param array<string,mixed>|null $channel
     * @param array<string,mixed>|null $program
     */
    public function available(?array $channel = null, ?array $program = null, ?string $kind = null): bool
    {
        if ($this->app->config->textProvider() === '') return false;
        $hosts = $this->app->hosts();
        $speaks = $channel === null ? $hosts->anySpeaks() : $hosts->speaksFor($channel, $program);
        if ($speaks || $kind === null || $channel === null) return $speaks;
        return $this->app->lines()->covers($channel, $program, $kind);
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
        // The show it belongs to (one host per show), from its block: an
        // estimated start drifts past a block's end when the plan runs late.
        $at = (int) ($item['block_start'] ?? $item['est_start'] ?? $this->app->clock->nowMs());
        $context['show'] ??= $this->app->hosts()->showStart($channel, (int) $program['id'], $at);
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
        // What a voice worker was asked for it: not wanted any more.
        $this->app->workers()->cancelFor('break', $id);
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
    /** `delivery` too: written with the rest, it could name someone the sanitizer did not know of. */
    private const PERSONAL = ['request', 'contribution', 'previous_request', 'previous_id', 'prayers', 'community', 'community_by', 'delivery'];

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
            $this->deleteClips($hb['audio']);
            $this->app->workers()->forget('break', $id);
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
        // Its host is found anew (the old one may have been tried and failed).
        $new = $this->create($channel, $program, (string) $hb['kind'], \Arche\Program\Timeline::decode($row), array_diff_key($hb['context'], array_flip([...self::PERSONAL, 'host_id', 'tried'])));
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
            // Who speaks, as listeners see them; null once the host is gone.
            'host' => $this->app->hosts()->info((int) ($hb['context']['host_id'] ?? 0)),
        ] + $this->notice($hb, $before)
            // The page of a fact the words told: the stage links it (web information shown is cited).
            + (isset($hb['context']['cite']['url']) ? ['cite' => $hb['context']['cite']] : []);
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
        // Written again after the model did not answer (below).
        if ($phase === 'wait:script') $phase = 'script';

        if ($phase === 'script') {
            $library = $this->libraryCovers($hb);
            $gate = $this->gate($hb, $library);
            if ($gate !== null) {
                $this->fail($hb, $gate);
                return null;
            }
            $tried = self::tried($hb);
            // A recorded line, where the program wants one and its host has one: ready at once.
            if ($library && $tried === [] && $this->fromLibrary($hb)) return null;
            // None fits: fresh words, gated like any others.
            if ($library && ($gate = $this->gate($hb)) !== null) {
                $this->fail($hb, $gate);
                return null;
            }
            // Everything after costs a script and a voice: not past the day's budget.
            if (!$this->app->usage()->withinBudget()) {
                $this->fail($hb, 'budget');
                return null;
            }
            // Its host: the one a switch chose (and that can still speak), else the show's.
            $hosts = $this->app->hosts();
            $chosen = isset($hb['context']['host_id']) ? $hosts->get((int) $hb['context']['host_id']) : null;
            $host = $chosen !== null && !in_array($chosen['id'], $tried, true) && $hosts->canSpeak($chosen) ? $chosen : $hosts->forBreak($hb, $tried);
            if ($host === null) {
                $this->fail($hb, 'no_voice');
                return null;
            }
            $context = $this->app->hostWriter()->context($hb, $host);
            $written = $this->app->hostWriter()->write($hb, $context, $host);
            // The model did not answer (a timeout, a dropped connection) while the
            // moment is still far off — after a long video an hour away: asked
            // again in half a minute, twice at most, before its template airs.
            if ($written['source'] === 'template:error' && $this->mayAskAgain($hb)) {
                $this->app->store()->query(
                    "UPDATE host_breaks SET context = json_set(context, '$.script_retries', ?), updated = ? WHERE id = ? AND state = 'pending'",
                    [(int) ($hb['context']['script_retries'] ?? 0) + 1, $this->app->clock->now(), $hb['id']],
                );
                return 'wait:script';
            }
            // A switch before writing again: the clips of the one before go.
            $this->deleteClips($hb['audio']);
            // The model takes seconds: an account deleted meanwhile has had this break forgotten.
            // How it should sound goes with the words (Speech::direction joins it to the host's own).
            $delivery = $written['delivery'] !== '' ? ['delivery' => $written['delivery']] : [];
            // A fact the words told rests from now, not from when it airs: a request block is written
            // before any of it airs, and the moment after an announcement must not tell it again.
            $told = [];
            $ref = $context['fact_refs'][$written['fact']] ?? null;
            if ($written['fact'] !== '' && is_array($ref)) {
                $cite = $this->app->knowledge()->told($ref, $this->app->clock->nowMs());
                $told = ['fact_told' => $written['fact']] + ($cite !== null ? ['cite' => $cite] : []);
            }
            if (!$this->saveIfPending($hb['id'], [
                'context' => json_encode(['host_id' => $host['id']] + $delivery + $told + $context + array_diff_key($hb['context'], array_flip(self::WRITTEN)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'texts' => json_encode($written['texts'] ?: new \stdClass(), JSON_UNESCAPED_UNICODE),
                'audio' => '{}',
                'durations' => '{}',
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
            // Its characters for today cannot take every language: another host, before any clip.
            if (!$hosts->hasRoom($host, $written['texts'])) return $this->switchHost($this->get($hb['id']) ?? $hb, $host, null);
            return 'tts:' . $first;
        }

        if (str_starts_with($phase, 'tts:')) {
            $lang = substr($phase, 4);
            $text = (string) ($hb['texts'][$lang] ?? '');
            if ($text !== '') {
                $hosts = $this->app->hosts();
                $host = $hosts->get((int) ($hb['context']['host_id'] ?? 0));
                // Deleted, switched off, resting or out of characters since its script: the next who can.
                if ($host === null || !$hosts->canSpeak($host, mb_strlen($text))) return $this->switchHost($hb, $host, null);
                // A voice worker's (Host\Workers): asked for every language at once; its uploads finish the moment.
                if (Voice::async($host)) return $this->askWorkers($hb, $host);
                try {
                    $spoken = $this->app->voice()->speak($host, Speech::forVoice($text, $lang, self::theirs($hb)), $lang, delivery: self::delivery($hb));
                    $name = sprintf('%d-%s.%s.mp3', $hb['id'], Ids::short(6), $lang);
                    $url = $this->app->media()->put('host/' . gmdate('Ymd', $this->app->clock->now()), $name, $spoken['bytes']);
                    $check = Mp3::inspect((string) $this->app->media()->path($url));
                    if (!$check['ok']) {
                        $this->app->media()->delete($url);
                        throw VoiceError::broken((string) $host['provider'], 'no playable audio');
                    }
                } catch (VoiceError $e) {
                    $hosts->failed($host, $e);
                    return $this->switchHost($hb, $host, $e);
                }
                $hosts->succeeded($host);
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

        if ($phase === 'wait') {
            // Ready meanwhile: runPhase returned above. Still open: a worker is on
            // it, or will be — unless none that offers this voice is online and
            // nobody holds a task. A task given up (a clip that did not fit its
            // words twice, the commit too near): the next host who can.
            // This round's tasks only: an earlier host's were cancelled when it was left.
            $round = array_map('intval', (array) ($hb['context']['tasks'] ?? []));
            $tasks = array_filter($this->app->workers()->tasksFor('break', $hb['id']), fn($t) => in_array($t['id'], $round, true));
            $open = array_filter($tasks, fn($t) => in_array($t['state'], ['queued', 'leased'], true));
            $gone = array_filter($tasks, fn($t) => in_array($t['state'], ['failed', 'cancelled'], true));
            $host = $this->app->hosts()->get((int) ($hb['context']['host_id'] ?? 0));
            $held = array_filter($open, fn($t) => $t['state'] === 'leased');
            $nobody = $host === null || !$this->app->hosts()->canSpeak($host);
            if ($gone || ($open && !$held && $nobody)) {
                $this->app->workers()->cancelFor('break', $hb['id']);
                return $this->switchHost($this->get($hb['id']) ?? $hb, $host, null);
            }
            if (!$open) {
                // Every task done, and still not ready: a language never asked for.
                $this->readyIfComplete($hb['id']);
                $now = $this->get($hb['id']);
                if ($now !== null && $now['state'] === 'pending' && $host !== null) return $this->askWorkers($now, $host);
                return null;
            }
            return 'wait';
        }
        return null;
    }

    /**
     * Whether a script the model did not answer may be asked again: twice at
     * most, and only while there is time to write and voice it before its
     * minutes are fixed.
     *
     * @param array<string,mixed> $hb
     */
    private function mayAskAgain(array $hb): bool
    {
        if ((int) ($hb['context']['script_retries'] ?? 0) >= self::SCRIPT_RETRIES) return false;
        $start = (int) ($this->app->store()->value('SELECT est_start FROM timeline_items WHERE host_break_id = ? ORDER BY id DESC LIMIT 1', [$hb['id']]) ?? 0);
        return $start - $this->app->clock->nowMs() > Timing::COMMIT + self::RETRY_LEAD_MS;
    }

    /**
     * A worker host's moment: every language without a clip asked of the voice
     * workers at once, due before the commit comes near (Workers::deadlineFor).
     *
     * @param array<string,mixed> $hb
     * @param array<string,mixed> $host
     */
    private function askWorkers(array $hb, array $host): string
    {
        $workers = $this->app->workers();
        $workers->cancelFor('break', $hb['id']);
        $start = (int) ($this->app->store()->value('SELECT est_start FROM timeline_items WHERE host_break_id = ? ORDER BY id DESC LIMIT 1', [$hb['id']]) ?? 0);
        $deadline = $start > 0 ? Workers::deadlineFor($start) : $this->app->clock->now() + 240;
        $ids = [];
        foreach ($this->app->config->stationLangs() as $l) {
            $text = trim((string) ($hb['texts'][$l] ?? ''));
            if ($text !== '' && !isset($hb['audio'][$l])) $ids[] = $workers->request('break', $hb['id'], $host, $l, Speech::forVoice($text, $l, self::theirs($hb)), $deadline, delivery: self::delivery($hb));
        }
        $this->app->store()->query(
            "UPDATE host_breaks SET context = json_set(context, '$.tasks', json(?)), updated = ? WHERE id = ? AND state = 'pending'",
            [json_encode($ids), $this->app->clock->now(), $hb['id']],
        );
        return 'wait';
    }

    /**
     * A clip of a language arrived from a voice worker (Host\Workers::complete):
     * merged in one statement (two languages may arrive at the same moment),
     * and the moment is ready once every language with words has its clip.
     * False: the moment is not pending any more (the caller drops the clip).
     */
    public function voiced(int $id, string $lang, string $url, int $ms): bool
    {
        $saved = $this->app->store()->query(
            "UPDATE host_breaks SET audio = json_set(audio, '$.' || ?, ?), durations = json_set(durations, '$.' || ?, ?), updated = ? WHERE id = ? AND state = 'pending'",
            [$lang, $url, $lang, $ms, $this->app->clock->now(), $id],
        )->rowCount() === 1;
        if ($saved) $this->readyIfComplete($id);
        return $saved;
    }

    private function readyIfComplete(int $id): void
    {
        $hb = $this->get($id);
        if ($hb === null || $hb['state'] !== 'pending' || !$hb['audio']) return;
        foreach ($this->app->config->stationLangs() as $l) {
            if (trim((string) ($hb['texts'][$l] ?? '')) !== '' && !isset($hb['audio'][$l])) return;
        }
        $this->saveIfPending($id, ['state' => 'ready']);
    }

    /**
     * The moment goes to the next host who can speak (Hosts::forBreak, never
     * one tried before). Words of the same name's host, or not the model's
     * (a reading, a moderator's text, a template), are voiced again in every
     * language; another name's are written again for them first. Nobody left:
     * a temporary error is thrown, for the job's retry as before; otherwise
     * the moment fails.
     *
     * @param array<string,mixed> $hb
     * @param array<string,mixed>|null $from the host that could not
     */
    private function switchHost(array $hb, ?array $from, ?VoiceError $error): ?string
    {
        $this->app->workers()->cancelFor('break', $hb['id']);
        $tried = self::tried($hb);
        if ($from !== null && !in_array($from['id'], $tried, true)) $tried[] = $from['id'];
        $next = count($tried) < self::MAX_TRIED ? $this->app->hosts()->forBreak($hb, $tried) : null;
        if ($next === null) {
            if ($error !== null && !$error->lasting()) throw $error;
            $this->fail($hb, 'no_voice');
            return null;
        }
        // The model may have said the name it wrote for; templates and people's words name no host.
        $modelWords = !in_array((string) $hb['source'], ['listener', 'moderator'], true) && !str_starts_with((string) $hb['source'], 'template:');
        $writtenFor = (string) ($hb['context']['host_name'] ?? $from['name'] ?? '');
        $rewrite = $modelWords && $writtenFor !== '' && mb_strtolower((string) $next['name']) !== mb_strtolower($writtenFor);
        $this->deleteClips($hb['audio']);
        $context = ['host_id' => $next['id'], 'tried' => $tried] + $hb['context'];
        if (!$this->saveIfPending($hb['id'], [
            'context' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'audio' => '{}',
            'durations' => '{}',
        ])) {
            return null;
        }
        if ($rewrite) return 'script';
        $first = $this->nextLang($hb['texts'], null);
        return $first !== null ? 'tts:' . $first : 'script';
    }

    /**
     * Whether a moment's words are people's own — a listener's request or
     * prayer read out, a moderator's opening prayer — which the voice gets
     * with only their typography made speakable (Speech::forVoice).
     *
     * @param array<string,mixed> $hb
     */
    private static function theirs(array $hb): bool
    {
        return in_array((string) $hb['source'], ['listener', 'moderator'], true);
    }

    /** How the moment's words should sound ('' leaves the host's own direction alone). @param array<string,mixed> $hb */
    private static function delivery(array $hb): string
    {
        return (string) ($hb['context']['delivery'] ?? '');
    }

    /** @param array<string,mixed> $hb @return list<int> hosts this moment already failed with */
    private static function tried(array $hb): array
    {
        return array_values(array_filter(array_map('intval', (array) ($hb['context']['tried'] ?? [])), fn($id) => $id > 0));
    }

    /**
     * Why this break should not cost anything right now, or null. The day's
     * budget is checked after the library (runPhase): a recorded line costs
     * no voice, and with the budget spent its pick still works without the model.
     *
     * @param array<string,mixed> $hb
     * @param bool $library its program takes it from recorded lines, and someone has one
     */
    private function gate(array $hb, bool $library = false): ?string
    {
        $c = $this->app->config;
        if (!$library && !$this->available()) return 'unavailable';
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
        // call, and someone sent it — it is neither stopped nor counted. A
        // recorded line costs no voice: neither stopped nor counted either.
        if (!$theirs && !$library) {
            $today = (int) $this->app->store()->value(
                "SELECT COUNT(*) FROM host_breaks WHERE channel_id = ? AND state = 'ready' AND updated >= ? AND source NOT IN ('listener', 'moderator', 'library')",
                [(int) $hb['channel_id'], $this->app->clock->now() - 86400],
            );
            if ($today >= $c->int('HOST_MAX_BREAKS_PER_DAY', 300)) return 'daily_cap';
        }
        return null;
    }

    /** @param array<string,mixed> $hb */
    private function libraryCovers(array $hb): bool
    {
        $program = $hb['program_id'] !== null ? $this->app->catalog()->program((int) $hb['program_id']) : null;
        $channel = $this->app->catalog()->channel((int) $hb['channel_id']);
        return $channel !== null && $this->app->lines()->covers($channel, $program, (string) $hb['kind']);
    }

    /**
     * The moment from a recorded line (Host\Lines::pick): its words and
     * clips, ready at once. No `next_uid`: a line names no song, so the
     * committer must not drop it when the next one changes. False: nothing
     * fits (its host has no line for now, or the moment needs fresh words) —
     * the fresh way, and the library is asked to fill up.
     *
     * @param array<string,mixed> $hb
     */
    private function fromLibrary(array $hb): bool
    {
        $lines = $this->app->lines();
        $program = $hb['program_id'] !== null ? $this->app->catalog()->program((int) $hb['program_id']) : null;
        $channel = $this->app->catalog()->channel((int) $hb['channel_id']);
        if ($channel === null) return false;
        $host = $lines->hostFor($hb, $channel, $program);
        $context = $host !== null ? $this->app->hostWriter()->context($hb, $host) : [];
        $line = $host !== null && Lines::fits((string) $hb['kind'], $context) ? $lines->pick($hb, $host, $context, $channel, $program) : null;
        if ($host === null || $line === null) {
            if ($host !== null) $this->app->jobs()->enqueue('lines', (int) $host['id'], 70, $this->app->clock->nowMs());
            return false;
        }
        $texts = array_intersect_key($line['texts'], $line['audio']);
        $ctx = ['host_id' => (int) $host['id'], 'line_id' => (int) $line['id']] + array_diff_key($hb['context'], array_flip([...self::WRITTEN, 'next_uid']));
        // Cancelled meanwhile (a plan change, a deleted account): done all the same.
        $this->saveIfPending($hb['id'], [
            'context' => json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'texts' => json_encode($texts ?: new \stdClass(), JSON_UNESCAPED_UNICODE),
            'audio' => json_encode($line['audio'] ?: new \stdClass(), JSON_UNESCAPED_SLASHES),
            'durations' => json_encode($line['durations'] ?: new \stdClass()),
            'source' => 'library',
            'state' => 'ready',
        ]);
        return true;
    }

    /**
     * A break's own clips go (here and at the edge); a recorded line's files
     * are shared by every moment that airs it (Host\Lines) and stay.
     *
     * @param array<mixed> $audio
     */
    private function deleteClips(array $audio): void
    {
        foreach ($audio as $url) {
            if (is_string($url) && str_starts_with($url, '/media/host/')) $this->app->media()->delete($url);
        }
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

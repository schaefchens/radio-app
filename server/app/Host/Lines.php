<?php
declare(strict_types=1);

namespace Arche\Host;

use Arche\ApiError;
use Arche\App;
use Arche\Ai\Voice;
use Arche\Ai\VoiceError;
use Arche\Audio\Mp3;
use Arche\Support\BudgetExceeded;

/**
 * Recorded host lines: per host, a library of things it says again and again
 * — a program's welcome and goodbye, a prayer hour's announcements, the
 * encouragement in its prayer time, the invitation after requests read out,
 * a generic word between songs — written once (by the text model, or a
 * moderator), recorded once in the host's own voice (OpenAI or ElevenLabs)
 * and then picked by the AI for each moment where its program wants that.
 * A picked line costs no voice call: it airs even when no voice can speak
 * (a host resting, a provider gone), and a line recorded today keeps playing
 * after its provider retires the model.
 *
 * Lines never carry listeners' words: readings, requests, a moderator's
 * opening prayer and video introductions stay fresh. They are the host's own
 * words, so the host never prays in them either (HostWriter::prays()).
 *
 * The files live under /media/lines, named per line and content: never in
 * media/host, whose clips are pruned after 48 h and deleted with their break.
 */
final class Lines
{
    public const KINDS = ['intro', 'outro', 'encourage', 'present', 'prayertime', 'prayer', 'break'];
    /** Kinds whose lines name their program: they belong to it. */
    public const PROGRAM_KINDS = ['intro', 'outro', 'present', 'prayertime'];
    /** What a program takes from the library when switched on without a choice. */
    public const DEFAULT_KINDS = ['intro', 'outro', 'encourage', 'present', 'prayertime', 'prayer'];
    /** `composed` (Stage 2) is reserved in the schema, not offered yet. */
    public const MODES = ['fresh', 'library'];
    public const TIMES = ['any', 'morning', 'afternoon', 'evening', 'night'];
    public const MOODS = ['calm', 'joyful', 'hopeful', 'reflective', 'warm'];
    public const STATES = ['draft', 'recording', 'active', 'paused', 'failed', 'removed'];
    public const ACTIONS = ['pause', 'resume', 'approve', 'rerecord', 'remove'];
    private const DEFAULT_OPTIONS = [
        'refill' => true,
        'live' => true,
        'targets' => ['intro' => 8, 'outro' => 6, 'encourage' => 30, 'present' => 6, 'prayertime' => 6, 'prayer' => 12, 'break' => 30],
        'rest_hours' => 72,
        'old_voice' => false,
    ];
    /** An OpenAI host may record this many characters a month unless told otherwise (≈ $1.70). An ElevenLabs host: none — its account is a small one. */
    private const OPENAI_MONTH_CHARS = 100_000;
    /** A voice worker's host (our own Mac, no bill): a whole library and more. */
    private const WORKER_MONTH_CHARS = 2_000_000;
    /** A line a worker could not speak this often (each after its own takes) is marked failed. */
    private const WORKER_FAILS = 2;
    /** Lines a pick chooses from: enough to fit the moment, few enough to stay cheap. */
    private const MAX_PICK = 12;
    /** Lines one refill writes; a moderator may ask for up to WRITE_MAX. */
    private const WRITE_BATCH = 6;
    private const WRITE_MAX = 10;
    /** Lines of the pool shown to the writer, so it does not repeat them. */
    private const WRITE_SHOWN = 40;
    private const REQUEST_TRIES = 3;
    private const JOB_PRIORITY = 70;
    /** What each kind is, for the writer. */
    private const MEANS = [
        'intro' => 'The welcome when the program begins: greet the listeners, name the program by its title and say briefly what it is about. For a prayer hour: say it is a time to pray for one another, and that listeners can share their prayer request now with the button in the app.',
        'outro' => 'The goodbye when the program ends: thank the listeners for this time and name the program by its title. Do not name what comes next.',
        'encourage' => 'In the prayer time of a prayer hour, after a quiet while: invite everyone to pray for the requests on the prayer wall and for one another. Never pick out one request, and never promise a button or another way to send something.',
        'present' => 'In a prayer hour, right before the station reads out the prayer requests word for word: introduce them in a sentence or two. Never invent a request and never give a number.',
        'prayertime' => 'In a prayer hour, after the requests were read out: announce the prayer time. Now everyone prays, wherever they are; prayers can be sent with the Pray button in the app, spoken or written, and are shared on air.',
        'prayer' => 'Right after one or more listeners\' prayer requests were read out: invite everyone to pray for them, and say they can pray along on the prayer wall in the app. Never name anyone and never give a number.',
        'break' => 'A short moment between two songs that fits any songs: a welcome, a thought, an encouragement.',
    ];

    /** What a moment's context says about listeners (HostWriter::context), and the song pin: never sent to a pick. */
    private const NOT_FOR_PICK = ['request', 'contribution', 'previous_request', 'prayers', 'community', 'next_uid'];

    /** @var array<string,bool> */
    private array $has = [];

    public function __construct(private App $app) {}

    // --- programs ---------------------------------------------------------------------

    /** @return array{mode:string,kinds:list<string>} */
    public function programMode(int $programId): array
    {
        $row = $this->app->store()->one('SELECT mode, kinds FROM program_lines WHERE program_id = ?', [$programId]);
        if ($row === null) return ['mode' => 'fresh', 'kinds' => self::DEFAULT_KINDS];
        $kinds = json_decode((string) $row['kinds'], true);
        return ['mode' => (string) $row['mode'], 'kinds' => array_values(array_intersect(self::KINDS, is_array($kinds) ? $kinds : []))];
    }

    /** A program's mode, from /mod (Catalog::saveProgram, only when sent). @param array<mixed> $data */
    public function setProgramMode(int $programId, array $data): void
    {
        $mode = (string) ($data['mode'] ?? 'fresh');
        $kinds = $data['kinds'] ?? self::DEFAULT_KINDS;
        if (!in_array($mode, self::MODES, true) || !is_array($kinds)) throw new ApiError(422, 'lines_mode');
        $kinds = array_map('strval', $kinds);
        if (array_diff($kinds, self::KINDS)) throw new ApiError(422, 'lines_mode');
        $this->app->store()->query(
            'INSERT INTO program_lines (program_id, mode, kinds, updated) VALUES (?, ?, ?, ?)
             ON CONFLICT(program_id) DO UPDATE SET mode = excluded.mode, kinds = excluded.kinds, updated = excluded.updated',
            [$programId, $mode, json_encode(array_values(array_intersect(self::KINDS, $kinds))), $this->app->clock->now()],
        );
        $this->has = [];
    }

    /** Whether this program takes moments of this kind from its hosts' lines. @param array<string,mixed>|null $program */
    public function usesLibrary(?array $program, string $kind): bool
    {
        if ($program === null || !in_array($kind, self::KINDS, true)) return false;
        $m = $this->programMode((int) $program['id']);
        return $m['mode'] === 'library' && in_array($kind, $m['kinds'], true);
    }

    /**
     * Whether a moment of this kind can come from the lines of someone in the
     * program's lineup — local checks only, the publish phase asks.
     *
     * @param array<string,mixed> $channel
     * @param array<string,mixed>|null $program
     */
    public function covers(array $channel, ?array $program, string $kind): bool
    {
        if (!$this->usesLibrary($program, $kind)) return false;
        $l = $this->app->hosts()->effective($channel, $program);
        foreach ([...$l['mains'], ...$l['fallbacks']] as $h) {
            if ($this->has($h, $kind, $program !== null ? (int) $program['id'] : null)) return true;
        }
        return false;
    }

    // --- options ------------------------------------------------------------------------

    /** @param array<string,mixed> $host @return array<string,mixed> */
    public function options(array $host): array
    {
        $raw = $this->app->store()->value('SELECT data FROM host_line_options WHERE host_id = ?', [(int) $host['id']]);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        return self::cleanOptions(is_array($data) ? $data : [], (string) $host['provider']);
    }

    /** @param array<mixed> $in @return array<string,mixed> */
    private static function cleanOptions(array $in, string $provider): array
    {
        $o = self::DEFAULT_OPTIONS + ['month_chars' => match ($provider) {
            'openai' => self::OPENAI_MONTH_CHARS,
            'worker' => self::WORKER_MONTH_CHARS,
            default => 0,
        }];
        foreach (['refill', 'live', 'old_voice'] as $k) {
            if (array_key_exists($k, $in)) $o[$k] = (bool) $in[$k];
        }
        if (isset($in['rest_hours'])) $o['rest_hours'] = max(1, min(720, (int) $in['rest_hours']));
        if (isset($in['month_chars'])) $o['month_chars'] = max(0, min(5_000_000, (int) $in['month_chars']));
        if (is_array($in['targets'] ?? null)) {
            foreach (self::KINDS as $k) {
                if (isset($in['targets'][$k])) $o['targets'][$k] = max(0, min(200, (int) $in['targets'][$k]));
            }
        }
        return $o;
    }

    /**
     * A host's options from /mod (admins) — only the keys sent.
     *
     * @param array<mixed> $data
     * @return array<string,mixed>
     */
    public function setOptions(int $hostId, array $data, string $actor): array
    {
        $host = $this->app->hosts()->get($hostId) ?? throw new ApiError(404, 'not_found');
        foreach (['refill', 'live', 'old_voice'] as $k) {
            if (array_key_exists($k, $data) && !is_bool($data[$k])) throw new ApiError(422, 'line_options');
        }
        foreach (['rest_hours', 'month_chars'] as $k) {
            if (array_key_exists($k, $data) && !is_int($data[$k])) throw new ApiError(422, 'line_options');
        }
        if (array_key_exists('targets', $data) && !is_array($data['targets'])) throw new ApiError(422, 'line_options');
        $before = $this->options($host);
        $next = self::cleanOptions(array_replace_recursive($before, array_intersect_key($data, self::DEFAULT_OPTIONS + ['month_chars' => 0])), (string) $host['provider']);
        $this->app->store()->query(
            'INSERT INTO host_line_options (host_id, data, updated) VALUES (?, ?, ?)
             ON CONFLICT(host_id) DO UPDATE SET data = excluded.data, updated = excluded.updated',
            [$hostId, json_encode($next), $this->app->clock->now()],
        );
        $changed = array_keys(array_filter($next, fn($v, $k) => $before[$k] !== $v, ARRAY_FILTER_USE_BOTH));
        $this->app->store()->audit($actor, 'Line options changed', $hostId . ' ' . $host['name'] . ($changed ? ': ' . implode(', ', $changed) : ''));
        $this->has = [];
        return $next;
    }

    // --- the voice a line was recorded with ---------------------------------------------

    /**
     * Who a line sounds like: provider ('stub' in stub mode, so a stub
     * recording never airs live), model, voice per language, direction and
     * settings. Not `hosts.updated`: a new name or picture changes no voice.
     *
     * @param array<string,mixed> $host
     */
    public function signature(array $host): string
    {
        $voices = [];
        foreach ($this->app->config->stationLangs() as $l) $voices[$l] = Hosts::voiceFor($host, $l);
        $provider = (string) $host['provider'];
        return substr(hash('sha256', (string) json_encode([
            // A worker is never stubbed (our own hardware): its recordings are real in every mode.
            $this->app->config->stubAi() && $provider !== 'worker' ? 'stub' : $provider,
            (string) $host['model'],
            $voices,
            $provider === 'openai' ? trim((string) ($host['instructions'] ?? '')) : '',
            Hosts::settings($provider, (array) $host['settings']),
        ])), 0, 16);
    }

    // --- picking ---------------------------------------------------------------------------

    /** Whether a host has a line that could air for this kind and program now (local). @param array<string,mixed> $host */
    public function has(array $host, string $kind, ?int $programId): bool
    {
        $key = $host['id'] . ':' . $kind . ':' . ($programId ?? 0);
        return $this->has[$key] ??= $this->eligible($host, $kind, $programId, 1) !== [];
    }

    /**
     * Active lines of a host for a kind — the program's own, then generic
     * ones — recorded with its current voice (unless old voices are allowed).
     *
     * @param array<string,mixed> $host
     * @return list<array<string,mixed>>
     */
    private function eligible(array $host, string $kind, ?int $programId, int $limit = 400): array
    {
        $opts = $this->options($host);
        $voice = $opts['old_voice'] ? null : $this->signature($host);
        $rows = $this->app->store()->all(
            "SELECT * FROM host_lines WHERE host_id = ? AND kind = ? AND state = 'active' AND part = 'whole'
               AND (program_id IS NULL OR program_id = ?) AND (? IS NULL OR voice = ?)
             ORDER BY program_id IS NULL, last_aired IS NOT NULL, last_aired, id LIMIT ?",
            [(int) $host['id'], $kind, $programId ?? 0, $voice, $voice, $limit],
        );
        // An intro or outro names its program: another program's never fits.
        return array_map([self::class, 'decode'], array_values(array_filter($rows, fn($r) => $r['program_id'] === null || (int) $r['program_id'] === $programId)));
    }

    /**
     * Who speaks a moment from the library: the show's host when it has lines
     * for it (one host per show — no lines, its fresh voice instead); when
     * nobody in the lineup can speak, a lineup host with lines, so the
     * station's repeating moments still air.
     *
     * @param array<string,mixed> $hb
     * @param array<string,mixed> $channel
     * @param array<string,mixed>|null $program
     * @return array<string,mixed>|null
     */
    public function hostFor(array $hb, array $channel, ?array $program): ?array
    {
        $kind = (string) $hb['kind'];
        $pid = $program !== null ? (int) $program['id'] : null;
        $hosts = $this->app->hosts();
        $speaker = $hosts->forBreak($hb);
        if ($speaker !== null) return $this->has($speaker, $kind, $pid) ? $speaker : null;
        $l = $hosts->effective($channel, $program);
        foreach ([...$l['mains'], ...$l['fallbacks']] as $h) {
            if ($this->has($h, $kind, $pid)) return $h;
        }
        return null;
    }

    /**
     * Whether a generic line can stand for this moment: a prayer hour's
     * welcome names who prays the opening prayer — no recorded line knows.
     *
     * @param array<string,mixed> $context HostWriter::context()
     */
    public static function fits(string $kind, array $context): bool
    {
        return !($kind === 'intro' && trim((string) ($context['opening_by'] ?? '')) !== '');
    }

    /**
     * The line for this moment: among those that fit the time of day (an
     * evening line says "heute Abend") and have rested long enough while
     * others have, the text model picks the one that fits best; without it
     * (no key, the day's budget spent, an error) the one aired longest ago.
     *
     * @param array<string,mixed> $hb
     * @param array<string,mixed> $host
     * @param array<string,mixed> $context HostWriter::context()
     * @param array<string,mixed> $channel
     * @param array<string,mixed>|null $program
     * @return array<string,mixed>|null
     */
    public function pick(array $hb, array $host, array $context, array $channel, ?array $program): ?array
    {
        $kind = (string) $hb['kind'];
        $now = $this->app->clock->nowMs();
        $period = $this->period($channel, $now);
        $fitting = array_values(array_filter(
            $this->eligible($host, $kind, $program !== null ? (int) $program['id'] : null),
            fn($l) => in_array($l['tags']['time'], ['any', $period], true) && $this->complete($l),
        ));
        if (!$fitting) return null;
        $restMs = (int) $this->options($host)['rest_hours'] * 3_600_000;
        $rested = array_values(array_filter($fitting, fn($l) => $l['last_aired'] === null || $now - $l['last_aired'] >= $restMs));
        $pool = array_slice($rested ?: $fitting, 0, self::MAX_PICK);
        if (count($pool) === 1) return $pool[0];

        $byId = [];
        $shown = [];
        foreach ($pool as $l) {
            $byId[(string) $l['id']] = $l;
            $shown[] = [
                'id' => (string) $l['id'],
                'text' => $l['texts'],
                'time' => $l['tags']['time'],
                'mood' => $l['tags']['mood'],
                'aired_hours_ago' => $l['last_aired'] !== null ? intdiv($now - $l['last_aired'], 3_600_000) : null,
            ];
        }
        $name = (string) $host['name'];
        $result = $this->app->text()->json(
            'lines_pick',
            'host',
            "You choose what {$name}, a host of the Christian community radio ARCHE, says next. Every line below was recorded in advance. Pick the one that fits this moment best: the program, the time of day, the songs around it and the mood. Prefer a line that was not aired recently. Answer with its id.",
            // The lines first; the moment without listeners' names and words, which no pick needs.
            json_encode(['lines' => $shown, 'moment' => array_diff_key(HostWriter::forModel($context), array_flip(self::NOT_FOR_PICK))], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            ['type' => 'object', 'properties' => ['id' => ['type' => 'string', 'enum' => array_keys($byId)]], 'required' => ['id'], 'additionalProperties' => false],
            1024,
        );
        $id = $result->ok() ? (string) ($result->data['id'] ?? '') : '';
        return $byId[$id] ?? $pool[0];
    }

    /** Every language that has a text also has its clip. @param array<string,mixed> $line */
    private function complete(array $line): bool
    {
        $any = false;
        foreach ($this->app->config->stationLangs() as $l) {
            if (trim((string) ($line['texts'][$l] ?? '')) === '') continue;
            if (!is_string($line['audio'][$l] ?? null) || $line['audio'][$l] === '') return false;
            $any = true;
        }
        return $any;
    }

    /** The part of the day at the channel, as lines are tagged. @param array<string,mixed> $channel */
    private function period(array $channel, int $ms): string
    {
        $hour = (int) (new \DateTimeImmutable('@' . intdiv($ms, 1000)))->setTimezone($this->app->resolver()->zone($channel))->format('G');
        return match (true) {
            $hour >= 5 && $hour < 11 => 'morning',
            $hour >= 11 && $hour < 17 => 'afternoon',
            $hour >= 17 && $hour < 22 => 'evening',
            default => 'night',
        };
    }

    /** Committed on air (Committer): counted, and resting from now. */
    public function aired(int $lineId, int $atMs): void
    {
        $this->app->store()->query('UPDATE host_lines SET uses = uses + 1, last_aired = ? WHERE id = ?', [$atMs, $lineId]);
        $this->has = [];
    }

    // --- writing and recording (job `lines`, one per host) --------------------------------

    /** Hosts with lines to write or record get a job (every 10 minutes, from the tick). */
    public function queue(): void
    {
        foreach ($this->app->hosts()->all() as $host) {
            if ($host['active'] !== 1) continue;
            if ($this->requests((int) $host['id']) || ($this->room($host) > 0 && ($this->waiting($host) || $this->shortfall($host) !== null))) {
                $this->app->jobs()->enqueue('lines', (int) $host['id'], self::JOB_PRIORITY, $this->app->clock->nowMs());
            }
        }
    }

    /**
     * Write first (a moderator's request, else the pool furthest below its
     * target), then record — one call per phase, like every job.
     *
     * @param array<string,mixed> $job
     */
    public function runPhase(array $job): ?string
    {
        $host = $this->app->hosts()->get((int) $job['ref_id']);
        if ($host === null || $host['active'] !== 1) return null;
        if ($job['phase'] === 'record') return $this->record($host);
        $this->write($host);
        return $this->waiting($host) ? 'record' : null;
    }

    /**
     * The pool a refill writes for: a kind (and program) a library program of
     * this host uses, furthest below its target.
     *
     * @param array<string,mixed> $host
     * @return array{kind:string,program_id:?int,count:int}|null
     */
    private function shortfall(array $host): ?array
    {
        $opts = $this->options($host);
        if (!$opts['refill']) return null;
        $best = null;
        foreach ($this->pools($host, true) as $p) {
            $missing = (int) $opts['targets'][$p['kind']] - $p['active'] - $p['waiting'];
            if ($missing > 0 && ($best === null || $missing > $best['count'])) $best = ['kind' => $p['kind'], 'program_id' => $p['program_id'], 'count' => $missing];
        }
        return $best;
    }

    /** @param array<string,mixed> $host */
    private function write(array $host): void
    {
        $id = (int) $host['id'];
        $requests = $this->requests($id);
        $request = array_shift($requests);
        if ($request === null) {
            $short = $this->shortfall($host);
            if ($short === null || $this->room($host) <= 0) return;
            $request = $short + ['hint' => '', 'by' => 'refill', 'tries' => 0];
            $request['count'] = min(self::WRITE_BATCH, $request['count']);
        }
        $this->app->store()->set('lines_requests:' . $id, $requests);

        $kind = (string) $request['kind'];
        $program = $request['program_id'] !== null ? $this->app->catalog()->program((int) $request['program_id']) : null;
        $existing = array_column($this->app->store()->all(
            "SELECT texts FROM host_lines WHERE host_id = ? AND kind = ? AND state != 'removed' AND (program_id IS NULL OR program_id = ?) ORDER BY id DESC LIMIT " . self::WRITE_SHOWN,
            [$id, $kind, $request['program_id'] ?? 0],
        ), 'texts');
        $langs = $this->app->config->stationLangs();
        $props = [];
        foreach ($langs as $l) {
            $props[$l] = ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text'], 'additionalProperties' => false];
        }
        $schema = ['type' => 'object', 'properties' => ['lines' => ['type' => 'array', 'items' => [
            'type' => 'object',
            'properties' => $props + ['time' => ['type' => 'string', 'enum' => self::TIMES], 'mood' => ['type' => 'string', 'enum' => self::MOODS]],
            'required' => [...$langs, 'time', 'mood'],
            'additionalProperties' => false,
        ]]], 'required' => ['lines'], 'additionalProperties' => false];
        $task = [
            'task' => 'Write ' . (int) $request['count'] . ' different lines you can say in this kind of moment. Each is recorded once and played again on many days.',
            'moment' => self::MEANS[$kind] ?? $kind,
            'program' => $program !== null ? [
                'title' => ['en' => $program['title_en'], 'de' => $program['title_de']],
                // What it is about, never part of its name ("Welcome to Prayer Hour, We pray together").
                'description' => HostWriter::about($program) ?? ['en' => '', 'de' => ''],
                'format' => (string) ($program['settings']['format'] ?? 'music'),
                'themes' => $program['themes'] ?? [],
            ] : null,
            'rules' => [
                'Each line must make sense on any day it is played: no song titles, artists, listener names, dates, weekdays, numbers or news.',
                'The English line stays time-neutral (it is heard worldwide). The German line may name the time of day ("heute Abend"); then set `time` to that part of the day, else "any".',
                'Vary how the lines begin. Never repeat or closely rephrase a line in `existing`.',
                'You never pray in them: an invitation to pray is fine, a prayer, a blessing or an Amen is not.',
            ],
            'existing' => array_values(array_map(fn($t) => json_decode((string) $t, true), $existing)),
            'hint' => (string) ($request['hint'] ?? ''),
        ];
        $result = $this->app->text()->json(
            'lines_write',
            'host',
            $this->app->hostWriter()->system($host),
            json_encode($task, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            $schema,
            4096,
            'low',
        );
        if (!$result->ok()) {
            // Tried again on a later run, unless the model refused it; a moderator's request at most REQUEST_TRIES times.
            if ($request['by'] !== 'refill' && $result->reason !== 'refusal' && (int) $request['tries'] + 1 < self::REQUEST_TRIES) {
                $this->app->store()->set('lines_requests:' . $id, [['tries' => (int) $request['tries'] + 1] + $request, ...$requests]);
            }
            $this->app->store()->audit('host', 'Lines not written', $id . ' ' . $host['name'] . ' ' . $kind . ': ' . $result->reason);
            return;
        }
        $seen = array_map(fn($t) => self::norm(implode(' ', (array) json_decode((string) $t, true))), $existing);
        $added = 0;
        foreach (array_slice((array) ($result->data['lines'] ?? []), 0, self::WRITE_MAX) as $raw) {
            if (!is_array($raw)) continue;
            $texts = [];
            foreach ($langs as $l) $texts[$l] = self::clean((string) ($raw[$l]['text'] ?? ''));
            $ok = true;
            foreach ($texts as $t) {
                if ($t === '' || mb_strlen($t) > HostWriter::maxChars($kind) || HostWriter::prays($t)) $ok = false;
            }
            $norm = self::norm(implode(' ', $texts));
            if (!$ok || in_array($norm, $seen, true)) continue;
            $seen[] = $norm;
            $this->insert($host, [
                'kind' => $kind,
                'program_id' => $request['program_id'] !== null ? (int) $request['program_id'] : null,
                'texts' => $texts,
                'tags' => ['time' => in_array($raw['time'] ?? '', self::TIMES, true) ? $raw['time'] : 'any', 'mood' => in_array($raw['mood'] ?? '', self::MOODS, true) ? $raw['mood'] : ''],
                'source' => 'model',
                'created_by' => (string) $request['by'],
            ]);
            $added++;
        }
        $this->app->store()->audit('host', 'Lines written by AI', $id . ' ' . $host['name'] . ': ' . $added . ' ' . $kind . ($program !== null ? ' for ' . $program['title_en'] : ''));
    }

    /**
     * One clip of the oldest line waiting: the first language without one.
     * A voice changed between its languages starts the line over, so one
     * line never mixes two voices.
     *
     * @param array<string,mixed> $host
     */
    private function record(array $host): ?string
    {
        // A voice worker's host: every waiting language asked of the workers; their uploads finish the lines.
        if (Voice::async($host)) {
            $this->askWorkers($host);
            return null;
        }
        $row = $this->app->store()->one("SELECT * FROM host_lines WHERE host_id = ? AND state = 'recording' ORDER BY id LIMIT 1", [(int) $host['id']]);
        if ($row === null) return null;
        $line = self::decode($row);
        $voice = $this->signature($host);
        if ($line['audio'] && $line['voice'] !== $voice) {
            $this->deleteFiles($line['audio']);
            $line['audio'] = $line['durations'] = [];
            $this->save($line['id'], ['audio' => '{}', 'durations' => '{}']);
        }
        $lang = null;
        foreach ($this->app->config->stationLangs() as $l) {
            if (trim((string) ($line['texts'][$l] ?? '')) !== '' && !isset($line['audio'][$l])) {
                $lang = $l;
                break;
            }
        }
        if ($lang === null) {
            $this->finish($host, $line);
            return $this->waiting($host) ? 'record' : null;
        }
        $text = (string) $line['texts'][$lang];
        if ($this->room($host) < mb_strlen($text)) return null; // this month's allowance: the rest waits for the next
        try {
            $spoken = $this->app->voice()->speak($host, Speech::forVoice($text, $lang), $lang, null, self::usageKind((int) $host['id']));
            $url = $this->app->media()->put('lines', sprintf('%d-%s.%s.mp3', $line['id'], substr(hash('sha256', $spoken['bytes']), 0, 12), $lang), $spoken['bytes']);
            $check = Mp3::inspect((string) $this->app->media()->path($url));
            if (!$check['ok']) {
                $this->app->media()->delete($url);
                throw VoiceError::broken((string) $host['provider'], 'no playable audio');
            }
        } catch (BudgetExceeded $e) {
            throw $e;
        } catch (VoiceError $e) {
            $this->app->hosts()->failed($host, $e);
            // A temporary failure waits for the next run; a lasting one marks the line.
            if ($e->lasting()) $this->save($line['id'], ['state' => 'failed', 'error' => mb_substr(VoiceError::redact($e->getMessage()), 0, 300)]);
            return null;
        }
        $this->app->hosts()->succeeded($host);
        $line['audio'][$lang] = $url;
        $line['durations'][$lang] = $check['ms'];
        $set = ['audio' => json_encode($line['audio'], JSON_UNESCAPED_SLASHES), 'durations' => json_encode($line['durations']), 'voice' => $voice];
        if ($this->app->store()->update('host_lines', $set + ['updated' => $this->app->clock->now()], "id = ? AND state = 'recording'", [$line['id']]) !== 1) {
            // Removed or changed while it was spoken: this clip is nobody's.
            $this->app->media()->delete($url);
            return $this->waiting($host) ? 'record' : null;
        }
        if ($this->complete($line)) $this->finish($host, $line);
        return $this->waiting($host) ? 'record' : null;
    }

    /**
     * A worker host's waiting lines: each language without a clip and not
     * asked for yet goes to the workers, within this month's allowance. A
     * language the workers gave up on WORKER_FAILS times marks its line failed
     * (Qwen could not read it), so it is not asked for again and again.
     *
     * @param array<string,mixed> $host
     */
    private function askWorkers(array $host): void
    {
        $workers = $this->app->workers();
        $voice = $this->signature($host);
        $room = $this->room($host);
        foreach ($this->app->store()->all("SELECT * FROM host_lines WHERE host_id = ? AND state = 'recording' ORDER BY id LIMIT 50", [(int) $host['id']]) as $row) {
            $line = self::decode($row);
            $tasks = $workers->tasksFor('line', $line['id']);
            foreach ($this->app->config->stationLangs() as $l) {
                $text = trim((string) ($line['texts'][$l] ?? ''));
                if ($text === '' || isset($line['audio'][$l])) continue;
                $mine = array_filter($tasks, fn($t) => $t['lang'] === $l);
                if (array_filter($mine, fn($t) => in_array($t['state'], ['queued', 'leased'], true))) continue;
                if (count(array_filter($mine, fn($t) => $t['state'] === 'failed')) >= self::WORKER_FAILS) {
                    $this->save($line['id'], ['state' => 'failed', 'error' => 'The computer could not read it as written.']);
                    continue 2;
                }
                if ($room < mb_strlen($text)) return; // this month's allowance: the rest waits for the next
                $workers->request('line', $line['id'], $host, $l, Speech::forVoice($text, $l), 0, ['signature' => $voice]);
                $room -= mb_strlen($text);
            }
        }
    }

    /**
     * A language of a line arrived from a voice worker (Host\Workers::complete):
     * merged in one statement (both languages may arrive at once). Recorded
     * with another voice than its other language (the host changed meanwhile):
     * the other goes, and is asked for again. False: the line is not
     * waiting any more (removed, changed) — the caller drops the clip.
     */
    public function recorded(int $lineId, string $lang, string $url, int $ms, string $voice): bool
    {
        $line = $this->get($lineId);
        if ($line === null || $line['state'] !== 'recording') return false;
        if ($line['audio'] && $voice !== '' && $line['voice'] !== '' && $line['voice'] !== $voice) {
            $this->deleteFiles($line['audio']);
            $this->app->store()->update('host_lines', ['audio' => '{}', 'durations' => '{}'], "id = ? AND state = 'recording'", [$lineId]);
        }
        $saved = $this->app->store()->query(
            "UPDATE host_lines SET audio = json_set(audio, '$.' || ?, ?), durations = json_set(durations, '$.' || ?, ?), voice = ?, updated = ? WHERE id = ? AND state = 'recording'",
            [$lang, $url, $lang, $ms, $voice !== '' ? $voice : $line['voice'], $this->app->clock->now(), $lineId],
        )->rowCount() === 1;
        if (!$saved) return false;
        $line = $this->get($lineId);
        $host = $this->app->hosts()->get($line['host_id'] ?? 0);
        if ($line !== null && $host !== null && $this->complete($line)) $this->finish($host, $line);
        $this->has = [];
        return true;
    }

    /** Every language recorded: on air, or waiting for a moderator's approval. @param array<string,mixed> $host @param array<string,mixed> $line */
    private function finish(array $host, array $line): void
    {
        $state = $this->options($host)['live'] ? 'active' : 'draft';
        $this->app->store()->update('host_lines', ['state' => $state, 'error' => '', 'updated' => $this->app->clock->now()], "id = ? AND state = 'recording'", [$line['id']]);
        $this->has = [];
    }

    /** Characters this host may still record this month. @param array<string,mixed> $host */
    public function room(array $host): int
    {
        return max(0, (int) $this->options($host)['month_chars'] - $this->monthChars((int) $host['id']));
    }

    public function monthChars(int $hostId): int
    {
        return (int) $this->app->store()->value(
            'SELECT COALESCE(SUM(input_tokens), 0) FROM ai_usage WHERE kind = ? AND day >= ?',
            [self::usageKind($hostId), gmdate('Y-m-01', $this->app->clock->now())],
        );
    }

    public static function usageKind(int $hostId): string
    {
        return 'tts:lines:' . $hostId;
    }

    /** @param array<string,mixed> $host */
    private function waiting(array $host): bool
    {
        return $this->app->store()->value("SELECT 1 FROM host_lines WHERE host_id = ? AND state = 'recording' LIMIT 1", [(int) $host['id']]) !== null;
    }

    /** @return list<array<string,mixed>> */
    private function requests(int $hostId): array
    {
        return array_values(array_filter((array) ($this->app->store()->get('lines_requests:' . $hostId) ?? []), 'is_array'));
    }

    // --- /mod ---------------------------------------------------------------------------------

    /**
     * Lines for the moderation list.
     *
     * @param array<string,mixed> $q host, kind, program (id or 'generic'), state (or 'old_voice'), q, sort
     * @return array{lines:list<array<string,mixed>>,total:int}
     */
    public function list(array $q, int $limit, int $offset): array
    {
        $host = $this->app->hosts()->get((int) ($q['host'] ?? 0)) ?? throw new ApiError(404, 'not_found');
        $where = ['host_id = ?'];
        $args = [(int) $host['id']];
        $kind = (string) ($q['kind'] ?? '');
        if ($kind !== '') {
            $where[] = 'kind = ?';
            $args[] = $kind;
        }
        $program = (string) ($q['program'] ?? '');
        if ($program === 'generic') {
            $where[] = 'program_id IS NULL';
        } elseif ($program !== '') {
            $where[] = 'program_id = ?';
            $args[] = (int) $program;
        }
        $state = (string) ($q['state'] ?? '');
        if ($state === 'old_voice') {
            $where[] = "state IN ('active', 'paused', 'draft') AND voice != ?";
            $args[] = $this->signature($host);
        } elseif (in_array($state, self::STATES, true)) {
            $where[] = 'state = ?';
            $args[] = $state;
        } else {
            $where[] = "state != 'removed'";
        }
        $text = trim((string) ($q['q'] ?? ''));
        if ($text !== '') {
            $where[] = "texts LIKE ? ESCAPE '\\'";
            $args[] = '%' . addcslashes($text, '%_\\') . '%';
        }
        $order = match ((string) ($q['sort'] ?? '')) {
            'most' => 'uses DESC, id DESC',
            'least' => 'uses, id DESC',
            default => 'id DESC',
        };
        $sql = implode(' AND ', $where);
        $store = $this->app->store();
        $total = (int) $store->value("SELECT COUNT(*) FROM host_lines WHERE $sql", $args);
        // "Load more" in /mod asks for a longer page, not the next one.
        $rows = $store->all("SELECT * FROM host_lines WHERE $sql ORDER BY $order LIMIT ? OFFSET ?", [...$args, max(1, min(500, $limit)), max(0, $offset)]);
        $voice = $this->signature($host);
        return ['lines' => array_map(fn($r) => self::view(self::decode($r), $voice), $rows), 'total' => $total];
    }

    /**
     * A host's library at a glance: its pools against their targets, this
     * month's recording, its options and the programs it speaks in.
     *
     * @param array<string,mixed> $host
     * @return array<string,mixed>
     */
    public function overview(array $host, bool $admin): array
    {
        $programs = [];
        foreach ($this->programsOf($host) as $p) {
            $m = $this->programMode((int) $p['id']);
            $programs[] = [
                'id' => (int) $p['id'],
                'channel_id' => (int) $p['channel_id'],
                'title' => ['en' => (string) $p['title_en'], 'de' => (string) $p['title_de']],
                'format' => (string) ($p['settings']['format'] ?? 'music'),
                'mode' => $m['mode'],
                'kinds' => $m['kinds'],
            ];
        }
        $opts = $this->options($host);
        return [
            'host' => [
                'id' => (int) $host['id'],
                'name' => (string) $host['name'],
                'color' => (string) $host['color'],
                'avatar' => $host['avatar'] ?: null,
                'provider' => (string) $host['provider'],
                'model' => (string) $host['model'],
                'voices' => (object) $host['voices'],
            ],
            'kinds' => self::KINDS,
            'program_kinds' => self::PROGRAM_KINDS,
            'programs' => $programs,
            'pools' => array_map(fn($p) => $p + ['target' => (int) $opts['targets'][$p['kind']]], $this->pools($host, false)),
            'month' => ['chars' => $this->monthChars((int) $host['id']), 'allowance' => (int) $opts['month_chars']],
            'options' => $opts,
            'queued' => array_sum(array_map(fn($r) => (int) ($r['count'] ?? 0), $this->requests((int) $host['id']))),
            'can_edit_options' => $admin,
        ];
    }

    /**
     * A host's pools: every generic kind once, every program kind per program
     * it speaks in. $used: only those a library program of its uses (refill).
     *
     * @param array<string,mixed> $host
     * @return list<array{kind:string,program_id:?int,active:int,waiting:int,failed:int,old_voice:int,aired_7d:int}>
     */
    private function pools(array $host, bool $used): array
    {
        $programs = $this->programsOf($host);
        $wanted = [];
        foreach (self::KINDS as $kind) {
            if (in_array($kind, self::PROGRAM_KINDS, true)) {
                foreach ($programs as $p) {
                    if (!$used || $this->usesLibrary($p, $kind)) $wanted[] = [$kind, (int) $p['id']];
                }
            } elseif (!$used || array_filter($programs, fn($p) => $this->usesLibrary($p, $kind))) {
                $wanted[] = [$kind, null];
            }
        }
        $voice = $this->signature($host);
        // A line in an earlier voice cannot air (unless kept): it fills no pool, a refill records anew.
        $anyVoice = $this->options($host)['old_voice'] ? 1 : 0;
        $since = $this->app->clock->now() - 7 * 86400;
        $out = [];
        foreach ($wanted as [$kind, $pid]) {
            $counts = $this->app->store()->one(
                "SELECT
                   SUM(state = 'active' AND (voice = ? OR ? = 1)) AS active, SUM(state IN ('draft', 'recording')) AS waiting, SUM(state = 'failed') AS failed,
                   SUM(state IN ('active', 'paused', 'draft') AND voice != ?) AS old_voice
                 FROM host_lines WHERE host_id = ? AND kind = ? AND program_id IS ?",
                [$voice, $anyVoice, $voice, (int) $host['id'], $kind, $pid],
            ) ?? [];
            $aired = (int) $this->app->store()->value(
                "SELECT COUNT(*) FROM host_breaks WHERE source = 'library' AND state = 'ready' AND updated >= ? AND json_extract(context, '$.line_id') IN
                   (SELECT id FROM host_lines WHERE host_id = ? AND kind = ? AND program_id IS ?)",
                [$since, (int) $host['id'], $kind, $pid],
            );
            $out[] = [
                'kind' => $kind,
                'program_id' => $pid,
                'active' => (int) ($counts['active'] ?? 0),
                'waiting' => (int) ($counts['waiting'] ?? 0),
                'failed' => (int) ($counts['failed'] ?? 0),
                'old_voice' => (int) ($counts['old_voice'] ?? 0),
                'aired_7d' => $aired,
            ];
        }
        return $out;
    }

    /**
     * The programs this host speaks in: those whose lineup (their own, their
     * channel's or the main channel's) has it.
     *
     * @param array<string,mixed> $host
     * @return list<array<string,mixed>>
     */
    private function programsOf(array $host): array
    {
        $out = [];
        $hosts = $this->app->hosts();
        foreach ($this->app->catalog()->channels(false) as $ch) {
            foreach ($this->app->catalog()->programs((int) $ch['id']) as $p) {
                $l = $hosts->effective($ch, $p);
                if (in_array((int) $host['id'], array_column([...$l['mains'], ...$l['fallbacks']], 'id'), true)) $out[] = $p;
            }
        }
        return $out;
    }

    /**
     * A line written by a moderator: recorded next.
     *
     * @param array<mixed> $data host_id, kind, program_id, texts, tags
     * @return array<string,mixed>
     */
    public function add(array $data, string $actor): array
    {
        $host = $this->app->hosts()->get((int) ($data['host_id'] ?? 0)) ?? throw new ApiError(422, 'line_host');
        $kind = self::kindOf($data['kind'] ?? null);
        $id = $this->insert($host, [
            'kind' => $kind,
            'program_id' => $this->programOf($kind, $data['program_id'] ?? null),
            'texts' => $this->textsOf($kind, $data['texts'] ?? null),
            'tags' => self::tagsOf($data['tags'] ?? []),
            'source' => 'moderator',
            'created_by' => $actor,
        ]);
        $this->app->jobs()->enqueue('lines', (int) $host['id'], self::JOB_PRIORITY, $this->app->clock->nowMs());
        $this->app->store()->audit($actor, 'Line added', $id . ' ' . $host['name'] . ' ' . $kind);
        return $this->viewOf($id);
    }

    /**
     * A line changed: its words (recorded again), tags, kind or program, or
     * paused, resumed, approved.
     *
     * @param array<mixed> $data
     * @return array<string,mixed>
     */
    public function update(int $id, array $data, string $actor): array
    {
        $line = $this->get($id) ?? throw new ApiError(404, 'not_found');
        if ($line['state'] === 'removed') throw new ApiError(404, 'not_found');
        $set = [];
        $kind = array_key_exists('kind', $data) ? self::kindOf($data['kind']) : $line['kind'];
        if ($kind !== $line['kind']) $set['kind'] = $kind;
        if (array_key_exists('program_id', $data) || $kind !== $line['kind']) {
            $pid = $this->programOf($kind, array_key_exists('program_id', $data) ? $data['program_id'] : $line['program_id']);
            if ($pid !== $line['program_id']) $set['program_id'] = $pid;
        }
        if (array_key_exists('tags', $data)) $set['tags'] = json_encode(self::tagsOf($data['tags']));
        $rerecord = false;
        if (array_key_exists('texts', $data)) {
            $texts = $this->textsOf($kind, $data['texts']);
            if ($texts !== $line['texts']) {
                $set['texts'] = json_encode($texts, JSON_UNESCAPED_UNICODE);
                $set['chars'] = self::chars($texts);
                $rerecord = true;
            }
        }
        if (array_key_exists('state', $data)) {
            $to = (string) $data['state'];
            $allowed = ['active' => ['paused', 'draft'], 'paused' => ['active']];
            if (!isset($allowed[$to]) || !in_array($line['state'], $allowed[$to], true)) {
                if ($to !== $line['state']) throw new ApiError(422, 'line_state');
            } else {
                $set['state'] = $to;
            }
        }
        if ($rerecord) {
            $this->deleteFiles($line['audio']);
            $set += ['audio' => '{}', 'durations' => '{}', 'state' => 'recording', 'error' => ''];
        }
        if ($set) {
            $this->save($id, $set);
            if ($rerecord) $this->app->jobs()->enqueue('lines', $line['host_id'], self::JOB_PRIORITY, $this->app->clock->nowMs());
            $this->app->store()->audit($actor, 'Line changed', $id . ': ' . implode(', ', array_keys($set)));
        }
        return $this->viewOf($id);
    }

    /** @param list<mixed> $ids */
    public function bulk(array $ids, string $action, string $actor): int
    {
        if (!in_array($action, self::ACTIONS, true)) throw new ApiError(422, 'line_action');
        if (!$ids || count($ids) > 200 || array_filter($ids, fn($i) => !is_int($i) || $i <= 0)) throw new ApiError(422, 'line_ids');
        $changed = 0;
        $hosts = [];
        foreach ($ids as $id) {
            $line = $this->get($id);
            if ($line === null || $line['state'] === 'removed') continue;
            $set = match ($action) {
                'pause' => $line['state'] === 'active' ? ['state' => 'paused'] : null,
                'resume' => $line['state'] === 'paused' ? ['state' => 'active'] : null,
                'approve' => $line['state'] === 'draft' ? ['state' => 'active'] : null,
                'rerecord' => ['audio' => '{}', 'durations' => '{}', 'state' => 'recording', 'error' => ''],
                'remove' => ['audio' => '{}', 'durations' => '{}', 'state' => 'removed'],
            };
            if ($set === null) continue;
            if (in_array($action, ['rerecord', 'remove'], true)) $this->deleteFiles($line['audio']);
            $this->save($id, $set);
            if ($action === 'rerecord') $hosts[$line['host_id']] = true;
            $changed++;
        }
        foreach (array_keys($hosts) as $hid) $this->app->jobs()->enqueue('lines', $hid, self::JOB_PRIORITY, $this->app->clock->nowMs());
        if ($changed) $this->app->store()->audit($actor, $action === 'remove' ? 'Lines removed' : 'Lines changed', $action . ': ' . $changed);
        return $changed;
    }

    public function remove(int $id, string $actor): void
    {
        if ($this->get($id) === null) throw new ApiError(404, 'not_found');
        $this->bulk([$id], 'remove', $actor);
    }

    /**
     * Lines a moderator asks the AI for: written (and recorded) by the next run.
     *
     * @param array<mixed> $data host_id, kind, program_id, count, hint
     */
    public function requestWrite(array $data, string $actor): int
    {
        $host = $this->app->hosts()->get((int) ($data['host_id'] ?? 0)) ?? throw new ApiError(422, 'line_host');
        $kind = self::kindOf($data['kind'] ?? null);
        $count = $data['count'] ?? null;
        if (!is_int($count) || $count < 1 || $count > self::WRITE_MAX) throw new ApiError(422, 'line_count');
        $hint = trim((string) ($data['hint'] ?? ''));
        if (mb_strlen($hint) > 200) throw new ApiError(422, 'line_hint');
        $requests = $this->requests((int) $host['id']);
        if (count($requests) >= 20) throw new ApiError(429, 'rate_limited');
        $requests[] = ['kind' => $kind, 'program_id' => $this->programOf($kind, $data['program_id'] ?? null), 'count' => $count, 'hint' => $hint, 'by' => $actor, 'tries' => 0];
        $this->app->store()->set('lines_requests:' . $host['id'], $requests);
        $this->app->jobs()->enqueue('lines', (int) $host['id'], self::JOB_PRIORITY, $this->app->clock->nowMs());
        $this->app->store()->audit($actor, 'Lines asked of the AI', $host['id'] . ' ' . $host['name'] . ': ' . $count . ' ' . $kind);
        return array_sum(array_map(fn($r) => (int) $r['count'], $requests));
    }

    public function activeCount(int $hostId): int
    {
        return (int) $this->app->store()->value("SELECT COUNT(*) FROM host_lines WHERE host_id = ? AND state = 'active'", [$hostId]);
    }

    // --- cleanup ------------------------------------------------------------------------------

    /** A host deleted: its lines' files go (the rows with it, ON DELETE CASCADE). */
    public function forgetHost(int $hostId): void
    {
        foreach ($this->app->store()->all('SELECT audio FROM host_lines WHERE host_id = ?', [$hostId]) as $r) $this->deleteFiles((array) json_decode((string) $r['audio'], true));
        $this->app->store()->query('DELETE FROM host_line_options WHERE host_id = ?', [$hostId]);
        $this->app->store()->query('DELETE FROM kv WHERE key = ?', ['lines_requests:' . $hostId]);
    }

    /** A program deleted: its own lines' files go (the rows with it). */
    public function forgetProgram(int $programId): void
    {
        foreach ($this->app->store()->all('SELECT audio FROM host_lines WHERE program_id = ?', [$programId]) as $r) $this->deleteFiles((array) json_decode((string) $r['audio'], true));
    }

    /** Removed lines' rows after a while (their files went when they were removed). */
    public function purge(int $beforeTs): int
    {
        return $this->app->store()->query("DELETE FROM host_lines WHERE state = 'removed' AND updated < ?", [$beforeTs])->rowCount();
    }

    // --- rows ---------------------------------------------------------------------------------

    /** @return array<string,mixed>|null */
    private function get(int $id): ?array
    {
        $row = $this->app->store()->one('SELECT * FROM host_lines WHERE id = ?', [$id]);
        return $row !== null ? self::decode($row) : null;
    }

    /** @return array<string,mixed> */
    private function viewOf(int $id): array
    {
        $line = $this->get($id) ?? throw new ApiError(404, 'not_found');
        $host = $this->app->hosts()->get($line['host_id']);
        return self::view($line, $host !== null ? $this->signature($host) : '');
    }

    /** @param array<string,mixed> $host @param array<string,mixed> $l */
    private function insert(array $host, array $l): int
    {
        $now = $this->app->clock->now();
        $id = $this->app->store()->insert('host_lines', [
            'host_id' => (int) $host['id'],
            'kind' => $l['kind'],
            'part' => 'whole',
            'program_id' => $l['program_id'],
            'texts' => json_encode($l['texts'], JSON_UNESCAPED_UNICODE),
            'tags' => json_encode($l['tags']),
            'state' => 'recording',
            'source' => $l['source'],
            'chars' => self::chars($l['texts']),
            'created_by' => mb_substr((string) $l['created_by'], 0, 80),
            'created' => $now,
            'updated' => $now,
        ]);
        $this->has = [];
        return $id;
    }

    /** @param array<string,mixed> $set */
    private function save(int $id, array $set): void
    {
        $this->app->store()->update('host_lines', $set + ['updated' => $this->app->clock->now()], 'id = ?', [$id]);
        $this->has = [];
    }

    /** @param array<mixed> $audio */
    private function deleteFiles(array $audio): void
    {
        foreach ($audio as $url) {
            if (is_string($url) && str_starts_with($url, '/media/lines/')) $this->app->media()->delete($url);
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function decode(array $row): array
    {
        foreach (['texts', 'audio', 'durations', 'tags'] as $k) {
            $v = json_decode((string) $row[$k], true);
            $row[$k] = is_array($v) ? $v : [];
        }
        $row['tags'] = ['time' => in_array($row['tags']['time'] ?? '', self::TIMES, true) ? $row['tags']['time'] : 'any', 'mood' => (string) ($row['tags']['mood'] ?? '')];
        foreach (['id', 'host_id', 'chars', 'uses', 'created', 'updated'] as $k) $row[$k] = (int) $row[$k];
        $row['program_id'] = $row['program_id'] !== null ? (int) $row['program_id'] : null;
        $row['last_aired'] = $row['last_aired'] !== null ? (int) $row['last_aired'] : null;
        return $row;
    }

    /** As /mod sees a line. @param array<string,mixed> $l @return array<string,mixed> */
    private static function view(array $l, string $voice): array
    {
        return [
            'id' => $l['id'],
            'host_id' => $l['host_id'],
            'kind' => (string) $l['kind'],
            'program_id' => $l['program_id'],
            'texts' => (object) $l['texts'],
            'audio' => (object) $l['audio'],
            'durations' => (object) $l['durations'],
            'tags' => $l['tags'],
            'state' => (string) $l['state'],
            'old_voice' => in_array($l['state'], ['active', 'paused', 'draft'], true) && $l['voice'] !== $voice,
            'source' => (string) $l['source'],
            'chars' => $l['chars'],
            'uses' => $l['uses'],
            'last_aired' => $l['last_aired'],
            'error' => (string) $l['error'],
            'note' => (string) $l['note'],
            'created' => $l['created'],
            'updated' => $l['updated'],
        ];
    }

    private static function kindOf(mixed $kind): string
    {
        if (!is_string($kind) || !in_array($kind, self::KINDS, true)) throw new ApiError(422, 'line_kind');
        return $kind;
    }

    /** A program kind needs its program; any kind may be kept to one. */
    private function programOf(string $kind, mixed $pid): ?int
    {
        if ($pid === null || $pid === '' || $pid === 0) {
            if (in_array($kind, self::PROGRAM_KINDS, true)) throw new ApiError(422, 'line_program');
            return null;
        }
        if (!is_int($pid) || $this->app->catalog()->program($pid) === null) throw new ApiError(422, 'line_program');
        return $pid;
    }

    /** @return array<string,string> */
    private function textsOf(string $kind, mixed $in): array
    {
        if (!is_array($in)) throw new ApiError(422, 'line_text');
        $texts = [];
        foreach ($this->app->config->stationLangs() as $l) {
            $t = self::clean((string) ($in[$l] ?? ''));
            if ($t === '') continue;
            if (mb_strlen($t) > HostWriter::maxChars($kind)) throw new ApiError(422, 'line_text');
            // The host's own words: a line may invite to prayer, never pray.
            if (HostWriter::prays($t)) throw new ApiError(422, 'line_prays');
            $texts[$l] = $t;
        }
        if (!$texts) throw new ApiError(422, 'line_text');
        return $texts;
    }

    /** @return array{time:string,mood:string} */
    private static function tagsOf(mixed $in): array
    {
        if (!is_array($in)) throw new ApiError(422, 'line_tags');
        $time = (string) ($in['time'] ?? 'any');
        $mood = (string) ($in['mood'] ?? '');
        if (!in_array($time, self::TIMES, true) || ($mood !== '' && !in_array($mood, self::MOODS, true))) throw new ApiError(422, 'line_tags');
        return ['time' => $time, 'mood' => $mood];
    }

    private static function clean(string $t): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', strip_tags($t)), " \"'“”„");
    }

    /** For spotting a line written twice: letters and digits only. */
    private static function norm(string $t): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($t));
    }

    /** @param array<string,string> $texts */
    private static function chars(array $texts): int
    {
        return array_sum(array_map('mb_strlen', $texts));
    }
}

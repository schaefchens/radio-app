<?php
declare(strict_types=1);

namespace Arche\Host;

use Arche\ApiError;
use Arche\App;
use Arche\Audio\Mp3;
use Arche\Program\Timing;
use Arche\Support\Ids;

/**
 * Voice workers: Macs of our own that speak for hosts with Qwen3-TTS
 * (provider `worker`, worker/README.md). They have no public address, so the
 * station never calls them: a worker polls for voice tasks over HTTPS,
 * speaks one, uploads the MP3 — and that upload finishes the moment, the
 * recorded line or the try. The tick never waits for a worker.
 *
 * A worker host speaks only while a worker that offers its voice has polled
 * lately (ONLINE_SECONDS); a Mac asleep is a host that cannot speak, and the
 * lineup's fallbacks take over as for any voice that fails.
 *
 * Qwen writes speech token by token and now and then repeats or skips a
 * word: every clip's length is checked against its text, and one that does
 * not fit is spoken again with another seed (MAX_TAKES), then given up — the
 * moment goes to the next host.
 *
 * A worker's key is shown once and kept as an HMAC with the identity pepper.
 * It travels in X-Arche-Worker-Key: an Authorization header never reaches PHP
 * on this host.
 */
final class Workers
{
    public const MODEL = 'qwen3-tts-1.7b-customvoice';
    /** A worker counts as online this long after its last poll (it polls every few seconds while idle). */
    public const ONLINE_SECONDS = 90;
    /** Takes of one task before it is given up (each a new seed). */
    private const MAX_TAKES = 2;
    /** A lease lasts LEASE_FACTOR × the expected length + LEASE_BASE, within these bounds (s). */
    private const LEASE_FACTOR = 3;
    private const LEASE_BASE = 60;
    private const LEASE_MAX = 600;
    /** A try's clip waits this long for the editor to fetch it (s). */
    private const TRY_KEEP = 600;
    /** Speech runs about 13 characters a second; a clip outside these bounds read something else. */
    private const MIN_CPS = 6.0;
    private const MAX_CPS = 30.0;
    /** Below this many characters a short clip is fine (a pause, a name). */
    private const SHORT_TEXT = 40;
    private const PRIORITY = ['try' => 5, 'break' => 10, 'line' => 50];

    public function __construct(private App $app) {}

    // --- workers (admins) ---------------------------------------------------------------------

    /** @return array{worker:array<string,mixed>,key:string} the key, shown this once */
    public function create(string $name, string $actor): array
    {
        $name = self::nameOf($name);
        $key = Ids::hex(32);
        $now = $this->app->clock->now();
        $id = $this->app->store()->insert('workers', [
            'name' => $name,
            'key_mac' => $this->mac($key),
            'key_hint' => substr($key, -4),
            'created' => $now,
            'updated' => $now,
        ]);
        $this->app->store()->audit($actor, 'Worker added', $id . ' ' . $name);
        return ['worker' => $this->view($this->get($id) ?? throw new \LogicException('worker vanished')), 'key' => $key];
    }

    /**
     * A worker renamed, switched off or on, or given a new key (the old one
     * stops working at once).
     *
     * @param array<mixed> $data name, active, rotate
     * @return array{worker:array<string,mixed>,key?:string}
     */
    public function update(int $id, array $data, string $actor): array
    {
        $w = $this->get($id) ?? throw new ApiError(404, 'not_found');
        $set = [];
        if (array_key_exists('name', $data)) $set['name'] = self::nameOf((string) $data['name']);
        if (array_key_exists('active', $data)) $set['active'] = $data['active'] ? 1 : 0;
        $key = null;
        if (!empty($data['rotate'])) {
            $key = Ids::hex(32);
            $set['key_mac'] = $this->mac($key);
            $set['key_hint'] = substr($key, -4);
        }
        if ($set) {
            $this->app->store()->update('workers', $set + ['updated' => $this->app->clock->now()], 'id = ?', [$id]);
            // Switched off: what it holds goes to another worker.
            if (($set['active'] ?? 1) === 0) $this->release($id);
            $what = array_keys(array_diff_key($set, ['key_mac' => 1, 'key_hint' => 1]));
            if ($key !== null) $what[] = 'new key';
            $this->app->store()->audit($actor, 'Worker changed', $id . ' ' . $w['name'] . ': ' . implode(', ', $what));
        }
        $out = ['worker' => $this->view($this->get($id) ?? throw new \LogicException('worker vanished'))];
        if ($key !== null) $out['key'] = $key;
        return $out;
    }

    public function delete(int $id, string $actor): void
    {
        $w = $this->get($id) ?? throw new ApiError(404, 'not_found');
        $this->release($id);
        $this->app->store()->query('DELETE FROM workers WHERE id = ?', [$id]);
        $this->app->store()->audit($actor, 'Worker removed', $id . ' ' . $w['name']);
    }

    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        return array_map(fn($r) => $this->view(self::decode($r)), $this->app->store()->all('SELECT * FROM workers ORDER BY name, id'));
    }

    /** As /mod sees a worker: never its key, only the key's end. @param array<string,mixed> $w @return array<string,mixed> */
    private function view(array $w): array
    {
        $today = gmdate('Y-m-d', $this->app->clock->now());
        $since = strtotime($today . ' 00:00:00 UTC');
        $count = fn(string $state) => (int) $this->app->store()->value('SELECT COUNT(*) FROM voice_tasks WHERE worker_id = ? AND state = ? AND updated >= ?', [$w['id'], $state, $since]);
        return [
            'id' => $w['id'],
            'name' => (string) $w['name'],
            'active' => $w['active'] === 1,
            'online' => $this->isOnline($w),
            'last_seen' => $w['last_seen'],
            'version' => (string) $w['version'],
            'engine' => (object) $w['engine'],
            'voices' => $w['voices'],
            'languages' => $w['languages'],
            'key_hint' => (string) $w['key_hint'],
            // `queued`: what this worker holds right now (a queued task belongs to no worker yet).
            'tasks' => ['done_today' => $count('done'), 'failed_today' => $count('failed'), 'queued' => (int) $this->app->store()->value("SELECT COUNT(*) FROM voice_tasks WHERE worker_id = ? AND state = 'leased'", [$w['id']])],
        ];
    }

    /** For /mod Status. @return array{online:int,total:int,queued:int} */
    public function summary(): array
    {
        $all = array_map([self::class, 'decode'], $this->app->store()->all('SELECT * FROM workers'));
        return ['online' => count(array_filter($all, fn($w) => $this->isOnline($w))), 'total' => count($all), 'queued' => $this->queued()];
    }

    private function queued(): int
    {
        return (int) $this->app->store()->value("SELECT COUNT(*) FROM voice_tasks WHERE state IN ('queued', 'leased')");
    }

    // --- who may speak ---------------------------------------------------------------------------

    /**
     * Whether a worker that can speak this voice (and model) has polled lately —
     * local, the publish phase asks (Hosts::canSpeak).
     */
    public function online(?string $voice = null, ?string $model = null): bool
    {
        foreach ($this->onlineWorkers() as $w) {
            if (self::offers($w, $voice, $model)) return true;
        }
        return false;
    }

    /**
     * The voices and models online workers offer, for the host editor.
     *
     * @return array{voices:list<array<string,mixed>>,models:list<array<string,mixed>>,online:int}
     */
    public function catalog(): array
    {
        $voices = [];
        $models = [];
        $workers = $this->onlineWorkers();
        foreach ($workers as $w) {
            foreach ($w['voices'] as $v) {
                $id = (string) ($v['id'] ?? '');
                if ($id !== '') $voices[$id] = ['id' => $id, 'name' => (string) ($v['label'] ?? $id), 'category' => 'qwen', 'labels' => '', 'languages' => $w['languages']];
            }
            $model = (string) ($w['engine']['model'] ?? '');
            if ($model !== '') $models[$model] = ['id' => $model, 'name' => $model, 'languages' => $w['languages'], 'cost' => 0];
        }
        return ['voices' => array_values($voices), 'models' => array_values($models), 'online' => count($workers)];
    }

    /** @return list<array<string,mixed>> */
    private function onlineWorkers(): array
    {
        $rows = $this->app->store()->all('SELECT * FROM workers WHERE active = 1 AND last_seen >= ?', [$this->app->clock->now() - self::ONLINE_SECONDS]);
        return array_map([self::class, 'decode'], $rows);
    }

    /** @param array<string,mixed> $w */
    private function isOnline(array $w): bool
    {
        return $w['active'] === 1 && $w['last_seen'] >= $this->app->clock->now() - self::ONLINE_SECONDS;
    }

    /** @param array<string,mixed> $w */
    private static function offers(array $w, ?string $voice, ?string $model): bool
    {
        $engine = strtolower((string) ($w['engine']['model'] ?? ''));
        if ($model !== null && $model !== '' && $engine !== '' && strtolower($model) !== $engine) return false;
        if ($voice === null || $voice === '' || !$w['voices']) return true;
        foreach ($w['voices'] as $v) {
            if (strcasecmp((string) ($v['id'] ?? ''), $voice) === 0) return true;
        }
        return false;
    }

    // --- tasks the station asks for ------------------------------------------------------------

    /**
     * A clip asked of the workers in a host's voice. `deadline` (unix s, 0 =
     * none): unleased by then, it is cancelled (a moment's commit is near).
     *
     * @param array<string,mixed> $host decoded (or a draft from "Try voice")
     * @param array<string,mixed> $extra kept with the request (a line's voice signature)
     * @param string $delivery how this moment should sound: Qwen's instruct is the host's own
     *                         direction and this (Speech::direction)
     */
    public function request(string $purpose, int $refId, array $host, string $lang, string $text, int $deadline = 0, array $extra = [], string $delivery = ''): int
    {
        $settings = Hosts::settings('worker', (array) $host['settings']);
        $now = $this->app->clock->now();
        return $this->app->store()->insert('voice_tasks', [
            'purpose' => $purpose,
            'ref_id' => $refId,
            'host_id' => (int) $host['id'],
            'lang' => $lang,
            'text' => $text,
            'request' => json_encode([
                'model' => (string) ($host['model'] ?: self::MODEL),
                'voice' => Hosts::voiceFor($host, $lang),
                'instruct' => Speech::direction((string) ($host['instructions'] ?? ''), $delivery),
                'temperature' => (float) $settings['temperature'],
                'seed' => 0,
            ] + $extra, JSON_UNESCAPED_UNICODE),
            'priority' => self::PRIORITY[$purpose] ?? 50,
            'deadline' => $deadline,
            'created' => $now,
            'updated' => $now,
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function tasksFor(string $purpose, int $refId): array
    {
        return array_map([self::class, 'decodeTask'], $this->app->store()->all('SELECT * FROM voice_tasks WHERE purpose = ? AND ref_id = ? ORDER BY id', [$purpose, $refId]));
    }

    /**
     * Its tasks are not wanted any more (a plan change, a host switched, an
     * account deleted): cancelled, their words gone — a worker's upload for
     * one is then turned away.
     */
    public function cancelFor(string $purpose, int $refId): void
    {
        $this->app->store()->query(
            "UPDATE voice_tasks SET state = 'cancelled', text = '', request = json_set(request, '$.instruct', ''), updated = ? WHERE purpose = ? AND ref_id = ? AND state IN ('queued', 'leased')",
            [$this->app->clock->now(), $purpose, $refId],
        );
    }

    /** Words of done tasks too (an erased account's): nothing of them stays with the tasks. */
    public function forget(string $purpose, int $refId): void
    {
        $this->cancelFor($purpose, $refId);
        $this->app->store()->query("UPDATE voice_tasks SET text = '', request = json_set(request, '$.instruct', '') WHERE purpose = ? AND ref_id = ?", [$purpose, $refId]);
    }

    /**
     * "Try voice" in /mod: its state, and its clip once spoken.
     *
     * @return array<string,mixed>
     */
    public function tryResult(int $taskId): array
    {
        $t = $this->task($taskId);
        if ($t === null || $t['purpose'] !== 'try') throw new ApiError(404, 'not_found');
        $out = ['state' => $t['state']];
        if ($t['state'] === 'done') {
            $bytes = is_file($t['audio']) ? (string) file_get_contents($t['audio']) : '';
            if ($bytes === '') return ['state' => 'failed', 'error' => 'gone'];
            $out += ['audio' => base64_encode($bytes), 'ms' => $t['ms']];
        }
        if ($t['state'] === 'failed') $out['error'] = $t['error'];
        return $out;
    }

    // --- what workers call (Api\WorkerApi) -------------------------------------------------------

    /**
     * The worker a request comes from, by its key. Only failed attempts are
     * counted per address: a worker polls every few seconds, and a valid poll
     * must not write a rate-limit row each time.
     *
     * @param array<string,string> $headers
     * @return array<string,mixed>
     */
    public function authenticate(array $headers, string $ip): array
    {
        $key = trim((string) ($headers['x-arche-worker-key'] ?? ''));
        $row = strlen($key) >= 32 ? $this->app->store()->one('SELECT * FROM workers WHERE key_mac = ?', [$this->mac($key)]) : null;
        if ($row === null) {
            if (!$this->app->rateLimit()->hit('worker-key:' . $this->app->rateLimit()->ipKey($ip), 30, 3600)) throw new ApiError(429, 'rate_limited');
            throw new ApiError(401, 'bad_worker_key');
        }
        $w = self::decode($row);
        if ($w['active'] !== 1) throw new ApiError(403, 'worker_inactive');
        return $w;
    }

    /**
     * A worker checks in: what it offers is noted (the host editor lists its
     * voices) and, when it is ready, the next task it can speak is leased to
     * it — the most urgent first.
     *
     * @param array<string,mixed> $w
     * @param array<mixed> $report name, version, engine, voices, languages, ready
     * @return array{task:?array<string,mixed>,retry_after:int}
     */
    public function poll(array $w, array $report): array
    {
        $now = $this->app->clock->now();
        $voices = [];
        foreach (array_slice((array) ($report['voices'] ?? []), 0, 100) as $v) {
            $id = is_array($v) ? (string) ($v['id'] ?? '') : (string) $v;
            if (preg_match('/^[A-Za-z0-9_-]{1,40}$/', $id)) $voices[] = ['id' => $id, 'label' => mb_substr(is_array($v) ? (string) ($v['label'] ?? $id) : $id, 0, 60)];
        }
        $languages = array_values(array_filter(array_map('strval', array_slice((array) ($report['languages'] ?? []), 0, 40)), fn($l) => preg_match('/^[a-z]{2}$/', $l) === 1));
        $engine = array_map(fn($v) => mb_substr((string) $v, 0, 120), array_intersect_key((array) ($report['engine'] ?? []), array_flip(['model', 'checkpoint', 'revision'])));
        $ready = ($report['ready'] ?? true) !== false;
        // "Not ready" (a check, a model still loading) leases nothing and does not count as online.
        $this->app->store()->update('workers', [
            'voices' => json_encode($voices),
            'languages' => json_encode($languages),
            'engine' => json_encode((object) $engine),
            'version' => mb_substr((string) ($report['version'] ?? ''), 0, 60),
            'last_seen' => $ready ? $now : $w['last_seen'],
        ], 'id = ?', [$w['id']]);
        // A worker host may speak again, or no longer: what Hosts knew of it is stale.
        $this->app->hosts()->refresh();
        if (!$ready) return ['task' => null, 'retry_after' => 15];
        $w = ['voices' => $voices, 'engine' => $engine] + $w;
        $task = $this->lease($w);
        if ($task === null) return ['task' => null, 'retry_after' => $this->workerHostsInUse() ? 4 : 15];
        return ['task' => $task, 'retry_after' => 2];
    }

    /** @param array<string,mixed> $w @return array<string,mixed>|null the task as the worker speaks it */
    private function lease(array $w): ?array
    {
        $now = $this->app->clock->now();
        $store = $this->app->store();
        return $store->tx(function () use ($w, $now, $store): ?array {
            $rows = $store->all(
                "SELECT * FROM voice_tasks WHERE state = 'queued' AND (deadline = 0 OR deadline > ?) ORDER BY priority, CASE WHEN deadline = 0 THEN 1 ELSE 0 END, deadline, id LIMIT 50",
                [$now],
            );
            foreach ($rows as $r) {
                $t = self::decodeTask($r);
                if (!self::offers($w, (string) ($t['request']['voice'] ?? ''), (string) ($t['request']['model'] ?? ''))) continue;
                $expected = self::expectedMs($t['text']);
                $until = $now + min(self::LEASE_MAX, self::LEASE_FACTOR * intdiv($expected, 1000) + self::LEASE_BASE);
                $store->update('voice_tasks', ['state' => 'leased', 'worker_id' => $w['id'], 'lease_until' => $until, 'updated' => $now], "id = ? AND state = 'queued'", [$t['id']]);
                return [
                    'id' => $t['id'],
                    'purpose' => $t['purpose'],
                    'text' => $t['text'],
                    'lang' => $t['lang'],
                    'voice' => (string) ($t['request']['voice'] ?? ''),
                    'model' => (string) ($t['request']['model'] ?? self::MODEL),
                    'instruct' => (string) ($t['request']['instruct'] ?? ''),
                    'temperature' => (float) ($t['request']['temperature'] ?? 0.7),
                    'seed' => (int) ($t['request']['seed'] ?? 0),
                    'expected_ms' => $expected,
                    'lease_until' => $until,
                ];
            }
            return null;
        });
    }

    /**
     * A worker's clip: checked (an MP3; a length that fits its words), kept
     * where its owner keeps clips, and handed to it — the moment, the line or
     * the try. A clip that does not fit its words is spoken again with
     * another seed once, then the task is given up.
     *
     * @param array<string,mixed> $w
     * @return array<string,mixed>
     */
    public function complete(array $w, int $taskId, ?string $file): array
    {
        $t = $this->task($taskId) ?? throw new ApiError(409, 'task_gone');
        if ($t['state'] === 'done' && $t['worker_id'] === $w['id']) return ['ok' => true]; // sent twice
        if ($t['state'] !== 'leased' || $t['worker_id'] !== $w['id']) throw new ApiError(409, 'task_gone');
        if ($file === null) throw new ApiError(422, 'invalid_audio');
        $check = Mp3::inspect($file);
        if (!$check['ok']) throw new ApiError(422, 'invalid_audio');
        if (!self::plausible($t['text'], $check['ms'])) {
            $this->again($t, 'length ' . round($check['ms'] / 1000, 1) . ' s for ' . mb_strlen($t['text']) . ' characters');
            return ['ok' => false, 'retake' => true];
        }
        $bytes = (string) file_get_contents($file);
        $handed = match ($t['purpose']) {
            'break' => $this->toBreak($t, $bytes, $check['ms']),
            'line' => $this->toLine($t, $bytes, $check['ms']),
            'try' => $this->toTry($t, $bytes),
            default => null,
        };
        if ($handed === null) {
            // Its owner no longer wants it (cancelled meanwhile): turned away, nothing kept.
            $this->app->store()->query("UPDATE voice_tasks SET state = 'cancelled', text = '', request = json_set(request, '$.instruct', ''), updated = ? WHERE id = ?", [$this->app->clock->now(), $t['id']]);
            throw new ApiError(409, 'task_gone');
        }
        $this->app->store()->update('voice_tasks', ['state' => 'done', 'audio' => $handed, 'ms' => $check['ms'], 'error' => '', 'updated' => $this->app->clock->now()], 'id = ?', [$t['id']]);
        // Its characters: a line's against the host's monthly recording, a moment's against its day.
        $this->app->usage()->record($t['purpose'] === 'line' ? Lines::usageKind($t['host_id']) : Hosts::usageKind($t['host_id']), mb_strlen($t['text']), 0, 0);
        return ['ok' => true];
    }

    /**
     * A worker could not speak it: spoken again (`retry`, at most MAX_TAKES
     * in all), else given up — the owner then moves on (the next host).
     *
     * @param array<string,mixed> $w
     * @return array{ok:bool}
     */
    public function fail(array $w, int $taskId, string $error, bool $retry): array
    {
        $t = $this->task($taskId);
        if ($t === null || $t['state'] !== 'leased' || $t['worker_id'] !== $w['id']) return ['ok' => true];
        $error = mb_substr(trim($error), 0, 200) ?: 'failed';
        if ($retry) {
            $this->again($t, $error);
        } else {
            $this->app->store()->update('voice_tasks', ['state' => 'failed', 'error' => $error, 'updated' => $this->app->clock->now()], 'id = ?', [$t['id']]);
        }
        return ['ok' => true];
    }

    /** Once more with another seed, or given up after MAX_TAKES. @param array<string,mixed> $t */
    private function again(array $t, string $why): void
    {
        $takes = $t['attempts'] + 1;
        $request = $t['request'];
        $request['seed'] = (int) ($request['seed'] ?? 0) + 1;
        $this->app->store()->update('voice_tasks', [
            'state' => $takes >= self::MAX_TAKES ? 'failed' : 'queued',
            'worker_id' => null,
            'lease_until' => 0,
            'attempts' => $takes,
            'request' => json_encode($request, JSON_UNESCAPED_UNICODE),
            'error' => mb_substr($why, 0, 200),
            'updated' => $this->app->clock->now(),
        ], 'id = ?', [$t['id']]);
    }

    /** @param array<string,mixed> $t */
    private function toBreak(array $t, string $bytes, int $ms): ?string
    {
        $url = $this->app->media()->put('host/' . gmdate('Ymd', $this->app->clock->now()), sprintf('%d-%s.%s.mp3', $t['ref_id'], Ids::short(6), $t['lang']), $bytes);
        if ($this->app->hostBreaks()->voiced($t['ref_id'], $t['lang'], $url, $ms)) return $url;
        $this->app->media()->delete($url);
        return null;
    }

    /** @param array<string,mixed> $t */
    private function toLine(array $t, string $bytes, int $ms): ?string
    {
        $url = $this->app->media()->put('lines', sprintf('%d-%s.%s.mp3', $t['ref_id'], substr(hash('sha256', $bytes), 0, 12), $t['lang']), $bytes);
        if ($this->app->lines()->recorded($t['ref_id'], $t['lang'], $url, $ms, (string) ($t['request']['signature'] ?? ''))) return $url;
        $this->app->media()->delete($url);
        return null;
    }

    /** A try's clip: in the data folder, never public, for the editor to fetch. @param array<string,mixed> $t */
    private function toTry(array $t, string $bytes): string
    {
        $dir = $this->app->config->dataDir . '/tries';
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        $path = $dir . '/' . $t['id'] . '.mp3';
        file_put_contents($path, $bytes);
        return $path;
    }

    // --- housekeeping (every tick, jobs phase) --------------------------------------------------

    /**
     * Leases that ran out go back to the queue (a Mac asleep mid-task), a
     * moment's task whose commit is near is cancelled (its host break moves
     * on to the next host), tries' clips go after TRY_KEEP, and what tasks
     * held of listeners' words goes after a day.
     *
     * @return array{requeued:int,expired:int}
     */
    public function maintain(): array
    {
        $now = $this->app->clock->now();
        $store = $this->app->store();
        $requeued = 0;
        foreach ($store->all("SELECT * FROM voice_tasks WHERE state = 'leased' AND lease_until < ?", [$now]) as $r) {
            $this->again(self::decodeTask($r), 'lease ran out');
            $requeued++;
        }
        $expired = $store->query(
            "UPDATE voice_tasks SET state = 'cancelled', error = 'too late', text = '', request = json_set(request, '$.instruct', ''), updated = ? WHERE state = 'queued' AND deadline > 0 AND deadline <= ?",
            [$now, $now],
        )->rowCount();
        foreach ($store->all("SELECT id, audio FROM voice_tasks WHERE purpose = 'try' AND audio != '' AND updated < ?", [$now - self::TRY_KEEP]) as $r) {
            @unlink((string) $r['audio']);
            $store->update('voice_tasks', ['audio' => ''], 'id = ?', [(int) $r['id']]);
        }
        // The moment's delivery travels in the instruct: it goes with the words.
        $store->query("UPDATE voice_tasks SET text = '', request = json_set(request, '$.instruct', '') WHERE text != '' AND state NOT IN ('queued', 'leased') AND updated < ?", [$now - 86400]);
        return ['requeued' => $requeued, 'expired' => $expired];
    }

    /** Old tasks' rows (Tick::purge). */
    public function purge(int $beforeTs): int
    {
        return $this->app->store()->query("DELETE FROM voice_tasks WHERE state NOT IN ('queued', 'leased') AND updated < ?", [$beforeTs])->rowCount();
    }

    /** The deadline of a moment's task: unleased by then, the commit comes too close for it. */
    public static function deadlineFor(int $startMs): int
    {
        return intdiv($startMs - Timing::COMMIT, 1000) - 30;
    }

    // --- rows ---------------------------------------------------------------------------------

    /** Whether some host may speak with a worker: then idle workers poll more often. */
    private function workerHostsInUse(): bool
    {
        return $this->app->store()->value("SELECT 1 FROM hosts WHERE provider = 'worker' AND active = 1 LIMIT 1") !== null;
    }

    /** Whether a clip's length fits its words (a repeated or skipped stretch does not). */
    public static function plausible(string $text, int $ms): bool
    {
        $chars = mb_strlen($text);
        $seconds = $ms / 1000;
        if ($seconds <= 0) return false;
        if ($seconds > $chars / self::MIN_CPS + 3) return false;
        return $chars < self::SHORT_TEXT || $seconds >= $chars / self::MAX_CPS;
    }

    private static function expectedMs(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 13 * 1000);
    }

    private function mac(string $key): string
    {
        return hash_hmac('sha256', 'worker:' . $key, $this->app->identities()->pepper());
    }

    private static function nameOf(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if ($name === '' || mb_strlen($name) > 60) throw new ApiError(422, 'worker_name');
        return $name;
    }

    /** @return array<string,mixed>|null */
    private function get(int $id): ?array
    {
        $row = $this->app->store()->one('SELECT * FROM workers WHERE id = ?', [$id]);
        return $row !== null ? self::decode($row) : null;
    }

    /** @return array<string,mixed>|null */
    private function task(int $id): ?array
    {
        $row = $this->app->store()->one('SELECT * FROM voice_tasks WHERE id = ?', [$id]);
        return $row !== null ? self::decodeTask($row) : null;
    }

    /** A worker's leased tasks go back to the queue (it was switched off or removed). */
    private function release(int $workerId): void
    {
        $this->app->store()->query(
            "UPDATE voice_tasks SET state = 'queued', worker_id = NULL, lease_until = 0, updated = ? WHERE worker_id = ? AND state = 'leased'",
            [$this->app->clock->now(), $workerId],
        );
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function decode(array $row): array
    {
        foreach (['voices', 'languages'] as $k) {
            $v = json_decode((string) $row[$k], true);
            $row[$k] = is_array($v) ? array_values($v) : [];
        }
        $e = json_decode((string) $row['engine'], true);
        $row['engine'] = is_array($e) ? $e : [];
        foreach (['id', 'active', 'last_seen', 'created', 'updated'] as $k) $row[$k] = (int) $row[$k];
        return $row;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function decodeTask(array $row): array
    {
        $r = json_decode((string) $row['request'], true);
        $row['request'] = is_array($r) ? $r : [];
        foreach (['id', 'ref_id', 'host_id', 'lease_until', 'attempts', 'priority', 'deadline', 'ms', 'created', 'updated'] as $k) $row[$k] = (int) $row[$k];
        $row['worker_id'] = $row['worker_id'] !== null ? (int) $row['worker_id'] : null;
        return $row;
    }
}

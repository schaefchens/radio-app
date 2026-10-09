<?php
declare(strict_types=1);

namespace Arche\Workers;

use Arche\ApiError;
use Arche\App;
use Arche\Support\Ids;

/**
 * The computers that do AI work for the station (herde: its
 * protocol/PROTOCOL.md): our own Macs, and machines lent by people we trust.
 * None has a public address, so the station never calls one: each polls
 * for tasks over HTTPS with a key of its own, shown once and kept as an
 * HMAC with the identity pepper. It travels in X-Worker-Key
 * (X-Arche-Worker-Key before protocol 2): an Authorization header never
 * reaches PHP on this host.
 *
 * Trust comes from how a computer came: an admin adds ours, or invites a
 * lender's with a one-time code. A lender is offered public work only —
 * recorded lines, and moments that name nobody once an admin lets it speak
 * on air (`live`) — never listeners' words: they stay on our own hardware.
 *
 * What a computer can do arrives with its polls (`caps`); whether it is
 * ready, paused by its owner or still loading, too. A poll that changes
 * nothing writes nothing — listeners' requests share the few PHP processes
 * with the polls of every computer — except `last_seen`, every 30 s.
 */
final class Computers
{
    /** What a worker host is saved with: its recorded lines' voice signature names it, so it stays. */
    public const MODEL = 'qwen3-tts-1.7b-customvoice';
    /** The speech model as workers report it, whichever checkpoint speaks a voice (a preset, or an own voice cloned by Base). */
    public const TTS = 'qwen3-tts-1.7b';
    /** Online this long after its last poll (it polls every few seconds, while it speaks too). */
    public const ONLINE_SECONDS = 90;
    /** Tasks one computer may hold at once: a lender's are few, so a broken one cannot sit on many. */
    public const LEASES = ['own' => 4, 'lender' => 1];
    /** `last_seen` is written at most this often. */
    private const SEEN_EVERY = 30;
    /** An invite nobody used: three days. */
    private const INVITE_SECONDS = 72 * 3600;
    /** Failures in a row (a model's error, a clip that does not fit its words) before a computer rests, and for how long. */
    private const FAILS = 3;
    private const REST = 600;
    private const VOICE = '/^[A-Za-z0-9_-]{1,40}$/';
    private const MODEL_ID = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,79}$/';
    private const LANG = '/^[a-z]{2}$/';

    public function __construct(private App $app) {}

    // --- computers (admins) -----------------------------------------------------------------------

    /**
     * One of our own computers, added by an admin.
     *
     * @return array{worker:array<string,mixed>,key:string} the key, shown this once
     */
    public function create(string $name, string $actor): array
    {
        $name = self::nameOf($name);
        [$id, $key] = $this->insert($name, 'own');
        $this->app->store()->audit($actor, 'Worker added', $id . ' ' . $name);
        return ['worker' => $this->view($this->get($id) ?? throw new \LogicException('computer vanished')), 'key' => $key];
    }

    /**
     * Renamed, switched off or on, given a new key (the old one stops at
     * once), or — a lender's — allowed to speak on air or not.
     *
     * @param array<mixed> $data name, active, rotate, live
     * @return array{worker:array<string,mixed>,key?:string}
     */
    public function update(int $id, array $data, string $actor): array
    {
        $c = $this->get($id) ?? throw new ApiError(404, 'not_found');
        $set = [];
        if (array_key_exists('name', $data)) $set['name'] = self::nameOf((string) $data['name']);
        if (array_key_exists('active', $data)) $set['active'] = $data['active'] ? 1 : 0;
        if (array_key_exists('live', $data)) $set['live'] = $data['live'] ? 1 : 0;
        $key = null;
        if (!empty($data['rotate'])) {
            $key = Ids::hex(32);
            $set['key_mac'] = $this->mac($key);
            $set['key_hint'] = substr($key, -4);
        }
        if ($set) {
            $this->app->store()->update('computers', $set + ['updated' => $this->app->clock->now()], 'id = ?', [$id]);
            // Switched off: what it holds goes to another computer.
            if (($set['active'] ?? 1) === 0) $this->app->workerTasks()->release($id);
            $what = array_keys(array_diff_key($set, ['key_mac' => 1, 'key_hint' => 1]));
            if ($key !== null) $what[] = 'new key';
            $this->app->store()->audit($actor, 'Worker changed', $id . ' ' . $c['name'] . ': ' . implode(', ', $what));
            $this->app->hosts()->refresh();
        }
        $out = ['worker' => $this->view($this->get($id) ?? throw new \LogicException('computer vanished'))];
        if ($key !== null) $out['key'] = $key;
        return $out;
    }

    public function delete(int $id, string $actor): void
    {
        $c = $this->get($id) ?? throw new ApiError(404, 'not_found');
        $this->app->workerTasks()->release($id);
        $this->app->store()->query('DELETE FROM computers WHERE id = ?', [$id]);
        $this->app->store()->audit($actor, 'Worker removed', $id . ' ' . $c['name']);
        $this->app->hosts()->refresh();
    }

    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        return array_map(fn($r) => $this->view(self::decode($r)), $this->app->store()->all('SELECT * FROM computers ORDER BY name, id'));
    }

    /** For /mod Status. @return array{online:int,total:int,queued:int} */
    public function summary(): array
    {
        $all = array_map([self::class, 'decode'], $this->app->store()->all('SELECT * FROM computers'));
        return [
            'online' => count(array_filter($all, fn($c) => $this->isOnline($c))),
            'total' => count($all),
            'queued' => (int) $this->app->store()->value("SELECT COUNT(*) FROM worker_tasks WHERE state IN ('queued', 'leased')"),
        ];
    }

    /** As /mod sees a computer: never its key, only the key's end. @param array<string,mixed> $c @return array<string,mixed> */
    private function view(array $c): array
    {
        $since = strtotime(gmdate('Y-m-d', $this->app->clock->now()) . ' 00:00:00 UTC');
        $count = fn(string $state) => (int) $this->app->store()->value('SELECT COUNT(*) FROM worker_tasks WHERE worker_id = ? AND state = ? AND updated >= ?', [$c['id'], $state, $since]);
        $tts = self::engines($c, 'tts');
        $voices = [];
        $langs = [];
        foreach ($tts as $e) {
            foreach ($e['voices'] as $v) {
                $voices[] = ['id' => $v['id'], 'label' => $v['label']];
                array_push($langs, ...$v['langs']);
            }
        }
        $now = $this->app->clock->now();
        return [
            'id' => $c['id'],
            'name' => (string) $c['name'],
            'trust' => (string) $c['trust'],
            'live' => $c['live'] === 1,
            'active' => $c['active'] === 1,
            'online' => $this->isOnline($c),
            // What it said with its last poll: paused by its owner (or outside its hours), its models loading.
            'state' => $c['last_seen'] >= $now - self::ONLINE_SECONDS ? (string) $c['state'] : '',
            'resume_at' => $c['state'] === 'paused' && $c['resume_at'] > $now ? $c['resume_at'] : 0,
            'resting_until' => $c['resting_until'] > $now ? $c['resting_until'] : 0,
            'last_seen' => $c['last_seen'],
            'version' => (string) $c['version'],
            'protocol' => $c['protocol'],
            'platform' => (object) ($c['caps']['platform'] ?? []),
            'engines' => array_map(fn($e) => array_intersect_key($e, array_flip(['kind', 'model', 'location'])) + ['voices' => count($e['voices'] ?? [])], self::engines($c)),
            // What a /mod tab from before protocol 2 reads.
            'engine' => (object) ($tts ? ['model' => (string) $tts[0]['model']] : []),
            'voices' => $voices,
            'languages' => array_values(array_unique($langs)),
            'key_hint' => (string) $c['key_hint'],
            // `queued`: what this computer holds right now (a queued task belongs to nobody yet).
            'tasks' => ['done_today' => $count('done'), 'failed_today' => $count('failed'),
                'queued' => (int) $this->app->store()->value("SELECT COUNT(*) FROM worker_tasks WHERE worker_id = ? AND state = 'leased'", [$c['id']])],
        ];
    }

    // --- invites (admins) and joining (the computer) ----------------------------------------------

    /**
     * A one-time code for a computer to join with: ours, or a lender's.
     * Kept only as an HMAC; shown in this answer only.
     *
     * @return array{invite:array<string,mixed>,code:string}
     */
    public function invite(string $name, string $trust, string $actor): array
    {
        $name = self::nameOf($name);
        if (!in_array($trust, ['own', 'lender'], true)) throw new ApiError(422, 'worker_trust');
        // 26 characters of an unambiguous base-32 alphabet: 130 random bits.
        $code = Ids::short(26);
        $now = $this->app->clock->now();
        $id = $this->app->store()->insert('worker_invites', [
            'code_mac' => $this->mac('invite:' . $code),
            'name' => $name,
            'trust' => $trust,
            'created_by' => $actor,
            'expires' => $now + self::INVITE_SECONDS,
            'created' => $now,
        ]);
        $this->app->store()->audit($actor, 'Worker invited', $id . ' ' . $name . ' (' . $trust . ')');
        return ['invite' => $this->inviteView($this->app->store()->one('SELECT * FROM worker_invites WHERE id = ?', [$id]) ?? []), 'code' => self::grouped($code)];
    }

    /** Invites nobody has used yet and that still hold. @return list<array<string,mixed>> */
    public function invites(): array
    {
        return array_map(
            fn($r) => $this->inviteView($r),
            $this->app->store()->all('SELECT * FROM worker_invites WHERE used = 0 AND expires > ? ORDER BY id', [$this->app->clock->now()]),
        );
    }

    public function revokeInvite(int $id, string $actor): void
    {
        $n = $this->app->store()->query('DELETE FROM worker_invites WHERE id = ? AND used = 0', [$id])->rowCount();
        if ($n !== 1) throw new ApiError(404, 'not_found');
        $this->app->store()->audit($actor, 'Worker invite withdrawn', (string) $id);
    }

    /**
     * A computer joins with its invite's code (PROTOCOL.md, `join`): the
     * code is used once, its trust becomes the computer's, and the key is
     * in this answer only. A wrong, used or expired code all get the same
     * answer, and wrong codes are counted per address.
     *
     * @param array<mixed> $in code, name, version, platform
     * @return array<string,mixed>
     */
    public function join(array $in, string $ip): array
    {
        $code = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', (string) ($in['code'] ?? '')));
        $now = $this->app->clock->now();
        $limiter = $this->app->rateLimit();
        $invite = strlen($code) >= 16
            ? $this->app->store()->one('SELECT * FROM worker_invites WHERE code_mac = ? AND used = 0 AND expires > ?', [$this->mac('invite:' . $code), $now])
            : null;
        if ($invite === null) {
            if (!$limiter->hit('worker-invite:' . $limiter->ipKey($ip), 10, 3600)) throw new ApiError(429, 'rate_limited', ['retry_after' => 600]);
            throw new ApiError(401, 'bad_code');
        }
        $offered = trim((string) preg_replace('/\s+/u', ' ', (string) ($in['name'] ?? '')));
        $name = (string) $invite['name'] !== '' ? (string) $invite['name'] : (mb_substr($offered, 0, 60) ?: 'Computer');
        $trust = (string) $invite['trust'];
        $store = $this->app->store();
        $joined = $store->tx(function () use ($store, $invite, $name, $trust, $now): ?array {
            // Used once: two computers racing with one code, only the first joins.
            if ($store->update('worker_invites', ['used' => $now], 'id = ? AND used = 0', [(int) $invite['id']]) !== 1) return null;
            [$id, $key] = $this->insert($name, $trust);
            $store->update('worker_invites', ['computer_id' => $id], 'id = ?', [(int) $invite['id']]);
            return [$id, $key];
        });
        if ($joined === null) throw new ApiError(401, 'bad_code');
        [$id, $key] = $joined;
        $this->app->store()->audit('worker', 'Worker joined', $id . ' ' . $name . ' (' . $trust . ', invited by ' . $invite['created_by'] . ')');
        return [
            'key' => $key,
            'worker' => ['id' => $id, 'name' => $name, 'trust' => $trust],
            'project' => ['name' => 'ARCHE', 'protocol' => 2],
        ];
    }

    // --- what a computer's polls say ----------------------------------------------------------------

    /**
     * The computer a request comes from, by its key. Only failed attempts are
     * counted per address: a computer polls every few seconds, and a valid
     * poll must not write a rate-limit row each time.
     *
     * @param array<string,string> $headers
     * @return array<string,mixed>
     */
    public function authenticate(array $headers, string $ip): array
    {
        $key = trim((string) ($headers['x-worker-key'] ?? $headers['x-arche-worker-key'] ?? ''));
        $row = strlen($key) >= 32 ? $this->app->store()->one('SELECT * FROM computers WHERE key_mac = ?', [$this->mac($key)]) : null;
        if ($row === null) {
            if (!$this->app->rateLimit()->hit('worker-key:' . $this->app->rateLimit()->ipKey($ip), 30, 3600)) throw new ApiError(429, 'rate_limited');
            throw new ApiError(401, 'bad_worker_key');
        }
        $c = self::decode($row);
        if ($c['active'] !== 1) throw new ApiError(403, 'worker_inactive');
        return $c;
    }

    /**
     * What a poll says about the computer, written only where it changed —
     * and `last_seen` at most every SEEN_EVERY seconds.
     *
     * @param array<string,mixed> $c decoded
     * @param array<string,mixed> $said protocol, version, state, resume_at, and caps with caps_hash when they came
     * @return array<string,mixed> the computer as it is now
     */
    public function polled(array $c, array $said): array
    {
        $now = $this->app->clock->now();
        $set = [];
        foreach (['protocol', 'version', 'state', 'resume_at'] as $k) {
            if (array_key_exists($k, $said) && $said[$k] !== $c[$k]) $set[$k] = $said[$k];
        }
        if (isset($said['caps']) && is_array($said['caps'])) {
            $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
            $hash = (string) ($said['caps_hash'] ?? '');
            if ($hash !== $c['caps_hash'] || json_encode($said['caps'], $flags) !== json_encode($c['caps'], $flags)) {
                $set['caps'] = json_encode($said['caps'], $flags);
                $set['caps_hash'] = $hash;
            }
        }
        $wasOnline = $this->isOnline($c);
        if ($set || $now - $c['last_seen'] >= self::SEEN_EVERY) $set['last_seen'] = $now;
        if (!$set) return $c;
        $this->app->store()->update('computers', $set, 'id = ?', [$c['id']]);
        $c = array_replace($c, $set);
        if (isset($set['caps'])) $c['caps'] = $said['caps'];
        // A worker host may speak again, or no longer: what Hosts knew of it is stale.
        if ($wasOnline !== $this->isOnline($c) || isset($set['caps'])) $this->app->hosts()->refresh();
        return $c;
    }

    /**
     * A computer's capabilities as the station keeps them (PROTOCOL.md,
     * `caps`): what does not fit the schema is left out, never trusted.
     *
     * @param mixed $raw
     * @return array<string,mixed>
     */
    public static function caps(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $platform = is_array($raw['platform'] ?? null) ? $raw['platform'] : [];
        $out = [
            'name' => mb_substr((string) ($raw['name'] ?? ''), 0, 80),
            'platform' => array_filter([
                'os' => mb_substr((string) ($platform['os'] ?? ''), 0, 40),
                'arch' => mb_substr((string) ($platform['arch'] ?? ''), 0, 40),
                'accelerator' => mb_substr((string) ($platform['accelerator'] ?? ''), 0, 120),
                'memory_gb' => is_numeric($platform['memory_gb'] ?? null) ? round((float) $platform['memory_gb'], 1) : null,
            ], fn($v) => $v !== '' && $v !== null),
            'engines' => [],
        ];
        foreach (array_slice(is_array($raw['engines'] ?? null) ? $raw['engines'] : [], 0, 50) as $e) {
            if (!is_array($e)) continue;
            $kind = (string) ($e['kind'] ?? '');
            $model = (string) ($e['model'] ?? '');
            if (!in_array($kind, ['tts', 'text', 'stt'], true) || !preg_match(self::MODEL_ID, $model)) continue;
            $engine = ['kind' => $kind, 'model' => $model, 'location' => ($e['location'] ?? 'local') === 'cloud' ? 'cloud' : 'local'];
            if ($kind === 'tts') {
                $engine['voices'] = [];
                foreach (array_slice(is_array($e['voices'] ?? null) ? $e['voices'] : [], 0, 100) as $v) {
                    $id = is_array($v) ? (string) ($v['id'] ?? '') : '';
                    if (!preg_match(self::VOICE, $id)) continue;
                    $engine['voices'][] = [
                        'id' => $id,
                        'label' => mb_substr((string) ($v['label'] ?? $id), 0, 60) ?: $id,
                        'langs' => self::langs($v['langs'] ?? []),
                        'instruct' => ($v['instruct'] ?? true) !== false,
                        'own' => ($v['own'] ?? false) === true,
                    ];
                }
                $engine['max_chars'] = max(1, (int) ($e['max_chars'] ?? 2000));
            } elseif ($kind === 'text') {
                $engine += ['json_schema' => ($e['json_schema'] ?? false) === true, 'context' => max(1, (int) ($e['context'] ?? 4096)),
                    'max_tokens' => max(1, (int) ($e['max_tokens'] ?? 4096)), 'loaded' => ($e['loaded'] ?? false) === true];
            }
            $speed = is_array($e['speed'] ?? null) ? array_filter($e['speed'], fn($v, $k) => is_numeric($v) && preg_match('/^[a-z_]{1,20}$/', (string) $k), ARRAY_FILTER_USE_BOTH) : [];
            if ($speed) $engine['speed'] = array_map(fn($v) => round((float) $v, 2), array_slice($speed, 0, 5, true));
            $out['engines'][] = $engine;
        }
        return $out;
    }

    /**
     * What a worker of protocol 1 (ARCHE's first voice worker) reports with
     * every poll, as capabilities: one speech engine, its voices in every
     * language it names.
     *
     * @param array<mixed> $report name, version, engine, voices, languages, ready
     * @return array<string,mixed>
     */
    public static function capsOfReport(array $report): array
    {
        $langs = self::langs($report['languages'] ?? []);
        $voices = [];
        foreach (array_slice((array) ($report['voices'] ?? []), 0, 100) as $v) {
            $id = is_array($v) ? (string) ($v['id'] ?? '') : (string) $v;
            $label = is_array($v) ? (string) ($v['label'] ?? $id) : $id;
            // Its own voices are cloned from a recording: they take no direction.
            if (preg_match(self::VOICE, $id)) $voices[] = ['id' => $id, 'label' => $label, 'langs' => $langs, 'instruct' => !str_contains($label, '(own voice')];
        }
        return self::caps(['name' => (string) ($report['name'] ?? ''), 'platform' => ['os' => 'darwin', 'arch' => 'arm64'],
            'engines' => [['kind' => 'tts', 'model' => self::TTS, 'voices' => $voices, 'max_chars' => 2000]]]);
    }

    // --- who can speak --------------------------------------------------------------------------

    /**
     * Whether a computer that can speak this voice (and model, and language)
     * is online and ready — local only, the publish phase asks
     * (Hosts::canSpeak). Private: one of ours. Public: ours, or a lender an
     * admin let speak on air.
     */
    public function online(?string $voice = null, ?string $model = null, bool $private = true, ?string $lang = null): bool
    {
        foreach ($this->onlineComputers() as $c) {
            if ($c['trust'] !== 'own' && ($private || $c['live'] !== 1)) continue;
            if (self::speaks($c, $voice, $model, $lang)) return true;
        }
        return false;
    }

    /**
     * The voices and models online computers offer, for the host editor.
     *
     * @return array{voices:list<array<string,mixed>>,models:list<array<string,mixed>>,online:int}
     */
    public function catalog(): array
    {
        $voices = [];
        $models = [];
        $online = $this->onlineComputers();
        foreach ($online as $c) {
            foreach (self::engines($c, 'tts') as $e) {
                // Qwen's speech model is saved with hosts under the name it had: their lines' signature names it.
                $model = self::family($e['model']) === self::TTS ? self::MODEL : $e['model'];
                $langs = [];
                foreach ($e['voices'] as $v) {
                    $voices[$v['id']] = ['id' => $v['id'], 'name' => $v['label'], 'category' => 'qwen', 'labels' => '', 'languages' => $v['langs']];
                    array_push($langs, ...$v['langs']);
                }
                $models[$model] = ['id' => $model, 'name' => $model, 'languages' => array_values(array_unique($langs)), 'cost' => 0];
            }
        }
        return ['voices' => array_values($voices), 'models' => array_values($models), 'online' => count($online)];
    }

    /**
     * Whether a computer speaks a voice with a model's family, in a language
     * when one is named. A computer that names no voices speaks none.
     *
     * @param array<string,mixed> $c decoded
     */
    public static function speaks(array $c, ?string $voice, ?string $model, ?string $lang = null): bool
    {
        foreach (self::engines($c, 'tts') as $e) {
            if ($model !== null && $model !== '' && self::family($model) !== self::family($e['model'])) continue;
            foreach ($e['voices'] as $v) {
                if ($voice !== null && $voice !== '' && strcasecmp($v['id'], $voice) !== 0) continue;
                if ($lang !== null && $lang !== '' && !in_array($lang, $v['langs'], true)) continue;
                return true;
            }
        }
        return false;
    }

    /**
     * A speech model by its family: the checkpoint that speaks a voice is the
     * worker's business — CustomVoice for a preset, Base for an own voice.
     */
    public static function family(string $model): string
    {
        return (string) preg_replace('/-(customvoice|base|voicedesign)$/', '', strtolower(trim($model)));
    }

    /** @param array<string,mixed> $c decoded @return list<array<string,mixed>> its engines (of a kind) */
    public static function engines(array $c, ?string $kind = null): array
    {
        $out = [];
        foreach ((array) ($c['caps']['engines'] ?? []) as $e) {
            if (is_array($e) && ($kind === null || ($e['kind'] ?? '') === $kind)) $out[] = $e + ['voices' => []];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function onlineComputers(): array
    {
        $now = $this->app->clock->now();
        $rows = $this->app->store()->all(
            "SELECT * FROM computers WHERE active = 1 AND state = 'ready' AND last_seen >= ? AND resting_until <= ?",
            [$now - self::ONLINE_SECONDS, $now],
        );
        return array_map([self::class, 'decode'], $rows);
    }

    /** @param array<string,mixed> $c */
    private function isOnline(array $c): bool
    {
        $now = $this->app->clock->now();
        return $c['active'] === 1 && $c['state'] === 'ready' && $c['last_seen'] >= $now - self::ONLINE_SECONDS && $c['resting_until'] <= $now;
    }

    // --- failures in a row ----------------------------------------------------------------------

    /** A task it could not do, or did wrong: FAILS in a row rest it, so a broken computer cannot sit on live work. */
    public function failed(int $id): void
    {
        $c = $this->get($id);
        if ($c === null) return;
        $fails = $c['fails'] + 1;
        if ($fails < self::FAILS) {
            $this->app->store()->update('computers', ['fails' => $fails], 'id = ?', [$id]);
            return;
        }
        $until = $this->app->clock->now() + self::REST;
        $this->app->store()->update('computers', ['fails' => 0, 'resting_until' => $until], 'id = ?', [$id]);
        $this->app->store()->audit('worker', 'Worker resting', $id . ' ' . $c['name'] . ' until ' . gmdate('H:i', $until) . ' UTC: ' . self::FAILS . ' failures in a row');
        $this->app->workerTasks()->release($id);
        $this->app->hosts()->refresh();
    }

    /** A task done well: the count starts over (one write only when there was one). */
    public function succeeded(int $id): void
    {
        $this->app->store()->query('UPDATE computers SET fails = 0 WHERE id = ? AND fails > 0', [$id]);
    }

    // --- rows ---------------------------------------------------------------------------------

    /** @return array<string,mixed>|null */
    public function get(int $id): ?array
    {
        $row = $this->app->store()->one('SELECT * FROM computers WHERE id = ?', [$id]);
        return $row !== null ? self::decode($row) : null;
    }

    /** @return array{0:int,1:string} the new computer's id and its key */
    private function insert(string $name, string $trust): array
    {
        $key = Ids::hex(32);
        $now = $this->app->clock->now();
        $id = $this->app->store()->insert('computers', [
            'name' => $name,
            'key_mac' => $this->mac($key),
            'key_hint' => substr($key, -4),
            'trust' => $trust,
            'protocol' => 2,
            'created' => $now,
            'updated' => $now,
        ]);
        return [$id, $key];
    }

    private function mac(string $secret): string
    {
        return hash_hmac('sha256', 'worker:' . $secret, $this->app->identities()->pepper());
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private function inviteView(array $r): array
    {
        return ['id' => (int) ($r['id'] ?? 0), 'name' => (string) ($r['name'] ?? ''), 'trust' => (string) ($r['trust'] ?? ''),
            'expires' => (int) ($r['expires'] ?? 0), 'created_by' => (string) ($r['created_by'] ?? '')];
    }

    /** "ab12cd…" as "AB12-CD34-…": easier to read out and to type. */
    private static function grouped(string $code): string
    {
        return strtoupper(implode('-', str_split($code, 4)));
    }

    /** @return list<string> */
    private static function langs(mixed $raw): array
    {
        $out = [];
        foreach (array_slice(is_array($raw) ? $raw : [], 0, 40) as $l) {
            if (is_string($l) && preg_match(self::LANG, $l)) $out[] = $l;
        }
        return array_values(array_unique($out));
    }

    private static function nameOf(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if ($name === '' || mb_strlen($name) > 60) throw new ApiError(422, 'worker_name');
        return $name;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    public static function decode(array $row): array
    {
        $caps = json_decode((string) $row['caps'], true);
        $row['caps'] = is_array($caps) ? $caps : [];
        foreach (['id', 'live', 'active', 'protocol', 'resume_at', 'fails', 'resting_until', 'last_seen', 'created', 'updated'] as $k) $row[$k] = (int) $row[$k];
        foreach (['name', 'trust', 'state', 'caps_hash', 'version'] as $k) $row[$k] = (string) $row[$k];
        return $row;
    }
}

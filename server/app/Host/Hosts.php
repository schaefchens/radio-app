<?php
declare(strict_types=1);

namespace Arche\Host;

use Arche\Ai\VoiceError;
use Arche\ApiError;
use Arche\App;
use Arche\Support\Sealed;
use Arche\Workers\Computers;

/**
 * The station's on-air hosts — each a persona (name, picture, color, a few
 * words listeners read and the writer is given, private style notes) with a
 * voice of its own: OpenAI or ElevenLabs, its model, voice, direction and
 * settings, and its own key (an OpenAI host without one speaks with the
 * station's). Not a Catalog write: a host changed, nothing planned is thrown
 * away; the next script and clip use the change.
 *
 * Who speaks is a lineup's: a program's on-air hosts (`main`) and fallbacks
 * (in order), else its channel's, else the main channel's. One host per show
 * (a run of the program, PlanResolver::runAt; a run longer than a day is one
 * show per local day): picked at random among the on-air hosts that can
 * speak — not the previous show's when another can — and kept in kv. A
 * moment it cannot speak (resting after errors, its characters for the day
 * used up) goes to one of the same name (the same persona on another
 * provider), then the fallbacks, then the other on-air hosts.
 *
 * Keys are write-only: sealed at rest (Support\Sealed), never in a response,
 * the audit or an error message.
 */
final class Hosts
{
    /** `worker`: a computer of ours, or a lent one, with Qwen3-TTS (Workers\Computers) — no key, asynchronous. */
    public const PROVIDERS = ['openai', 'elevenlabs', 'worker'];
    /** OpenAI's built-in voices; the tts-1 models lack ballad, marin and cedar. */
    public const OPENAI_VOICES = ['alloy', 'ash', 'ballad', 'coral', 'echo', 'fable', 'nova', 'onyx', 'sage', 'shimmer', 'verse', 'marin', 'cedar'];
    public const OPENAI_MODELS = ['gpt-4o-mini-tts', 'tts-1', 'tts-1-hd'];
    public const ELEVENLABS_MODELS = ['eleven_multilingual_v2', 'eleven_flash_v2_5', 'eleven_v3'];
    private const DEFAULT_MODEL = ['openai' => 'gpt-4o-mini-tts', 'elevenlabs' => 'eleven_flash_v2_5', 'worker' => Computers::MODEL];
    /** @var array<string,array<string,float|bool>> what each provider can be told, with its defaults */
    private const SETTINGS = [
        'openai' => ['speed' => 1.0],
        // `language`: send the language code — eleven_multilingual_v2 refuses one.
        'elevenlabs' => ['stability' => 0.5, 'similarity' => 0.75, 'style' => 0.0, 'speaker_boost' => true, 'speed' => 1.0, 'language' => true],
        // Lower reads steadier: Qwen's own 0.9 skipped or repeated a word more often.
        'worker' => ['temperature' => 0.7],
    ];
    /** A worker host's voices until told otherwise: what the station's owner chose by ear. */
    private const WORKER_VOICES = ['en' => 'Ryan', 'de' => 'Sohee'];
    private const MAX_LINEUP = 8;
    /** Temporary failures in a row before a host rests, and for how long (s). */
    private const TEMP_FAILS = 3;
    private const TEMP_REST = 600;
    /** Shows remembered per channel and program: the current, the one before, and a few drafted ahead. */
    private const SHOWS_KEPT = 4;
    /** The host of a station that has none left to name (channels.json must name one). */
    private const NOBODY = ['name' => 'Hope', 'avatar' => null, 'color' => '#2f7bff', 'about' => ['en' => '', 'de' => ''], 'voice' => 'openai'];

    /** @var array<int,array<string,mixed>>|null */
    private ?array $cache = null;
    /** @var array<string,list<array{id:int,role:string}>> */
    private array $lineups = [];
    /** @var array<string,bool> whether a host can speak a private or a public moment (no length asked), until something changes */
    private array $speaks = [];
    /** @var array<int,string> opened keys, by host id */
    private array $keys = [];
    private ?Sealed $sealed = null;
    /** @var \Closure(int,int):int */
    private \Closure $rand;

    public function __construct(private App $app)
    {
        $this->rand = static fn(int $min, int $max): int => random_int($min, $max);
    }

    /** Deterministic randomness for tests. @param \Closure(int,int):int $rand */
    public function useRandom(\Closure $rand): void
    {
        $this->rand = $rand;
    }

    // --- reading --------------------------------------------------------------------

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function decode(array $row): array
    {
        foreach (['voices', 'settings'] as $k) {
            $v = json_decode((string) $row[$k], true);
            $row[$k] = is_array($v) ? $v : [];
        }
        foreach (['id', 'max_chars_day', 'active', 'resting_until', 'fail_count'] as $k) $row[$k] = (int) $row[$k];
        $row['settings'] = self::settings((string) $row['provider'], $row['settings']);
        return $row;
    }

    /** @return array<int,array<string,mixed>> every host by id */
    private function byId(): array
    {
        if ($this->cache !== null) return $this->cache;
        $out = [];
        foreach ($this->app->store()->all('SELECT * FROM hosts ORDER BY name, id') as $r) {
            $h = self::decode($r);
            $out[$h['id']] = $h;
        }
        return $this->cache = $out;
    }

    /** @return array<string,mixed>|null */
    public function get(int $id): ?array
    {
        return $this->byId()[$id] ?? null;
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return array_values($this->byId());
    }

    /** Who can speak may have changed outside (a voice worker came online or went quiet). */
    public function refresh(): void
    {
        $this->changed();
    }

    private function changed(): void
    {
        $this->cache = null;
        $this->speaks = [];
        $this->keys = [];
    }

    /**
     * What a provider can be told, clamped to what it accepts, defaults for the rest.
     *
     * @param array<mixed> $in
     * @return array<string,float|bool>
     */
    public static function settings(string $provider, array $in): array
    {
        $out = [];
        foreach (self::SETTINGS[$provider] ?? self::SETTINGS['openai'] as $k => $default) {
            $v = $in[$k] ?? $default;
            $out[$k] = is_bool($default) ? (bool) $v : round((float) $v, 2);
        }
        if ($provider === 'elevenlabs') {
            foreach (['stability', 'similarity', 'style'] as $k) $out[$k] = max(0.0, min(1.0, (float) $out[$k]));
            $out['speed'] = max(0.7, min(1.2, (float) $out['speed']));
        } elseif ($provider === 'worker') {
            $out['temperature'] = max(0.1, min(1.2, (float) $out['temperature']));
        } else {
            $out['speed'] = max(0.25, min(4.0, (float) $out['speed']));
        }
        return $out;
    }

    /**
     * The voice a host speaks a language with: its own for that language, else
     * the first it has (one voice can speak every language), else none.
     *
     * @param array<string,mixed> $h
     */
    public static function voiceFor(array $h, string $lang): string
    {
        $own = trim((string) ($h['voices'][$lang] ?? ''));
        if ($own !== '') return $own;
        foreach ((array) $h['voices'] as $v) {
            if (is_string($v) && trim($v) !== '') return trim($v);
        }
        return '';
    }

    /**
     * For /mod: never the key — whether there is one, and its last characters
     * for admins. With today's characters, the resting state and where it is used.
     *
     * @param array<string,mixed> $h
     * @return array<string,mixed>
     */
    public function view(array $h, bool $admin): array
    {
        $now = $this->app->clock->now();
        $own = (string) $h['api_key'] !== '';
        $view = [
            'id' => $h['id'],
            'name' => (string) $h['name'],
            'avatar' => $h['avatar'] ?: null,
            'color' => (string) $h['color'],
            'about_en' => (string) $h['about_en'],
            'about_de' => (string) $h['about_de'],
            'style' => (string) $h['style'],
            'provider' => (string) $h['provider'],
            'model' => (string) $h['model'],
            'voices' => (object) $h['voices'],
            'instructions' => (string) $h['instructions'],
            'settings' => $h['settings'],
            'max_chars_day' => $h['max_chars_day'],
            'active' => $h['active'] === 1,
            'key_set' => $own,
            // A sealed key that no longer opens (the pepper changed): enter it again.
            'key_unreadable' => $own && $this->key($h) === '',
            'station_key' => !$own && $h['provider'] === 'openai' && $this->app->config->openaiKey() !== '',
            'resting_until' => $h['resting_until'] > $now ? $h['resting_until'] : 0,
            'last_error' => (string) $h['last_error'],
            'speaks' => $this->canSpeak($h),
            'today' => ['chars' => $this->usedToday($h['id']), 'calls' => $this->callsToday($h['id'])],
            'used_in' => $this->usedIn($h['id']),
            'lines_active' => $this->app->lines()->activeCount($h['id']),
        ];
        if ($h['provider'] === 'worker') $view['worker_online'] = $this->app->computers()->online(self::voiceFor($h, $this->app->config->stationLangs()[0]), (string) $h['model']);
        if ($admin) $view['key_hint'] = (string) $h['key_hint'];
        return $view;
    }

    /** @return list<array<string,mixed>> */
    public function views(bool $admin): array
    {
        return array_map(fn($h) => $this->view($h, $admin), $this->all());
    }

    /**
     * The lineups a host is in: channels and programs, with its role.
     *
     * @return list<array{channel:?string,program:?int,title:array{en:string,de:string},role:string}>
     */
    private function usedIn(int $id): array
    {
        $out = [];
        foreach ($this->app->store()->all(
            'SELECT l.role, l.channel_id, l.program_id, c.slug, c.name_en AS c_en, c.name_de AS c_de, p.title_en, p.title_de, pc.slug AS p_slug
             FROM host_lineups l LEFT JOIN channels c ON c.id = l.channel_id LEFT JOIN programs p ON p.id = l.program_id
             LEFT JOIN channels pc ON pc.id = p.channel_id WHERE l.host_id = ? ORDER BY l.program_id IS NOT NULL, c.name_en, p.title_en',
            [$id],
        ) as $r) {
            $program = $r['program_id'] !== null;
            $out[] = [
                'channel' => (string) ($program ? $r['p_slug'] : $r['slug']),
                'program' => $program ? (int) $r['program_id'] : null,
                'title' => ['en' => (string) ($program ? $r['title_en'] : $r['c_en']), 'de' => (string) ($program ? $r['title_de'] : $r['c_de'])],
                'role' => (string) $r['role'],
            ];
        }
        return $out;
    }

    // --- writing ----------------------------------------------------------------------

    /**
     * A host, new or changed — only the keys present. `api_key`: a new key
     * (sealed), or '' for none (an OpenAI host then speaks with the station's).
     * Saving clears a rest and the last error: a moderator fixed what made it
     * fail, or wants to know at once.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed> the host (decoded)
     */
    public function save(?int $id, array $data, string $actor): array
    {
        $before = $id !== null ? ($this->get($id) ?? throw new ApiError(404, 'not_found')) : null;
        $row = $this->row($before, $data);
        $store = $this->app->store();
        $now = $this->app->clock->now();
        if ($id === null) {
            $id = $store->insert('hosts', $row + ['created' => $now, 'updated' => $now]);
        } else {
            $store->update('hosts', $row + ['updated' => $now], 'id = ?', [$id]);
        }
        $this->changed();
        $host = $this->get($id) ?? throw new \LogicException('host vanished');
        // Never the key, not even its end.
        $what = array_keys(array_diff_key($row, array_flip(['api_key', 'key_hint', 'resting_until', 'fail_count', 'last_error'])));
        if (array_key_exists('api_key', $data)) $what[] = $row['api_key'] === '' ? 'key removed' : 'key changed';
        $store->audit($actor, $before === null ? 'Host added' : 'Host changed', $id . ' ' . $host['name'] . ($what ? ': ' . implode(', ', $what) : ''));
        return $host;
    }

    /**
     * The editor's unsaved changes on a host, checked like a save — for "Try
     * voice". A key typed in is not part of it (Voice::speak takes it apart).
     *
     * @param array<string,mixed> $host
     * @param array<string,mixed> $changes
     * @return array<string,mixed>
     */
    public function draft(array $host, array $changes): array
    {
        unset($changes['api_key']);
        $row = $this->row($host, $changes);
        $raw = $this->app->store()->one('SELECT * FROM hosts WHERE id = ?', [$host['id']]) ?? throw new ApiError(404, 'not_found');
        return self::decode(array_merge($raw, $row));
    }

    /**
     * Whether "Try voice" may speak $chars characters now: as a moment would,
     * but switched off or resting is fine (that is how a fix is tried).
     *
     * @param array<string,mixed> $h
     */
    public function mayTry(array $h, int $chars): bool
    {
        if ($h['provider'] === 'elevenlabs' && $h['max_chars_day'] <= 0) return false;
        return $h['max_chars_day'] <= 0 || $this->usedToday($h['id']) + $chars <= $h['max_chars_day'];
    }

    /**
     * The columns a save writes, checked.
     *
     * @param array<string,mixed>|null $before
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function row(?array $before, array $data): array
    {
        $provider = array_key_exists('provider', $data) ? (string) $data['provider'] : (string) ($before['provider'] ?? 'openai');
        if (!in_array($provider, self::PROVIDERS, true)) throw new ApiError(422, 'host_provider');
        $switched = $before !== null && $before['provider'] !== $provider;
        $row = [];
        if ($before === null || $switched) $row['provider'] = $provider;
        if (array_key_exists('name', $data) || $before === null) {
            $name = trim((string) preg_replace('/\s+/u', ' ', (string) ($data['name'] ?? '')));
            if ($name === '' || mb_strlen($name) > 40) throw new ApiError(422, 'host_name');
            $row['name'] = $name;
        }
        if (array_key_exists('color', $data)) {
            $color = trim((string) $data['color']);
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) throw new ApiError(422, 'invalid_color');
            $row['color'] = strtolower($color);
        }
        foreach (['about_en', 'about_de'] as $k) {
            if (!array_key_exists($k, $data)) continue;
            $about = trim((string) preg_replace('/\s+/u', ' ', (string) $data[$k]));
            // Shown under the host's name while they speak: a line or two.
            if (mb_strlen($about) > 200) throw new ApiError(422, 'host_about');
            $row[$k] = $about;
        }
        foreach (['style' => 600, 'instructions' => 500] as $k => $max) {
            if (!array_key_exists($k, $data)) continue;
            $text = trim(str_replace("\r", '', (string) $data[$k]));
            if (mb_strlen($text) > $max) throw new ApiError(422, 'host_' . $k);
            $row[$k] = $text;
        }
        if (array_key_exists('model', $data) || $before === null || $switched) {
            $model = trim((string) ($data['model'] ?? '')) ?: self::DEFAULT_MODEL[$provider];
            if (!preg_match('/^[a-z0-9][a-z0-9._-]{1,63}$/', $model)) throw new ApiError(422, 'host_model');
            $row['model'] = $model;
        }
        if (array_key_exists('voices', $data) || $before === null || $switched) {
            $given = is_array($data['voices'] ?? null) ? $data['voices'] : [];
            $voices = [];
            foreach ($this->app->config->stationLangs() as $l) {
                $v = trim((string) ($given[$l] ?? ''));
                if ($v === '') continue;
                $ok = match ($provider) {
                    'elevenlabs' => preg_match('/^[A-Za-z0-9]{8,40}$/', $v),
                    // Qwen's presets: Ryan, Sohee, Ono_Anna …
                    'worker' => preg_match('/^[A-Za-z0-9_-]{1,40}$/', $v),
                    default => preg_match('/^(?:[a-z]{2,16}|voice_[A-Za-z0-9_-]{4,64})$/', $v),
                };
                if (!$ok) throw new ApiError(422, 'host_voice');
                $voices[$l] = $v;
            }
            // A new OpenAI host speaks with coral until it is told otherwise.
            if (!$voices && $provider === 'openai') $voices = array_fill_keys($this->app->config->stationLangs(), 'coral');
            if (!$voices && $provider === 'worker') $voices = array_intersect_key(self::WORKER_VOICES, array_flip($this->app->config->stationLangs()));
            $row['voices'] = json_encode((object) $voices, JSON_UNESCAPED_UNICODE);
        }
        if (array_key_exists('settings', $data) || $before === null || $switched) {
            $kept = !$switched && $before !== null ? $before['settings'] : [];
            $row['settings'] = json_encode(self::settings($provider, (is_array($data['settings'] ?? null) ? $data['settings'] : []) + $kept));
        }
        if (array_key_exists('max_chars_day', $data)) $row['max_chars_day'] = max(0, min(1_000_000, (int) $data['max_chars_day']));
        if (array_key_exists('active', $data)) $row['active'] = $data['active'] ? 1 : 0;
        if ($provider === 'worker') {
            // A worker speaks with no key: one kept from another provider would only sit there.
            $row['api_key'] = '';
            $row['key_hint'] = '';
        } elseif (array_key_exists('api_key', $data)) {
            $key = trim((string) $data['api_key']);
            if ($key !== '' && !preg_match('/^[\x21-\x7e]{8,200}$/', $key)) throw new ApiError(422, 'host_key');
            $row['api_key'] = $key === '' ? '' : $this->sealed()->seal($key);
            $row['key_hint'] = $key === '' ? '' : '…' . substr($key, -4);
        } elseif ($switched) {
            // The other provider's key would only fail there.
            $row['api_key'] = '';
            $row['key_hint'] = '';
        }
        return $row + ['resting_until' => 0, 'fail_count' => 0, 'last_error' => ''];
    }

    /** @return array<string,mixed> the host with its new picture */
    public function setAvatar(int $id, string $file, string $actor): array
    {
        $h = $this->get($id) ?? throw new ApiError(404, 'not_found');
        // The old picture stays: names are content hashes (two hosts may share
        // one), and published minute and day files still point at it.
        $url = $this->app->media()->storeImage($file, 'stage', 256, 256);
        $this->app->store()->update('hosts', ['avatar' => $url, 'updated' => $this->app->clock->now()], 'id = ?', [$id]);
        $this->changed();
        $this->app->store()->audit($actor, 'Host changed', $id . ' ' . $h['name'] . ': picture');
        return $this->get($id) ?? throw new \LogicException('host vanished');
    }

    /** Only a host in no lineup, and never the last one (channels.json must name a host). */
    public function delete(int $id, string $actor): void
    {
        $h = $this->get($id) ?? throw new ApiError(404, 'not_found');
        $used = $this->usedIn($id);
        if ($used) throw new ApiError(409, 'host_in_use', ['used' => array_map(fn($u) => $u['title']['en'], $used)]);
        if (count($this->byId()) <= 1) throw new ApiError(409, 'last_host');
        // Its recorded lines' files (the rows go with it, ON DELETE CASCADE).
        $this->app->lines()->forgetHost($id);
        $this->app->store()->query('DELETE FROM hosts WHERE id = ?', [$id]);
        $this->changed();
        $this->app->store()->audit($actor, 'Host deleted', $id . ' ' . $h['name']);
    }

    // --- keys -------------------------------------------------------------------------

    private function sealed(): Sealed
    {
        return $this->sealed ??= new Sealed($this->app->identities()->pepper());
    }

    /**
     * The key a host speaks with: its own, else — for OpenAI — the station's.
     * '' = none (or its own no longer opens).
     *
     * @param array<string,mixed> $h
     */
    public function key(array $h): string
    {
        if ((string) $h['api_key'] === '') return $h['provider'] === 'openai' ? $this->app->config->openaiKey() : '';
        return $this->keys[$h['id']] ??= $this->sealed()->open((string) $h['api_key']) ?? '';
    }

    // --- lineups ----------------------------------------------------------------------

    /** @return list<array{id:int,role:string}> on-air hosts first, each group in its order */
    public function lineup(string $owner, int $id): array
    {
        $col = $owner === 'program' ? 'program_id' : 'channel_id';
        return $this->lineups["$owner:$id"] ??= array_map(
            fn($r) => ['id' => (int) $r['host_id'], 'role' => (string) $r['role']],
            $this->app->store()->all("SELECT host_id, role FROM host_lineups WHERE $col = ? ORDER BY role = 'fallback', sort, rowid", [$id]),
        );
    }

    /**
     * A channel's or a program's lineup, replaced: at most MAX_LINEUP hosts,
     * each once, at least one on air when there are any. Empty: the
     * program takes its channel's, the channel the main channel's.
     *
     * @param array<mixed> $list [{id, role}]
     */
    public function setLineup(string $owner, int $id, array $list): void
    {
        $col = $owner === 'program' ? 'program_id' : 'channel_id';
        $rows = [];
        foreach ($list as $e) {
            $hid = (int) (is_array($e) ? ($e['id'] ?? 0) : $e);
            $role = is_array($e) ? (string) ($e['role'] ?? 'main') : 'main';
            if ($this->get($hid) === null || !in_array($role, ['main', 'fallback'], true) || isset($rows[$hid])) throw new ApiError(422, 'host_lineup');
            $rows[$hid] = $role;
        }
        if (count($rows) > self::MAX_LINEUP || ($rows && !in_array('main', $rows, true))) throw new ApiError(422, 'host_lineup');
        $store = $this->app->store();
        $store->tx(function () use ($store, $col, $id, $rows): void {
            $store->query("DELETE FROM host_lineups WHERE $col = ?", [$id]);
            $sort = 0;
            foreach ($rows as $hid => $role) $store->insert('host_lineups', ['host_id' => $hid, $col => $id, 'role' => $role, 'sort' => $sort++]);
        });
        $this->lineups = [];
        $this->speaks = [];
    }

    /**
     * The lineup that applies to a program: its own, else its channel's, else
     * the main channel's — active hosts only.
     *
     * @param array<string,mixed> $channel
     * @param array<string,mixed>|null $program
     * @return array{mains:list<array<string,mixed>>,fallbacks:list<array<string,mixed>>}
     */
    public function effective(array $channel, ?array $program): array
    {
        $entries = $program !== null ? $this->lineup('program', (int) $program['id']) : [];
        if (!$entries) $entries = $this->lineup('channel', (int) $channel['id']);
        if (!$entries) {
            $main = $this->app->store()->value('SELECT id FROM channels ORDER BY is_main DESC, active DESC, sort, id LIMIT 1');
            if ($main !== null && (int) $main !== (int) $channel['id']) $entries = $this->lineup('channel', (int) $main);
        }
        $out = ['mains' => [], 'fallbacks' => []];
        foreach ($entries as $e) {
            $h = $this->get($e['id']);
            if ($h !== null && $h['active'] === 1) $out[$e['role'] === 'main' ? 'mains' : 'fallbacks'][] = $h;
        }
        return $out;
    }

    /**
     * The voice services a program's words may go to (the privacy note on the
     * forms): every provider in its lineup.
     *
     * @param array<string,mixed> $channel
     * @param array<string,mixed> $program
     * @return list<string>
     */
    public function providers(array $channel, array $program): array
    {
        $l = $this->effective($channel, $program);
        $used = array_unique(array_column([...$l['mains'], ...$l['fallbacks']], 'provider'));
        return array_values(array_intersect(self::PROVIDERS, $used));
    }

    /**
     * A program's on-air hosts as listeners see them (the schedule).
     *
     * @param array<string,mixed> $channel
     * @param array<string,mixed> $program
     * @return list<array<string,mixed>>
     */
    public function publicMains(array $channel, array $program): array
    {
        return array_map([self::class, 'publicOf'], array_slice($this->effective($channel, $program)['mains'], 0, 6));
    }

    /**
     * The host channels.json names for a channel (and older apps show for
     * every moment): its lineup's first.
     *
     * @param array<string,mixed> $channel
     * @return array<string,mixed>
     */
    public function channelHost(array $channel): array
    {
        $l = $this->effective($channel, null);
        $first = $l['mains'][0] ?? $l['fallbacks'][0] ?? null;
        return $first !== null ? self::publicOf($first) : self::NOBODY;
    }

    /** As listeners see a host: minute and day files, channels.json. @return array<string,mixed>|null */
    public function info(?int $id): ?array
    {
        $h = $id !== null && $id > 0 ? $this->get($id) : null;
        return $h !== null ? self::publicOf($h) : null;
    }

    /** @param array<string,mixed> $h @return array<string,mixed> */
    private static function publicOf(array $h): array
    {
        return [
            'name' => (string) $h['name'],
            'avatar' => $h['avatar'] ?: null,
            'color' => (string) $h['color'],
            'about' => ['en' => (string) $h['about_en'], 'de' => (string) $h['about_de']],
            'voice' => (string) $h['provider'],
        ];
    }

    // --- who speaks ------------------------------------------------------------------------

    /**
     * Whether a host can speak now — local checks only, no network, so the
     * publish phase may ask: active, not resting, a voice, a key (none needed
     * in stub mode, where no provider is called), and room for $chars more
     * characters today. An ElevenLabs host needs a daily cap: without one it
     * never speaks (the station's account is a small one).
     *
     * A worker host needs a computer online that offers its voice. For a
     * private moment (one that names a listener, people's own words) only
     * one of ours counts; for a public one also a lender an admin let speak
     * on air.
     *
     * @param array<string,mixed> $h
     */
    public function canSpeak(array $h, int $chars = 0, bool $private = true): bool
    {
        $cached = $h['id'] . ($private ? ':private' : ':public');
        if ($chars <= 0 && isset($this->speaks[$cached])) return $this->speaks[$cached];
        $voice = self::voiceFor($h, $this->app->config->stationLangs()[0]);
        $ok = $h['active'] === 1
            && $h['resting_until'] <= $this->app->clock->now()
            && $voice !== ''
            // No key, but a computer online that offers its voice (not stubbed: our own hardware).
            && ($h['provider'] === 'worker' ? $this->app->computers()->online($voice, (string) $h['model'], $private) : ($this->app->config->stubAi() || $this->key($h) !== ''))
            && ($h['provider'] !== 'elevenlabs' || $h['max_chars_day'] > 0)
            && ($h['max_chars_day'] <= 0 || $this->usedToday($h['id']) + max(1, $chars) <= $h['max_chars_day']);
        if ($chars <= 0) $this->speaks[$cached] = $ok;
        return $ok;
    }

    /**
     * Room for every language of a moment: one host voices them all.
     *
     * @param array<string,mixed> $h
     * @param array<string,mixed> $texts
     */
    public function hasRoom(array $h, array $texts, bool $private = true): bool
    {
        $chars = 0;
        foreach ($texts as $t) $chars += mb_strlen((string) $t);
        return $this->canSpeak($h, max(1, $chars), $private);
    }

    /** Some host of the station can speak (whether listeners' prayers are taken at all). */
    public function anySpeaks(): bool
    {
        foreach ($this->byId() as $h) {
            if ($this->canSpeak($h)) return true;
        }
        return false;
    }

    /**
     * Someone in the lineup of this program can speak: only then does the
     * generator plan host moments (a request read out by nobody would be
     * taken, fail and be given back over and over).
     *
     * @param array<string,mixed> $channel
     * @param array<string,mixed>|null $program
     */
    public function speaksFor(array $channel, ?array $program): bool
    {
        $l = $this->effective($channel, $program);
        foreach ([...$l['mains'], ...$l['fallbacks']] as $h) {
            if ($this->canSpeak($h)) return true;
        }
        return false;
    }

    /**
     * The show a moment belongs to, by its program's block: the run's start,
     * or for a program on air for days, the local day's.
     *
     * @param array<string,mixed> $channel
     */
    public function showStart(array $channel, int $programId, int $atMs): int
    {
        $resolver = $this->app->resolver();
        $run = $resolver->runAt($channel, $atMs);
        if ($run['program_id'] !== $programId) return $atMs;
        if ($run['end'] - $run['start'] <= 86_400_000) return $run['start'];
        $day = (new \DateTimeImmutable('@' . intdiv($atMs, 1000)))->setTimezone($resolver->zone($channel))->setTime(0, 0);
        return max($run['start'], $day->getTimestamp() * 1000);
    }

    /**
     * Who hosts a show: the host picked for it — or, when it has none yet (or
     * its pick has left the lineup), one picked now at random among the
     * on-air hosts that can speak, not the previous show's when another can.
     * Null when none of them can speak now (the next moment tries again).
     *
     * @param array<string,mixed> $channel
     * @param list<array<string,mixed>> $mains
     */
    public function showHost(array $channel, int $programId, int $showStart, array $mains): ?int
    {
        $key = 'host_show:' . (int) $channel['id'] . ':' . $programId;
        $store = $this->app->store();
        /** @var list<array{0:int,1:int}> $shows */
        $shows = array_values(array_filter((array) ($store->get($key) ?? []), fn($s) => is_array($s) && count($s) === 2));
        $ids = array_column($mains, 'id');
        $before = null;
        $latest = PHP_INT_MIN;
        foreach ($shows as [$start, $host]) {
            if ((int) $start === $showStart && in_array((int) $host, $ids, true)) return (int) $host;
            if ((int) $start < $showStart && (int) $start > $latest) {
                $latest = (int) $start;
                $before = (int) $host;
            }
        }
        $speaking = array_values(array_filter($mains, fn($h) => $this->canSpeak($h)));
        if (!$speaking) return null;
        $fresh = array_values(array_filter($speaking, fn($h) => $h['id'] !== $before));
        $pool = $fresh ?: $speaking;
        $pick = (int) $pool[($this->rand)(0, count($pool) - 1)]['id'];
        $shows = array_values(array_filter($shows, fn($s) => (int) $s[0] !== $showStart));
        $shows[] = [$showStart, $pick];
        usort($shows, fn($a, $b) => (int) $a[0] <=> (int) $b[0]);
        $store->set($key, array_slice($shows, -self::SHOWS_KEPT));
        return $pick;
    }

    /**
     * Who speaks a moment: the show's host; else one of the same name (the
     * same persona with another voice: listeners hear no change of host);
     * else the fallbacks, in order; else the other on-air hosts — each only
     * when it can speak now, never one in $tried. Null: nobody can.
     * `$private`: whether the moment may name a listener (Hosts::canSpeak).
     *
     * @param array<string,mixed> $hb decoded host break
     * @param list<int> $tried
     * @return array<string,mixed>|null
     */
    public function forBreak(array $hb, array $tried = [], bool $private = true): ?array
    {
        $channel = $this->app->catalog()->channel((int) $hb['channel_id']);
        if ($channel === null) return null;
        $program = $hb['program_id'] !== null ? $this->app->catalog()->program((int) $hb['program_id']) : null;
        $l = $this->effective($channel, $program);
        $show = null;
        if ($program !== null && $l['mains']) {
            $start = (int) ($hb['context']['show'] ?? $this->showStart($channel, (int) $program['id'], $this->app->clock->nowMs()));
            $id = $this->showHost($channel, (int) $program['id'], $start, $l['mains']);
            $show = $id !== null ? $this->get($id) : null;
        }
        $show ??= $l['mains'][0] ?? null;
        $order = $show !== null ? [$show] : [];
        if ($show !== null) {
            foreach ([...$l['mains'], ...$l['fallbacks']] as $h) {
                if (mb_strtolower($h['name']) === mb_strtolower($show['name'])) $order[] = $h;
            }
        }
        array_push($order, ...$l['fallbacks'], ...$l['mains']);
        foreach ($order as $h) {
            if (!in_array($h['id'], $tried, true) && $this->canSpeak($h, 0, $private)) return $h;
        }
        return null;
    }

    /**
     * A clip that failed. Lasting (a quota used up, a wrong key, a voice the
     * provider does not know): the host rests until the next UTC day, or
     * until a moderator saves it. Temporary: counted, and TEMP_FAILS in a row
     * rest it for TEMP_REST.
     *
     * @param array<string,mixed> $h
     */
    public function failed(array $h, VoiceError $e): void
    {
        $now = $this->app->clock->now();
        $fails = $h['fail_count'] + 1;
        $until = $e->lasting() ? (intdiv($now, 86400) + 1) * 86400 : ($fails >= self::TEMP_FAILS ? $now + self::TEMP_REST : $h['resting_until']);
        $this->app->store()->update('hosts', ['fail_count' => $fails, 'resting_until' => $until, 'last_error' => VoiceError::redact($e->getMessage())], 'id = ?', [$h['id']]);
        if ($until > $now && $until !== $h['resting_until']) {
            $this->app->store()->audit('voice', 'Host resting', $h['id'] . ' ' . $h['name'] . ' until ' . gmdate('H:i', $until) . ' UTC: ' . VoiceError::redact($e->getMessage()));
        }
        $this->changed();
    }

    /** A clip that worked: the count of failures starts over (one write only when there were some). @param array<string,mixed> $h */
    public function succeeded(array $h): void
    {
        if ($h['fail_count'] > 0 || (string) $h['last_error'] !== '') {
            $this->app->store()->update('hosts', ['fail_count' => 0, 'last_error' => ''], 'id = ?', [$h['id']]);
            $this->changed();
        }
        // The characters it just used count against its cap.
        unset($this->speaks[$h['id']]);
    }

    /** A program deleted: its shows' picks go with it. */
    public function forgetProgram(int $channelId, int $programId): void
    {
        $this->app->store()->query('DELETE FROM kv WHERE key = ?', ['host_show:' . $channelId . ':' . $programId]);
    }

    // --- usage ------------------------------------------------------------------------------

    public static function usageKind(int $id): string
    {
        return 'tts:host:' . $id;
    }

    /** Characters spoken today (UTC) — what a daily cap counts. */
    public function usedToday(int $id): int
    {
        return (int) $this->app->store()->value(
            'SELECT COALESCE(SUM(input_tokens), 0) FROM ai_usage WHERE day = ? AND kind = ?',
            [gmdate('Y-m-d', $this->app->clock->now()), self::usageKind($id)],
        );
    }

    private function callsToday(int $id): int
    {
        return $this->app->usage()->callsToday(self::usageKind($id));
    }

    /**
     * For /mod Status: every host, and whether it can speak.
     *
     * @return list<array<string,mixed>>
     */
    public function summary(): array
    {
        $now = $this->app->clock->now();
        return array_map(fn($h) => [
            'id' => $h['id'],
            'name' => (string) $h['name'],
            'provider' => (string) $h['provider'],
            'model' => (string) $h['model'],
            'active' => $h['active'] === 1,
            'speaks' => $this->canSpeak($h),
            'restingUntil' => $h['resting_until'] > $now ? $h['resting_until'] : 0,
            'charsToday' => $this->usedToday($h['id']),
            'cap' => $h['max_chars_day'],
            'lastError' => (string) $h['last_error'],
        ], $this->all());
    }
}

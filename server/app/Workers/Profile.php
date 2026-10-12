<?php
declare(strict_types=1);

namespace Arche\Workers;

use Arche\ApiError;
use Arche\App;

/**
 * The station's profile for its computers (herde's protocol/PROTOCOL.md,
 * Profiles; station decision 2026-10-12): one setup for every computer that
 * speaks for a host. Another recording, checkpoint or setting is another
 * speaker, and a hundred computers each set up by its owner would be a
 * hundred hosts. So the station holds herde's speech code, the checkpoints and
 * their number format, the settings of every take, and our own voices with
 * their recordings. A computer fetches the profile when its hash changes,
 * speaks its check takes, and proves with every poll that it still runs it;
 * the station compares what each one reports with the profile itself
 * (`standing`), and offers speech only to a computer in step
 * (Tasks::matching).
 *
 * The recordings live in the data folder (voices/<sha256>.wav), never
 * public. Every computer gets the same profile, ours and lent ones alike
 * (station decision 2026-10-12: a computer that cannot speak our hosts'
 * voices is no use in the herd); what a lender may hear stays its own rule
 * (Workers\Tasks: public work only).
 */
final class Profile
{
    /**
     * herde 0.5.0's speech code (its engines/tts/core.py CODE) and the
     * checkpoints its pins load (config.py TTS_DEFAULTS). A computer on
     * another herde is told what differs; raise these by hand with herde,
     * once our own computers run it.
     */
    public const CODE = 1;
    public const BACKENDS = [
        'mlx' => [
            'model' => 'mlx-community/Qwen3-TTS-12Hz-1.7B-CustomVoice-bf16@52f4770fd9726457eae3d3b6aa92047a25a10776',
            'clone_model' => 'mlx-community/Qwen3-TTS-12Hz-1.7B-Base-bf16@a6eb4f68e4b056f1215157bb696209bc82a6db48',
        ],
        'torch' => [
            'model' => 'Qwen/Qwen3-TTS-12Hz-1.7B-CustomVoice@0c0e3051f131929182e2c023b9537f8b1c68adfe',
            'clone_model' => 'Qwen/Qwen3-TTS-12Hz-1.7B-Base@fd4b254389122332181a7c3db7f27e918eec64e3',
        ],
    ];
    /** bf16 only: an older NVIDIA card's fp16 would be a slightly different speaker. */
    public const DTYPES = ['bf16'];
    /** Every take's settings: herde's defaults, with which the owner chose the hosts by ear (pieces of 300, as the demo had them). */
    public const SPEECH = ['chunk_chars' => 300, 'pause_ms' => 150, 'top_k' => 50, 'top_p' => 1.0, 'repetition_penalty' => 1.05, 'mp3_kbps' => 96];
    /** Each computer speaks its check takes every six hours, and after any change. */
    public const CHECK_EVERY = 21600;
    /** A computer verifies its setup every 10 minutes: one that has not for longer than this speaks for no host. */
    public const VERIFIED_WITHIN = 1800;
    /** What a check take says: how a host opens. */
    private const CHECK_TEXT = [
        'de' => 'Guten Abend, hier ist ARCHE. Schön, dass du dabei bist.',
        'en' => 'Good evening, this is ARCHE. Great to have you with us.',
    ];
    /** Qwen's presets (herde's VOICES): an own voice by one of these names would be two voices behind one id. */
    private const PRESETS = ['ryan', 'aiden', 'vivian', 'serena', 'uncle_fu', 'dylan', 'eric', 'ono_anna', 'sohee'];
    /** The languages Qwen speaks (herde's LANGUAGES). */
    private const LANGS = ['en', 'de', 'fr', 'es', 'it', 'pt', 'ru', 'zh', 'ja', 'ko'];
    private const VOICE = '/^[A-Za-z0-9_-]{1,40}$/';
    /**
     * A recording as herde takes one: PCM WAV of 3 to 30 s (every take reads
     * all of it first), its words at most 1000 characters — and at most 8 MB,
     * the host's upload limit (.user.ini; herde would take 10).
     */
    private const FILE_MAX = 8 * 1024 * 1024;
    private const SECONDS_MIN = 3.0;
    private const SECONDS_MAX = 30.0;
    private const TEXT_MAX = 1000;

    /** @var array<string,mixed>|null this request's profile */
    private ?array $document = null;

    public function __construct(private App $app) {}

    // --- what a computer gets (Api\WorkerApi) ----------------------------------------------------

    /** The profile as `profile` answers it: its hash, and what the hash is over. @return array<string,mixed> */
    public function document(): array
    {
        return $this->document ??= $this->build();
    }

    public function hash(): string
    {
        return (string) $this->document()['profile'];
    }

    /** The answer to `profile`: the profile, and the very bytes to send (no encoder may change a number in them). @return array{0:array<string,mixed>,1:string} */
    public function served(): array
    {
        $doc = $this->document();
        return [$doc, self::canonical($doc)];
    }

    /** A recording the profile names, by its SHA-256: its path — any other is not found. */
    public function path(string $sha): string
    {
        $named = false;
        foreach ($this->document()['tts']['voices'] as $v) {
            foreach ($v['langs'] as $rec) $named = $named || $rec['file'] === $sha;
        }
        $path = $this->dir() . '/' . $sha . '.wav';
        if (!$named || !is_file($path)) throw new ApiError(404, 'not_found');
        return $path;
    }

    /**
     * Where a computer stands with its profile, from its poll's report and its
     * caps (PROTOCOL.md, Profiles): 'ok' — the current profile, verified
     * lately, its checks passed, and a setup the station finds to be the
     * profile's — or why not, with a detail for admins: 'old' (a herde that
     * knows no profile), 'syncing', 'unmet', 'failing', 'stale' (not verified
     * for too long) or 'differs' (its setup is not the profile's).
     *
     * @param array<string,mixed> $c decoded computer, with this poll's caps
     * @return array{0:string,1:string}
     */
    public function standing(array $c, mixed $report): array
    {
        if (!is_array($report)) return ['old', ''];
        $state = (string) ($report['state'] ?? '');
        $detail = mb_substr(trim((string) preg_replace('/\s+/u', ' ', is_string($report['detail'] ?? null) ? $report['detail'] : '')), 0, 200);
        if (($report['hash'] ?? '') !== $this->hash() || !in_array($state, ['ok', 'unmet', 'failing'], true)) return ['syncing', $detail];
        if ($state !== 'ok') return [$state, $detail];
        $ago = $report['verified_ago'] ?? null;
        if (!is_int($ago) || $ago > self::VERIFIED_WITHIN) return ['stale', ''];
        $setup = null;
        foreach (Computers::engines($c, 'tts') as $e) $setup = $e['setup'] ?? null;
        if (!is_array($setup)) return ['differs', 'no setup'];
        $differs = self::differences($setup, $this->document()['tts']);
        return $differs ? ['differs', implode(', ', $differs)] : ['ok', ''];
    }

    /**
     * What a computer's setup (from its caps) has other than the profile:
     * nothing when it runs it. Its own report is not taken for it.
     *
     * @param array<string,mixed> $setup
     * @param array<string,mixed> $tts the profile's speech part
     * @return list<string>
     */
    public static function differences(array $setup, array $tts): array
    {
        $out = [];
        if (($setup['code'] ?? null) !== $tts['code']) $out[] = 'speech code ' . (is_int($setup['code'] ?? null) ? $setup['code'] : '?');
        $backend = is_string($setup['backend'] ?? null) ? $setup['backend'] : '';
        $pins = $tts['backends'][$backend] ?? null;
        if ($pins === null) {
            $out[] = 'backend ' . ($backend !== '' ? $backend : '?');
        } else {
            if (($setup['model'] ?? '') !== $pins['model']) $out[] = 'checkpoint ' . ((string) ($setup['model'] ?? '') ?: 'none');
            if ($tts['voices'] && ($setup['clone_model'] ?? '') !== $pins['clone_model']) $out[] = 'Base checkpoint ' . ((string) ($setup['clone_model'] ?? '') ?: 'none');
            // The stub loads no weights (local stacks and tests only).
            if ($backend !== 'stub' && !in_array($setup['dtype'] ?? '', $tts['dtypes'], true)) $out[] = 'number format ' . ((string) ($setup['dtype'] ?? '') ?: '?');
        }
        $speech = is_array($setup['speech'] ?? null) ? $setup['speech'] : [];
        foreach ($tts['speech'] as $k => $v) {
            $has = $speech[$k] ?? null;
            if ((!is_int($has) && !is_float($has)) || abs((float) $has - (float) $v) > 1e-9) $out[] = $k;
        }
        $want = [];
        foreach ($tts['voices'] as $v) {
            foreach ($v['langs'] as $lang => $rec) $want[$v['id']][$lang] = $rec['file'];
        }
        if (self::sorted(is_array($setup['voices'] ?? null) ? $setup['voices'] : []) !== self::sorted($want)) $out[] = 'recordings';
        return $out;
    }

    /**
     * JSON as herde hashes it (its protocol.canonical_hash): keys sorted, no
     * spaces, UTF-8 as it is — and a number as Python writes it back (1.0
     * stays 1.0). The profile goes out as these very bytes.
     */
    public static function canonical(mixed $value): string
    {
        return json_encode(self::sorted($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    // --- our own voices (admins, /mod › Rechner) ------------------------------------------------

    /** As /mod shows the profile: our own voices, and what every computer must run. @return array<string,mixed> */
    public function view(): array
    {
        $voices = [];
        foreach ($this->app->store()->all('SELECT * FROM worker_voices ORDER BY lower(voice), lang') as $r) {
            $id = (string) $r['voice'];
            $voices[$id] ??= ['id' => $id, 'langs' => []];
            $voices[$id]['langs'][] = ['lang' => (string) $r['lang'], 'seconds' => round((float) $r['seconds'], 1), 'bytes' => (int) $r['bytes'],
                'text' => (string) $r['text'], 'updated' => (int) $r['updated']];
        }
        $tts = $this->document()['tts'];
        return ['voices' => array_values($voices), 'hash' => $this->hash(), 'code' => self::CODE, 'backends' => array_keys($tts['backends']),
            'dtypes' => self::DTYPES, 'speech' => self::SPEECH, 'checks' => $tts['checks']];
    }

    /**
     * A recording of an own voice in one language, with exactly what it says:
     * a new voice, a new language of one, or a new take. Checked as herde
     * checks it and kept by its SHA-256. Every computer fetches it with its
     * next poll and checks it before it speaks for a host again.
     *
     * @return array<string,mixed> the profile as /mod shows it
     */
    public function addRecording(string $voice, string $lang, string $text, ?string $tmp, string $actor): array
    {
        $voice = trim($voice);
        if (!preg_match(self::VOICE, $voice) || in_array(strtolower($voice), self::PRESETS, true)) throw new ApiError(422, 'voice_name');
        if (!in_array($lang, self::LANGS, true)) throw new ApiError(422, 'voice_lang');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '' || mb_strlen($text) > self::TEXT_MAX) throw new ApiError(422, 'voice_text');
        if ($tmp === null || !is_file($tmp) || (int) filesize($tmp) > self::FILE_MAX) throw new ApiError(422, 'voice_file');
        $seconds = self::seconds($tmp) ?? throw new ApiError(422, 'voice_file');
        if ($seconds < self::SECONDS_MIN || $seconds > self::SECONDS_MAX) throw new ApiError(422, 'voice_length');
        $store = $this->app->store();
        // One voice, one spelling: "faith" in another language is Faith's.
        $known = $store->value('SELECT voice FROM worker_voices WHERE lower(voice) = lower(?) LIMIT 1', [$voice]);
        if (is_string($known)) $voice = $known;
        $bytes = (string) file_get_contents($tmp);
        $sha = hash('sha256', $bytes);
        $dir = $this->dir();
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        $path = "$dir/$sha.wav";
        if (!is_file($path)) {
            file_put_contents("$path.tmp", $bytes);
            rename("$path.tmp", $path);
        }
        $now = $this->app->clock->now();
        $before = $store->value('SELECT sha256 FROM worker_voices WHERE voice = ? AND lang = ?', [$voice, $lang]);
        $store->query(
            'INSERT INTO worker_voices (voice, lang, sha256, bytes, seconds, text, created, updated) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (voice, lang) DO UPDATE SET sha256 = excluded.sha256, bytes = excluded.bytes, seconds = excluded.seconds, text = excluded.text, updated = excluded.updated',
            [$voice, $lang, $sha, strlen($bytes), $seconds, $text, $now, $now],
        );
        if (is_string($before) && $before !== $sha) $this->dropFile($before);
        $store->audit($actor, 'Worker voice recorded', $voice . ' ' . $lang . ' (' . round($seconds, 1) . ' s, ' . substr($sha, 0, 12) . ')');
        $this->changed();
        return $this->view();
    }

    /** One language's recording removed (the last one removes the voice). @return array<string,mixed> */
    public function removeRecording(string $voice, string $lang, string $actor): array
    {
        $sha = $this->app->store()->value('SELECT sha256 FROM worker_voices WHERE voice = ? AND lang = ?', [$voice, $lang]);
        if (!is_string($sha)) throw new ApiError(404, 'not_found');
        $this->app->store()->query('DELETE FROM worker_voices WHERE voice = ? AND lang = ?', [$voice, $lang]);
        $this->dropFile($sha);
        $this->app->store()->audit($actor, 'Worker voice removed', $voice . ' ' . $lang);
        $this->changed();
        return $this->view();
    }

    /**
     * A PCM WAV's length in seconds, or null when it is none — herde reads it
     * with Python's wave module, which takes plain PCM only.
     */
    public static function seconds(string $path): ?float
    {
        $f = @fopen($path, 'rb');
        if ($f === false) return null;
        try {
            $head = (string) fread($f, 12);
            if (strlen($head) !== 12 || substr($head, 0, 4) !== 'RIFF' || substr($head, 8, 4) !== 'WAVE') return null;
            $total = (int) filesize($path);
            $fmt = null;
            while (true) {
                $chunk = (string) fread($f, 8);
                if (strlen($chunk) < 8) return null;
                ['id' => $id, 'size' => $size] = (array) unpack('a4id/Vsize', $chunk);
                if ($id === 'fmt ') {
                    $fmt = unpack('vformat/vchannels/Vrate/Vbyterate/valign/vbits', (string) fread($f, 16));
                    if ($size > 16) fseek($f, $size - 16 + ($size % 2), SEEK_CUR);
                } elseif ($id === 'data') {
                    if (!is_array($fmt) || $fmt['format'] !== 1 || $fmt['channels'] < 1 || $fmt['rate'] < 1 || !in_array($fmt['bits'], [8, 16, 24, 32], true)) return null;
                    // A header that claims more than the file holds is no recording.
                    if ($size > $total - (int) ftell($f)) return null;
                    return $size / ($fmt['rate'] * $fmt['channels'] * intdiv($fmt['bits'], 8));
                } else {
                    fseek($f, $size + ($size % 2), SEEK_CUR);
                }
            }
        } finally {
            fclose($f);
        }
    }

    // --- inside -----------------------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function build(): array
    {
        $own = [];
        $rows = $this->app->store()->all('SELECT * FROM worker_voices ORDER BY voice, lang');
        foreach ($rows as $r) $own[(string) $r['voice']][(string) $r['lang']] = ['file' => (string) $r['sha256'], 'bytes' => (int) $r['bytes'], 'text' => (string) $r['text']];
        $voices = [];
        foreach ($own as $id => $langs) $voices[] = ['id' => $id, 'langs' => $langs];
        $tts = [
            'model' => Computers::TTS,
            'code' => self::CODE,
            'backends' => $this->backends(),
            'dtypes' => self::DTYPES,
            'speech' => self::SPEECH,
            'voices' => $voices,
            'checks' => ['every' => self::CHECK_EVERY, 'takes' => $this->takes($own)],
        ];
        return ['profile' => hash('sha256', self::canonical(['tts' => $tts])), 'tts' => $tts];
    }

    /** herde's backends — and on a local stack or in tests the stub, which speaks silence. @return array<string,array{model:string,clone_model:string}> */
    private function backends(): array
    {
        $stub = in_array($this->app->config->get('ARCHE_ENV'), ['local', 'test'], true);
        return self::BACKENDS + ($stub ? ['stub' => ['model' => '', 'clone_model' => '']] : []);
    }

    /**
     * The check takes: each own voice of this profile in each language it was
     * recorded in, and each of Qwen's presets a host of ours speaks with — in
     * the words a host opens with, where the station has them.
     *
     * @param array<string,array<string,mixed>> $own
     * @return list<array{voice:string,lang:string,text:string}>
     */
    private function takes(array $own): array
    {
        $pairs = [];
        foreach ($own as $id => $langs) {
            foreach (array_keys($langs) as $lang) $pairs["$id/$lang"] = [$id, $lang];
        }
        foreach ($this->app->store()->all("SELECT voices FROM hosts WHERE provider = 'worker' AND active = 1") as $h) {
            foreach ((array) json_decode((string) $h['voices'], true) as $lang => $voice) {
                if (is_string($lang) && is_string($voice) && in_array(strtolower($voice), self::PRESETS, true)) $pairs["$voice/$lang"] ??= [$voice, $lang];
            }
        }
        ksort($pairs, SORT_STRING);
        $takes = [];
        foreach ($pairs as [$voice, $lang]) {
            if (isset(self::CHECK_TEXT[$lang])) $takes[] = ['voice' => $voice, 'lang' => $lang, 'text' => self::CHECK_TEXT[$lang]];
        }
        return $takes;
    }

    /**
     * The profile changed: no computer is in step with it until it fetched
     * it and passed its checks — not even for the seconds until its next poll.
     */
    private function changed(): void
    {
        $this->document = null;
        $this->app->store()->query("UPDATE computers SET profile_state = 'syncing', profile_detail = '' WHERE profile_state = 'ok'");
        $this->app->hosts()->refresh();
    }

    /** A recording no voice names any more. */
    private function dropFile(string $sha): void
    {
        if ($this->app->store()->value('SELECT 1 FROM worker_voices WHERE sha256 = ? LIMIT 1', [$sha]) === null) @unlink($this->dir() . '/' . $sha . '.wav');
    }

    private function dir(): string
    {
        return $this->app->config->dataDir . '/voices';
    }

    private static function sorted(mixed $v): mixed
    {
        if (!is_array($v)) return $v;
        if (!array_is_list($v)) ksort($v, SORT_STRING);
        return array_map([self::class, 'sorted'], $v);
    }
}

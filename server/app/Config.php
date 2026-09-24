<?php
declare(strict_types=1);

namespace Arche;

/**
 * Settings, from (first match wins) the process environment, the .env file,
 * then config/defaults.php.
 *
 * Three directories matter and they differ between the host and the repo:
 *
 *   root       /_arche          (host)   server/            (repo, tests)
 *   publicDir  the web root /            .data/site in Docker, a temp dir in tests
 *   dataDir    /_arche/var               likewise
 *
 * On the host /_arche sits inside the web root (the SFTP account is chrooted
 * to it), so publicDir defaults to dirname(root).
 */
final class Config
{
    /** @var array<string,string> */
    private array $defaults;

    /** @param array<string,string> $env */
    public function __construct(
        private array $env,
        public readonly string $root,
        public readonly string $publicDir,
        public readonly string $dataDir,
    ) {
        /** @var array<string,string> $defaults */
        $defaults = require $root . '/config/defaults.php';
        $this->defaults = $defaults;
    }

    public static function load(?string $root = null): self
    {
        $root ??= dirname(__DIR__);
        $envFile = getenv('ARCHE_ENV_FILE') ?: $root . '/.env';
        $env = self::readEnv($envFile);
        $pick = static fn(string $k): string => (string) (getenv($k) ?: ($env[$k] ?? ''));
        $publicDir = $pick('ARCHE_PUBLIC_DIR') ?: dirname($root);
        $dataDir = $pick('ARCHE_DATA_DIR') ?: $root . '/var';
        return new self($env, $root, rtrim($publicDir, '/'), rtrim($dataDir, '/'));
    }

    /**
     * Parsed, never sourced: the SFTP password in the same file contains shell
     * metacharacters. Same rules as the deploy scripts' reader.
     *
     * @return array<string,string>
     */
    public static function readEnv(string $path): array
    {
        $values = [];
        if (!is_file($path)) return $values;
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (!preg_match('/^\s*([A-Z][A-Z0-9_]*)\s*=(.*)$/', $line, $m)) continue;
            $value = trim($m[2]);
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && str_ends_with($value, $value[0])) {
                $value = substr($value, 1, -1);
            }
            $values[$m[1]] = $value;
        }
        return $values;
    }

    public function get(string $key, string $default = ''): string
    {
        $v = getenv($key);
        if ($v !== false && $v !== '') return $v;
        if (($this->env[$key] ?? '') !== '') return $this->env[$key];
        return $this->defaults[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== '';
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->get($key);
        return is_numeric($v) ? (int) $v : $default;
    }

    public function float(string $key, float $default = 0.0): float
    {
        $v = $this->get($key);
        return is_numeric($v) ? (float) $v : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $v = strtolower($this->get($key));
        if ($v === '') return $default;
        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }

    /** @return list<string> comma-separated, trimmed, empties dropped */
    public function list(string $key): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $this->get($key))), fn($s) => $s !== ''));
    }

    /** @return array<mixed> */
    public function json(string $key): array
    {
        $v = $this->get($key);
        if ($v === '') return [];
        $d = json_decode($v, true);
        return is_array($d) ? $d : [];
    }

    public function isLocal(): bool
    {
        return $this->get('ARCHE_ENV') === 'local';
    }

    public function stubAi(): bool
    {
        return $this->get('AI_MODE') === 'stub';
    }

    /**
     * Where the app loads program files and media from: the CDN's base URL,
     * or '' for the origin. 'off' switches it off whatever the .env says — the
     * local stacks share the .env with production and must never use its CDN.
     */
    public function cdnBase(): string
    {
        $v = rtrim($this->get('CDN_BASE_URL'), '/');
        return $v === 'off' ? '' : $v;
    }

    public function anthropicKey(): string
    {
        return $this->get('ANTHROPIC_KEY') ?: $this->get('ANTHROPIC_API_KEY');
    }

    public function openaiKey(): string
    {
        return $this->get('OPENAI_KEY') ?: $this->get('OPENAI_API_KEY');
    }

    /**
     * Who writes the host's words and judges submissions: 'stub'
     * (AI_MODE=stub), 'anthropic' or 'openai' — as AI_TEXT_PROVIDER says, or
     * with 'auto' whichever key is set, Anthropic first. '' = no text model:
     * the host speaks from templates, and submissions are not offered (and
     * would fail closed).
     */
    public function textProvider(): string
    {
        if ($this->stubAi()) return 'stub';
        $anthropic = $this->anthropicKey() !== '';
        $openai = $this->openaiKey() !== '';
        return match (strtolower($this->get('AI_TEXT_PROVIDER', 'auto'))) {
            'anthropic' => $anthropic ? 'anthropic' : '',
            'openai' => $openai ? 'openai' : '',
            default => $anthropic ? 'anthropic' : ($openai ? 'openai' : ''),
        };
    }

    /** @return list<string> the languages every host break is voiced in */
    public function stationLangs(): array
    {
        $langs = array_values(array_intersect($this->list('STATION_LANGS'), ['en', 'de']));
        return $langs ?: ['en', 'de'];
    }

    /** A copy with some values replaced — for tests. @param array<string,string> $values */
    public function with(array $values): self
    {
        return new self(array_merge($this->env, $values), $this->root, $this->publicDir, $this->dataDir);
    }
}

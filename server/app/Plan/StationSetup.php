<?php
declare(strict_types=1);

namespace Arche\Plan;

use Arche\App;
use Arche\Support\Files;

/**
 * The station's setup in one file, to try the program on a local stack with
 * what is on air: channels, programs and their plans, the library and its
 * groups, the hosts with their lineups and recorded lines (/mod › Status,
 * admins; `npm run setup:import` at home).
 *
 * Only what the station made. Nothing listeners handed in or who they are:
 * no recording that went into the library (`contrib`, titled with a name and
 * a place), no link to a submission, no opening prayer (it names who prays),
 * no moderator's notes or ids. Nothing secret: a host's key is sealed with
 * this station's pepper and goes blank, with its rest and its last error.
 * Media travel as public paths the import fetches from the site.
 */
final class StationSetup
{
    public const FORMAT = 'arche-station-setup';
    public const VERSION = 1;

    /**
     * What travels, and what each table leaves behind: the value a column
     * gets instead, on the way out and again on the way in (a file edited by
     * hand brings no key either).
     */
    private const TABLES = [
        'channels' => [],
        'programs' => [],
        'day_plans' => [],
        'day_plan_blocks' => [],
        'week_plan' => [],
        'special_days' => [],
        'library_groups' => ['note' => ''],
        'library_items' => ['submission_id' => null],
        'hosts' => ['api_key' => '', 'key_hint' => '', 'last_error' => '', 'resting_until' => 0, 'fail_count' => 0],
        'host_lineups' => [],
        'program_lines' => [],
        'host_line_options' => [],
        'host_lines' => ['created_by' => '', 'note' => '', 'error' => ''],
        // What was looked up about the library's songs and videos (Library\Knowledge): about them, never a
        // listener; no look-up in progress, no moderator's id.
        'video_knowledge' => ['work' => '{}', 'edited_by' => '', 'error' => ''],
    ];

    /** Tables a file from before them lacks: it still imports, and the local rows stay. */
    private const OPTIONAL = ['video_knowledge'];

    /**
     * Rows that stay home: listeners' recordings, and lines that are not
     * ready (a local station would record them again, at a cost).
     */
    private const ONLY = [
        'library_items' => "kind <> 'contrib'",
        'host_lines' => "state IN ('active', 'paused', 'draft')",
        // Only what is known of the library's items: never a turned-down request's video.
        'video_knowledge' => "state = 'ready' AND yt_id IN (SELECT yt_id FROM library_items WHERE yt_id IS NOT NULL AND kind <> 'contrib')",
    ];

    /**
     * What a local station drops with its old setup, since it points at its
     * channels, programs, library items or hosts: the program and the work
     * on it, and what was handed in for it. Identities (the local admin),
     * computers, usage and the audit stay.
     */
    private const LOCAL = [
        'timeline_items', 'host_breaks', 'jobs', 'worker_tasks',
        'prayed_along', 'wall_reports', 'submissions', 'opening_prayers',
        'reactions', 'playback_errors',
    ];

    /** Remembered state about the old channels, programs and hosts; `task:` so every local task runs at the next tick. */
    private const KV = [
        'frontier:%', 'published:%', 'draft_version:%', 'draft_library:%', 'waiting:%', 'evergreen:%',
        'host_show:%', 'fact_at:%', 'lines_requests:%', 'cdn_listeners:%', 'task:%', 'library_fingerprint', 'channels_hash',
    ];

    /** Larger than any picture, jingle or piece of prayer music (10 min). */
    private const MEDIA_MAX = 40_000_000;

    public function __construct(private App $app) {}

    /** @return array<string,mixed> */
    public function export(): array
    {
        $store = $this->app->store();
        $tables = [];
        foreach (array_keys(self::TABLES) as $table) {
            $rows = $store->all("SELECT * FROM $table WHERE " . (self::ONLY[$table] ?? '1') . ' ORDER BY rowid');
            $tables[$table] = array_map(fn(array $row): array => $this->clean($table, $row), $rows);
        }
        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'schema' => (int) $store->get('schema', 0),
            'site' => rtrim($this->app->config->get('SITE_BASE_URL'), '/'),
            'exported' => $this->app->clock->now(),
            'tables' => $tables,
            'media' => self::media($tables),
        ];
    }

    /** @param array<string,list<array<string,mixed>>> $tables @return array<string,int> */
    public static function counts(array $tables): array
    {
        return array_map('count', $tables);
    }

    /**
     * Replace this station's setup with an exported one and start its
     * program afresh, as after an outage (the next tick re-anchors every
     * channel). Ticks pause meanwhile; the database is copied first.
     *
     * @param array<string,mixed> $data
     * @param (callable(string):void)|null $say progress, a line at a time
     * @return array<string,mixed>
     */
    public function import(array $data, ?callable $say = null): array
    {
        $say ??= static function (string $line): void {};
        // The host has no PHP CLI, but a station's database is never replaced from a file.
        if (!$this->app->config->isLocal()) throw new \RuntimeException('Only a local stack takes an imported setup (ARCHE_ENV=local).');
        $tables = $this->read($data);
        $site = (string) ($data['site'] ?? '');
        if (!preg_match('~^https://[A-Za-z0-9.-]+(:\d{1,5})?$~', $site)) throw new \InvalidArgumentException('The setup names no https site to fetch its media from.');
        $schema = (int) $this->app->store()->get('schema', 0);
        if ((int) ($data['schema'] ?? 0) !== $schema) {
            $say(sprintf('Schema %d here, %d there: only the columns both know are taken.', $schema, (int) ($data['schema'] ?? 0)));
        }

        // The maintenance flag (Tick) keeps new ticks out; a tick under way finishes, then the locks are ours.
        $flag = $this->app->config->dataDir . '/maintenance';
        @file_put_contents($flag, (string) $this->app->clock->now());
        try {
            $backup = $this->backup();
            $say('Backup: ' . $backup);
            $counts = $this->locked(fn(): array => $this->replace($tables, $site, (int) ($data['exported'] ?? 0)));
            foreach ($counts as $table => $n) $say(sprintf('%-18s %d', $table, $n));
            $media = $this->fetch(self::media($tables), $site, $say);
        } finally {
            @unlink($flag);
        }
        return ['backup' => $backup, 'tables' => $counts, 'media' => $media];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function clean(string $table, array $row): array
    {
        return array_replace($row, array_intersect_key(self::TABLES[$table], $row));
    }

    /**
     * The file's tables, each a list of rows of plain values. Column names
     * are only ever used after matching this station's own (columns()).
     *
     * @param array<string,mixed> $data
     * @return array<string,list<array<string,mixed>>>
     */
    private function read(array $data): array
    {
        if (($data['format'] ?? null) !== self::FORMAT) throw new \InvalidArgumentException('This is not a station setup exported from /mod.');
        if ((int) ($data['version'] ?? 0) > self::VERSION) throw new \InvalidArgumentException('This setup comes from a newer ARCHE: update this checkout first.');
        $tables = [];
        foreach (array_keys(self::TABLES) as $table) {
            $rows = $data['tables'][$table] ?? null;
            if ($rows === null && in_array($table, self::OPTIONAL, true)) continue;
            if (!is_array($rows) || !array_is_list($rows)) throw new \InvalidArgumentException("The setup has no $table.");
            $keep = [];
            foreach ($rows as $row) {
                if (!is_array($row) || $row === []) throw new \InvalidArgumentException("A row of $table is not a row.");
                foreach ($row as $value) {
                    if ($value !== null && !is_scalar($value)) throw new \InvalidArgumentException("A row of $table holds more than plain values.");
                }
                if ($table === 'library_items' && ($row['kind'] ?? '') === 'contrib') continue;
                $keep[] = $this->clean($table, $row);
            }
            $tables[$table] = $keep;
        }
        return $tables;
    }

    /**
     * In one transaction: the local program and what points at the old
     * setup go, the setup's tables are written whole and must hold together.
     *
     * @param array<string,list<array<string,mixed>>> $tables
     * @return array<string,int>
     */
    private function replace(array $tables, string $site, int $exported): array
    {
        $store = $this->app->store();
        // SQLite ignores this pragma inside a transaction. Off, parents and
        // children are replaced in any order without cascades; the check
        // below stands in for it before the commit.
        $store->db->exec('PRAGMA foreign_keys=OFF');
        try {
            return $store->tx(function () use ($store, $tables, $site, $exported): array {
                foreach (self::LOCAL as $table) {
                    if ($this->columns($table) !== []) $store->query("DELETE FROM $table");
                }
                $store->query('DELETE FROM kv WHERE ' . implode(' OR ', array_fill(0, count(self::KV), 'key LIKE ?')), self::KV);
                foreach ($tables as $table => $rows) {
                    $store->query("DELETE FROM $table");
                    $columns = $this->columns($table);
                    foreach ($rows as $row) {
                        $row = array_intersect_key($row, $columns);
                        if ($row !== []) $store->insert($table, $row);
                    }
                }
                foreach (array_keys($tables) as $table) {
                    $broken = $store->all("PRAGMA foreign_key_check($table)");
                    if ($broken !== []) {
                        $b = $broken[0];
                        throw new \RuntimeException(sprintf('The setup does not hold together: %s row %s points at a missing %s.', $table, (string) $b['rowid'], (string) $b['parent']));
                    }
                }
                // Like any plan change: every cache of the old plan is thrown away.
                $store->set('plan_version', (int) $store->get('plan_version', 0) + 1);
                $counts = self::counts($tables);
                $store->audit('console', 'Station setup imported', sprintf('from %s, exported %s: %d programs, %d library items, %d hosts',
                    $site, gmdate('Y-m-d H:i', $exported), $counts['programs'], $counts['library_items'], $counts['hosts']));
                return $counts;
            });
        } finally {
            $store->db->exec('PRAGMA foreign_keys=ON');
        }
    }

    /** @return array<string,true> this station's columns of $table (none: no such table) */
    private function columns(string $table): array
    {
        $out = [];
        foreach ($this->app->store()->all("PRAGMA table_info($table)") as $c) $out[(string) $c['name']] = true;
        return $out;
    }

    /**
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private function locked(callable $fn): mixed
    {
        $lock = $this->app->lock();
        for ($try = 0; $try < 60; $try++) {
            $done = false;
            $out = $lock->run('publish', function () use ($lock, $fn, &$done) {
                return $lock->run('jobs', function () use ($fn, &$done) {
                    $done = true;
                    return $fn();
                });
            });
            if ($done) return $out;
            sleep(1);
        }
        throw new \RuntimeException('A tick is still running: try again in a minute.');
    }

    /** A copy of this station's database as it was, next to the daily backups (three kept). */
    private function backup(): string
    {
        $dir = $this->app->config->dataDir . '/backups';
        Files::ensureDir($dir, 0700);
        $file = $dir . '/before-import-' . gmdate('Ymd-His', $this->app->clock->now()) . '.sqlite';
        if (!is_file($file)) $this->app->store()->db->exec('VACUUM INTO ' . $this->app->store()->db->quote($file));
        $all = glob($dir . '/before-import-*.sqlite') ?: [];
        rsort($all);
        foreach (array_slice($all, 3) as $old) @unlink($old);
        return basename($file);
    }

    /**
     * The media the setup shows or plays: channel and program pictures,
     * hosts' avatars, jingles, prayer music, thumbnails, recorded lines.
     *
     * @param array<string,list<array<string,mixed>>> $tables
     * @return list<string>
     */
    private static function media(array $tables): array
    {
        $paths = [];
        foreach ($tables['channels'] as $r) $paths[] = $r['host_avatar'] ?? null;
        foreach ($tables['programs'] as $r) $paths[] = $r['image'] ?? null;
        foreach ($tables['hosts'] as $r) $paths[] = $r['avatar'] ?? null;
        foreach ($tables['library_items'] as $r) array_push($paths, $r['audio'] ?? null, $r['thumb'] ?? null);
        foreach ($tables['host_lines'] as $r) {
            $audio = json_decode((string) ($r['audio'] ?? '{}'), true);
            if (is_array($audio)) array_push($paths, ...array_values($audio));
        }
        $out = [];
        foreach ($paths as $p) {
            if (is_string($p) && self::mediaPath($p)) $out[$p] = true;
        }
        return array_keys($out);
    }

    /** One file in one folder of /media, named as Library\Media names its files. */
    private static function mediaPath(string $path): bool
    {
        return preg_match('~^/media/[a-z0-9_-]{1,40}/[A-Za-z0-9_-][A-Za-z0-9._-]{2,80}$~', $path) === 1 && !preg_match('/\.ph/i', $path);
    }

    /**
     * Fetch what this station does not have yet from the public site. Names
     * carry their content (a hash, a video id), so a file here is the same.
     * A file that does not come is reported, never fatal.
     *
     * @param list<string> $paths
     * @param callable(string):void $say
     * @return array{fetched:int,kept:int,failed:list<string>}
     */
    private function fetch(array $paths, string $site, callable $say): array
    {
        $out = ['fetched' => 0, 'kept' => 0, 'failed' => []];
        $media = $this->app->media();
        foreach ($paths as $i => $path) {
            $file = $media->path($path);
            if ($file === null) continue;
            if (is_file($file)) {
                $out['kept']++;
                continue;
            }
            // A CLI run has no tick's budget to keep to, only each file's own timeout.
            $this->app->budget->restart(30);
            try {
                $r = $this->app->http()->get($site . $path, [], 20);
                $ok = $r->status === 200 && $r->body !== '' && strlen($r->body) <= self::MEDIA_MAX;
            } catch (\Throwable) {
                $ok = false;
            }
            if (!$ok) {
                $out['failed'][] = $path;
                continue;
            }
            Files::write($file, $r->body);
            $out['fetched']++;
            if (($i + 1) % 50 === 0) $say(sprintf('Media: %d of %d', $i + 1, count($paths)));
        }
        $say(sprintf('Media: %d fetched, %d already here, %d failed', $out['fetched'], $out['kept'], count($out['failed'])));
        return $out;
    }
}

<?php
declare(strict_types=1);

use Arche\App;
use Arche\Config;
use Arche\Support\FixedClock;

/** Builds an isolated App per test: temp web root + data dir, stub AI, fixed clock. */
final class TestKit
{
    /** Wednesday 2026-09-23 10:00:00 UTC (12:00 in Berlin). */
    public const T0 = 1790157600000;

    /** @var list<string> */
    private static array $dirs = [];

    /** @param array<string,string> $env */
    public static function app(array $env = [], int $nowMs = self::T0): App
    {
        $dir = sys_get_temp_dir() . '/arche-test-' . bin2hex(random_bytes(5));
        mkdir($dir . '/public', 0777, true);
        mkdir($dir . '/data', 0777, true);
        self::$dirs[] = $dir;
        $config = new Config(array_merge([
            'AI_MODE' => 'stub',
            'ARCHE_ENV' => 'test',
            'IDENTITY_PEPPER' => str_repeat('a1', 32),
            'CRON_KEY' => str_repeat('c', 40),
            'NODE_SECRET' => str_repeat('n', 40),
            'ADMIN_SETUP_KEY' => str_repeat('s', 40),
            'TICK_BUDGET' => '60',
            'HOST_MIN_LISTENERS' => '0',
            'YOUTUBE_API_KEY' => '',
            'REALTIME_DRIVER' => 'static',
            'THUMBS' => '0',
        ], $env), dirname(__DIR__), $dir . '/public', $dir . '/data');
        return new App($config, new FixedClock($nowMs));
    }

    public static function clock(App $app): FixedClock
    {
        $c = $app->clock;
        if (!$c instanceof FixedClock) throw new LogicException('not a fixed clock');
        return $c;
    }

    /** Insert songs straight into the library. @return list<int> */
    public static function songs(App $app, int $n, int $durMs = 240_000, array $extra = []): array
    {
        $ids = [];
        for ($i = 1; $i <= $n; $i++) {
            $ids[] = $app->store()->insert('library_items', $extra + [
                'kind' => 'song',
                'yt_id' => substr(str_pad('v' . $i . bin2hex(random_bytes(3)), 11, 'x'), 0, 11),
                'title' => "Song $i",
                'artist' => "Artist $i",
                'duration_ms' => $durMs,
                'themes' => '["worship"]',
                'created' => 1,
                'updated' => 1,
            ]);
        }
        return $ids;
    }

    /** @return array<string,mixed> */
    public static function main(App $app): array
    {
        return $app->catalog()->mainChannel();
    }

    /** @return list<array<string,mixed>> committed items in air order */
    public static function committed(App $app): array
    {
        return array_map([Arche\Program\Timeline::class, 'decode'], $app->store()->all(
            "SELECT * FROM timeline_items WHERE state = 'committed' ORDER BY start_ms",
        ));
    }

    public static function cleanup(): void
    {
        foreach (self::$dirs as $dir) self::rm($dir);
        self::$dirs = [];
    }

    private static function rm(string $dir): void
    {
        if (!is_dir($dir)) return;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        @rmdir($dir);
    }
}

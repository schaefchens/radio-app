<?php
declare(strict_types=1);

namespace Arche\Tick;

use Arche\App;
use Arche\Support\Files;

/**
 * One unit of background work, started by the konsoleH cron once a minute
 * (cron.php) or opportunistically after an API request when the cron is late.
 *
 * Two phases under two locks, in this order:
 *   publish — draft, commit and write the program files for every channel,
 *             plus local housekeeping. No network calls, ever: this is the part
 *             that keeps the radio playing.
 *   jobs    — AI scripts, voice, moderation, the realtime reaper, while the
 *             Budget lasts. Slow or failing calls here cannot delay publishing.
 *
 * While /_arche/var/maintenance exists (a deploy is uploading files) nothing runs.
 */
final class Tick
{
    /** @var array<string,int> local tasks and their interval in seconds */
    private const LOCAL_TASKS = [
        'days' => 300,
        'evergreen' => 3600,
        'retention' => 600,
        'decay' => 3600,
        'aired' => 60,
        'purge' => 86400,
        'backup' => 86400,
    ];

    public function __construct(private App $app) {}

    /**
     * scripts/deploy.sh writes the flag (a Unix timestamp) before it starts
     * uploading and removes it at the end. A flag older than 15 minutes is a
     * deploy that died without cleaning up — the radio must not stay silent
     * because of it.
     */
    private function inMaintenance(): bool
    {
        $flag = $this->app->config->dataDir . '/maintenance';
        if (!is_file($flag)) return false;
        $since = (int) trim((string) @file_get_contents($flag)) ?: (int) @filemtime($flag);
        return $this->app->clock->now() - $since < 900;
    }

    /** @return array<string,mixed> */
    public function run(string $source = 'cron'): array
    {
        if ($this->inMaintenance()) return ['maintenance' => true];
        $this->app->store()->set('last_tick', ['at' => $this->app->clock->now(), 'source' => $source]);
        $lock = $this->app->lock();
        return [
            'publish' => $lock->run('publish', fn() => $this->publish()) ?? 'busy',
            'jobs' => $lock->run('jobs', fn() => $this->work()) ?? 'busy',
        ];
    }

    /** Called after an API response has been sent: tick if the cron seems late. */
    public function runIfLate(int $seconds = 90): void
    {
        $last = $this->app->store()->get('last_tick');
        if (is_array($last) && $this->app->clock->now() - (int) $last['at'] < $seconds) return;
        $this->run('api');
    }

    /** @return array<string,mixed> */
    private function publish(): array
    {
        $out = ['channels' => []];
        // Songs added, pulled or enabled again: the fallback loop follows in
        // this tick, not within the hour.
        $library = $this->app->library()->fingerprint();
        if ($library !== $this->app->store()->get('library_fingerprint')) {
            $this->app->store()->set('library_fingerprint', $library);
            $this->app->store()->set('task:evergreen', 0);
        }
        foreach ($this->app->catalog()->channels() as $channel) {
            $slug = (string) $channel['slug'];
            try {
                $out['channels'][$slug] = [
                    'drafted' => $this->app->drafter()->draft($channel),
                    'committed' => $this->app->committer()->commit($channel),
                    'files' => $this->app->publisher()->publishSlots($channel),
                ];
                $this->app->publisher()->publishLive($channel);
            } catch (\Throwable $e) {
                // One broken channel must not stop the others from publishing.
                $out['channels'][$slug] = ['error' => $e->getMessage()];
                $this->app->store()->audit('tick', 'Channel failed: ' . $slug, $e::class . ': ' . $e->getMessage());
            }
        }
        foreach (self::LOCAL_TASKS as $task => $interval) {
            if ($this->due($task, $interval)) $out['tasks'][$task] = $this->runLocal($task);
        }
        // After the tasks: channels.json points at the current fallback loop.
        $this->app->publisher()->publishChannels();
        return $out;
    }

    /** @return array<string,mixed> */
    private function work(): array
    {
        $out = [];
        if ($this->due('realtime', 60)) {
            try {
                $out['realtime'] = $this->app->nodes()->maintain();
            } catch (\Throwable $e) {
                $out['realtime'] = 'error';
                $this->app->store()->audit('tick', 'Realtime maintenance failed', $e->getMessage());
            }
        }
        if ($this->due('highlights', 120)) $this->app->moderator()->queueHighlights();
        if ($this->due('cdn', 60)) {
            try {
                $out['cdn'] = $this->app->cdn()->maintain();
            } catch (\Throwable $e) {
                $out['cdn'] = 'error';
                $this->app->store()->audit('tick', 'CDN maintenance failed', $e->getMessage());
            }
        }
        $out['jobs'] = $this->app->runner()->runUntilBudget();
        return $out;
    }

    private function due(string $task, int $interval): bool
    {
        $key = 'task:' . $task;
        $last = (int) ($this->app->store()->get($key) ?? 0);
        $now = $this->app->clock->now();
        if ($now - $last < $interval) return false;
        $this->app->store()->set($key, $now);
        return true;
    }

    private function runLocal(string $task): mixed
    {
        try {
            return match ($task) {
                'days' => array_sum(array_map(fn($c) => $this->app->publisher()->publishDays($c), $this->app->catalog()->channels())),
                'evergreen' => array_map(fn($c) => $this->app->publisher()->publishEvergreen($c), $this->app->catalog()->channels()),
                'retention' => $this->retention(),
                'decay' => $this->app->trends()->decay(),
                'aired' => ['aired' => $this->app->submissions()->markAired(), 'expired' => $this->app->submissions()->expireUnreachable()],
                'purge' => $this->purge(),
                'backup' => $this->backup(),
                default => null,
            };
        } catch (\Throwable $e) {
            $this->app->store()->audit('tick', 'Task failed: ' . $task, $e->getMessage());
            return 'error';
        }
    }

    /** Old minute files and host audio. Bounded per run; the rest goes next time. */
    private function retention(): int
    {
        $c = $this->app->config;
        $now = $this->app->clock->now();
        $removed = 0;
        foreach ($this->app->catalog()->channels(false) as $ch) {
            $removed += Files::prune($this->app->publicPath("program/{$ch['slug']}/slots"), $now - $c->int('RETAIN_SLOT_HOURS', 48) * 3600, 500 - $removed);
            $removed += Files::prune($this->app->publicPath("program/{$ch['slug']}/days"), $now - $c->int('RETAIN_DAY_FILES_DAYS', 60) * 86400, 500 - $removed);
        }
        // Host clips can name a listener: purged at the edge too, not just here.
        $removed += Files::prune($this->app->publicPath('media/host'), $now - $c->int('RETAIN_HOST_AUDIO_HOURS', 48) * 3600, max(0, 500 - $removed),
            fn(string $path) => $this->app->cdn()->forget('/media/host/' . basename($path)));
        return $removed;
    }

    /** @return array<string,int> */
    private function purge(): array
    {
        $now = $this->app->clock->now();
        $store = $this->app->store();
        $c = $this->app->config;
        // The retention periods the privacy policy states (app/src/content/legal.ts).
        return [
            // First, so the identities they belonged to can go next.
            'submissions' => $this->app->submissions()->purgeBefore($now - $c->int('RETAIN_SUBMISSIONS_DAYS', 90) * 86400),
            'identities' => $this->app->identities()->purgeInactive(),
            'timeline' => $this->app->timeline()->purgeBefore(($now - $this->app->config->int('RETAIN_TIMELINE_DAYS', 30) * 86400) * 1000),
            'jobs' => $this->app->jobs()->purgeBefore($now - 7 * 86400),
            'attempts' => $store->query('DELETE FROM attempts WHERE time < ?', [$now - 2 * 86400])->rowCount(),
            'presence' => $store->query('DELETE FROM presence WHERE seen < ?', [$now - 86400])->rowCount(),
            'audit' => $store->query('DELETE FROM audit WHERE time < ?', [$now - 90 * 86400])->rowCount(),
            // Chat: community voices are shown for two hours, reports handled within days.
            'highlights' => $store->query('DELETE FROM highlights WHERE created < ?', [$now - 7 * 86400])->rowCount(),
            'reports' => $store->query('DELETE FROM chat_reports WHERE created < ?', [$now - 30 * 86400])->rowCount(),
            'removed' => $store->query('DELETE FROM removed_messages WHERE time < ?', [$now - 7 * 86400])->rowCount(),
        ];
    }

    /** A consistent copy with VACUUM INTO, one per day, seven kept. */
    private function backup(): string
    {
        $dir = $this->app->config->dataDir . '/backups';
        Files::ensureDir($dir, 0700);
        $file = $dir . '/arche-' . gmdate('Ymd', $this->app->clock->now()) . '.sqlite';
        if (!is_file($file)) $this->app->store()->db->exec('VACUUM INTO ' . $this->app->store()->db->quote($file));
        $all = glob($dir . '/arche-*.sqlite') ?: [];
        rsort($all);
        foreach (array_slice($all, 7) as $old) @unlink($old);
        return basename($file);
    }
}

<?php
declare(strict_types=1);

namespace Arche\Cdn;

use Arche\App;
use Arche\Program\Timing;
use Arche\Support\BudgetExceeded;

/**
 * BunnyCDN in front of /program and /media (a pull zone with the webhosting
 * as its origin, set up by scripts/cdn/setup-bunny.sh). Nothing is ever
 * uploaded: the edge fetches each file once and keeps it as long as its
 * Cache-Control says. What is left for PHP, in the jobs phase:
 *
 *   - purge: a deleted media file (a recording after its retention, a host
 *     clip naming a listener) must not live on in the edge cache for a year
 *   - listeners: every client fetches each minute file once, so the requests
 *     for one minute's file are that minute's audience — counted from the
 *     zone's access log (IP addresses are not logged), with no request to us
 */
final class Bunny
{
    private const LOGS = 'https://logging.bunnycdn.com/v2/pullzones/%d/logs';
    private const PURGE = 'https://api.bunny.net/purge';
    /** Log lines per page, pages per count: beyond that the count is a floor. */
    private const PAGE = 10000;
    private const MAX_PAGES = 5;
    private const PURGES_PER_RUN = 100;
    private const QUEUE_MAX = 5000;
    /** The minute counted lags this far behind: logs arrive within seconds, a late fetch within one minute. */
    private const LAG_MINUTES = 2;

    public function __construct(private App $app) {}

    public function configured(): bool
    {
        $c = $this->app->config;
        return $c->cdnBase() !== '' && $c->has('BUNNY_API_KEY') && $c->int('BUNNY_PULL_ZONE_ID') > 0;
    }

    /** A published media file was deleted: its copy at the edge goes on the next run. No network here. */
    public function forget(string $urlPath): void
    {
        if (!$this->configured() || !str_starts_with($urlPath, '/media/')) return;
        $store = $this->app->store();
        $queue = array_values(array_filter((array) ($store->get('cdn_purge') ?? []), 'is_string'));
        if (in_array($urlPath, $queue, true) || count($queue) >= self::QUEUE_MAX) return;
        $queue[] = $urlPath;
        $store->set('cdn_purge', $queue);
    }

    /** @return array<string,mixed> jobs phase, once a minute */
    public function maintain(): array
    {
        if (!$this->configured()) return ['skipped' => 'not configured'];
        return ['purged' => $this->purgeQueued(), 'listeners' => $this->countListeners()];
    }

    /** The audience of a channel by the CDN log, if fresh (null = unknown). */
    public function listeners(string $channel): ?int
    {
        $row = $this->app->store()->get('cdn_listeners:' . $channel);
        if (!is_array($row)) return null;
        $fresh = (int) ($row['t'] ?? 0) >= $this->app->clock->nowMs() - (self::LAG_MINUTES + 3) * Timing::MINUTE;
        return $fresh ? (int) ($row['n'] ?? 0) : null;
    }

    private function purgeQueued(): int
    {
        $store = $this->app->store();
        $queue = array_values(array_filter((array) ($store->get('cdn_purge') ?? []), 'is_string'));
        $done = 0;
        foreach (array_slice($queue, 0, self::PURGES_PER_RUN) as $path) {
            try {
                $r = $this->app->http()->request('POST', self::PURGE . '?' . http_build_query([
                    'url' => $this->app->config->cdnBase() . $path,
                    'async' => 'true',
                ]), $this->auth(), '', 10);
            } catch (BudgetExceeded $e) {
                break;
            } catch (\Throwable $e) {
                $this->app->store()->audit('cdn', 'Purge failed', substr($e->getMessage(), 0, 200));
                break;
            }
            if ($r->status < 200 || $r->status >= 300) {
                $this->app->store()->audit('cdn', 'Purge failed', "HTTP {$r->status} for $path");
                break;
            }
            $done++;
        }
        if ($done > 0) $store->set('cdn_purge', array_slice($queue, $done));
        return $done;
    }

    /** @return array<string,int|null> */
    private function countListeners(): array
    {
        $minute = Timing::floorMinute($this->app->clock->nowMs()) - self::LAG_MINUTES * Timing::MINUTE;
        $out = [];
        foreach ($this->app->catalog()->channels() as $ch) {
            $slug = (string) $ch['slug'];
            $n = $this->countRequests('/' . Timing::slotPath($slug, $minute), $minute, $minute + Timing::MINUTE);
            if ($n !== null) $this->app->store()->set('cdn_listeners:' . $slug, ['t' => $minute, 'n' => $n]);
            $out[$slug] = $n;
        }
        return $out;
    }

    /** Successful requests for exactly $path in [$fromMs, $toMs). */
    private function countRequests(string $path, int $fromMs, int $toMs): ?int
    {
        $count = 0;
        $zone = $this->app->config->int('BUNNY_PULL_ZONE_ID');
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $url = sprintf(self::LOGS, $zone) . '?' . http_build_query([
                'from' => gmdate('Y-m-d\TH:i:s\Z', intdiv($fromMs, 1000)),
                'to' => gmdate('Y-m-d\TH:i:s\Z', intdiv($toMs, 1000)),
                'urlContains' => $path,
                'status' => '2xx',
                'limit' => self::PAGE,
                'offset' => $page * self::PAGE,
            ]);
            try {
                $r = $this->app->http()->get($url, $this->auth(), 10);
            } catch (BudgetExceeded $e) {
                throw $e;
            } catch (\Throwable $e) {
                $this->app->store()->audit('cdn', 'Log read failed', substr($e->getMessage(), 0, 200));
                return null;
            }
            $data = $r->json();
            if ($r->status !== 200 || !is_array($data)) {
                $this->app->store()->audit('cdn', 'Log read failed', 'HTTP ' . $r->status);
                return null;
            }
            // urlContains is a substring match; only this file counts.
            foreach ((array) ($data['data'] ?? []) as $row) {
                if (is_array($row) && ($row['path'] ?? '') === $path) $count++;
            }
            if (!($data['pagination']['hasMore'] ?? false)) break;
        }
        return $count;
    }

    /** @return array<string,string> */
    private function auth(): array
    {
        return ['AccessKey' => $this->app->config->get('BUNNY_API_KEY'), 'Accept' => 'application/json'];
    }
}

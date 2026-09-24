<?php
declare(strict_types=1);

namespace Arche\Tick;

use Arche\App;
use Arche\Store;

/**
 * /cron.php. The konsoleH cron is a curl that must return within seconds, so
 * this answers first and works after: receipt is recorded, the connection is
 * closed (fastcgi_finish_request), then one Tick runs within TICK_BUDGET.
 *
 * The key normally comes as `Authorization: Bearer`. A panel that cannot send
 * headers may use `?key=` instead — it then shows up in access logs, which is
 * why the endpoint can do nothing but trigger a (rate-limited) tick.
 */
final class CronEndpoint
{
    /**
     * Whether this request may tick: at most once per 20 s, however often the
     * endpoint is called — a leaked key buys an attacker no more than that.
     * Throttled on the last tick, not the last request: counting every
     * request would let calls every few seconds (a second cron, a retrying
     * curl) hold the tick off for good.
     */
    public static function due(Store $store, int $now): bool
    {
        $store->set('cron_seen', $now);
        if ($now - (int) ($store->get('cron_tick') ?? 0) < 20) return false;
        $store->set('cron_tick', $now);
        return true;
    }

    public static function run(App $app): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        $expected = $app->config->get('CRON_KEY');
        $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
        $given = str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : (string) ($_GET['key'] ?? '');
        if (strlen($expected) < 32 || !hash_equals($expected, $given)) {
            http_response_code(401);
            echo '{"error":"unauthorized"}';
            return;
        }
        $store = $app->store();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            echo json_encode([
                'lastTick' => $store->get('last_tick'),
                'lastCron' => $store->get('cron_seen'),
                'jobs' => $app->jobs()->counts(),
            ], JSON_UNESCAPED_SLASHES);
            return;
        }
        $run = self::due($store, $app->clock->now());
        $body = json_encode(['accepted' => true, 'tick' => $run, 'time' => gmdate(DATE_ATOM, $app->clock->now())]);
        ignore_user_abort(true);
        header('Content-Length: ' . strlen((string) $body));
        header('Connection: close');
        while (ob_get_level() > 0) ob_end_clean();
        echo $body;
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        else flush();
        if (!$run) return;
        try {
            $app->tick()->run('cron');
        } catch (\Throwable $e) {
            $store->audit('cron', 'Tick failed', $e::class . ': ' . $e->getMessage());
        }
    }
}

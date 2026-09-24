<?php
// How long may a request keep working after its response has gone out?
//
//   background.php?key=…          start: answers at once, then appends a line
//                                 every 5 s for up to 600 s
//   background.php?key=…&read=1   the log, and how long the last run lasted
//
// The tick answers the cron immediately (fastcgi_finish_request) and does its
// work afterwards; the run length measured here is its time budget. Limits
// are left exactly as the host sets them — the point is to meet them.
declare(strict_types=1);

define('PROBE', true);
require __DIR__ . '/_common.php';
probe_require_key();

$log = probe_var_dir() . '/background.log';

if (!empty($_GET['read'])) {
    $lines = is_file($log) ? file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    $entries = array_values(array_filter(array_map(static function ($l) {
        return json_decode($l, true);
    }, $lines)));
    $start = null;
    $last = null;
    $end = null;
    foreach ($entries as $e) {
        if (($e['event'] ?? '') === 'start') {
            $start = $e;
        }
        if (($e['event'] ?? '') === 'tick') {
            $last = $e;
        }
        if (in_array($e['event'] ?? '', ['done', 'shutdown'], true)) {
            $end = $e;
        }
    }
    $lastAt = $last['t'] ?? ($start['t'] ?? null);
    // The shutdown line is written at the moment PHP stops the script, so it
    // is more precise than the last 5-second tick; a kill from outside leaves
    // only the ticks.
    $endAt = $end['t'] ?? $lastAt;
    probe_json([
        'started' => $start['t'] ?? null,
        'ran_seconds' => ($start && $endAt) ? round($endAt - $start['t'], 1) : null,
        'last_line_age_seconds' => $lastAt ? round(microtime(true) - $lastAt, 1) : null,
        'finished' => $end,
        'verdict' => $end === null && $lastAt !== null && microtime(true) - $lastAt > 15
            ? 'killed from outside (FPM/Apache) — the wall-clock limit is about ran_seconds'
            : ($end === null
                ? 'still running (or never started)'
                : (($end['event'] ?? '') === 'done'
                    ? 'completed all 600 s — no practical limit'
                    : 'stopped by PHP: ' . ($end['error'] ?? 'shutdown without error'))),
        'entries' => $entries,
    ]);
}

// Only one run at a time; a second start would muddle the log.
$lock = fopen(probe_var_dir() . '/background.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    probe_json(['error' => 'a run is already in progress — ?read=1'], 409);
}

$write = static function (array $entry) use ($log): void {
    $entry['t'] = microtime(true);
    file_put_contents($log, json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);
};
file_put_contents($log, '');
$write([
    'event' => 'start',
    'sapi' => PHP_SAPI,
    'max_execution_time' => ini_get('max_execution_time'),
    'fastcgi_finish_request' => function_exists('fastcgi_finish_request'),
]);

register_shutdown_function(static function () use ($write): void {
    $err = error_get_last();
    $write(['event' => 'shutdown', 'error' => $err ? ($err['message'] . ' @' . ($err['line'] ?? '?')) : null]);
});

// Answer now, keep working afterwards — the same dance as the real cron.php.
ignore_user_abort(true);
$body = json_encode(['started' => true, 'read' => 'background.php?read=1']);
header('Content-Type: application/json');
header('Cache-Control: no-store');
header('Content-Length: ' . strlen($body));
header('Connection: close');
while (ob_get_level() > 0) {
    ob_end_clean();
}
echo $body;
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    flush();
}

$t0 = microtime(true);
for ($i = 1; $i <= 120; $i++) {
    sleep(5);
    $write(['event' => 'tick', 'i' => $i, 'elapsed' => round(microtime(true) - $t0, 1)]);
}
$write(['event' => 'done', 'elapsed' => round(microtime(true) - $t0, 1)]);

<?php
// Logs every call from the konsoleH cron, to learn three things over 24 h:
// the real interval, whether the panel sends headers (Bearer) or only a URL
// (?key=), and what it looks like (user agent, method).
//
//   cron-probe.php?key=…          (or Authorization: Bearer …) log one arrival
//   cron-probe.php?key=…&read=1   the log with interval statistics
//
// Arrivals without a valid key are logged too (capped), because "the panel
// silently drops the header" is exactly the failure to detect.
declare(strict_types=1);

define('PROBE', true);
require __DIR__ . '/_common.php';

$log = probe_var_dir() . '/cron.log';

if (!empty($_GET['read'])) {
    probe_require_key();
    $entries = [];
    foreach (is_file($log) ? file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $l) {
        $e = json_decode($l, true);
        if (is_array($e)) {
            $entries[] = $e;
        }
    }
    $stats = [];
    foreach (['valid' => true, 'invalid' => false] as $label => $want) {
        $times = [];
        $via = [];
        foreach ($entries as $e) {
            if ((bool) ($e['valid'] ?? false) === $want) {
                $times[] = (float) $e['t'];
                $via[$e['via'] ?? '?'] = ($via[$e['via'] ?? '?'] ?? 0) + 1;
            }
        }
        sort($times);
        $gaps = [];
        for ($i = 1, $n = count($times); $i < $n; $i++) {
            $gaps[] = $times[$i] - $times[$i - 1];
        }
        sort($gaps);
        $count = count($gaps);
        $stats[$label] = [
            'arrivals' => count($times),
            'via' => $via,
            'first' => $times ? gmdate('c', (int) $times[0]) : null,
            'last' => $times ? gmdate('c', (int) end($times)) : null,
            'last_age_seconds' => $times ? round(microtime(true) - end($times)) : null,
            'interval_seconds' => $count ? [
                'min' => round($gaps[0], 1),
                'median' => round($gaps[intdiv($count, 2)], 1),
                'max' => round($gaps[$count - 1], 1),
                'over_90s' => count(array_filter($gaps, static function ($g) {
                    return $g > 90;
                })),
            ] : null,
        ];
    }
    probe_json(['stats' => $stats, 'recent' => array_slice($entries, -30)]);
}

[, $via] = probe_key_given();
$valid = probe_key_valid();
$entry = [
    't' => microtime(true),
    'valid' => $valid,
    'via' => $via,
    'method' => $_SERVER['REQUEST_METHOD'] ?? '?',
    'sapi' => PHP_SAPI,
    'ua' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120),
    'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    'auth_header_seen' => isset($_SERVER['HTTP_AUTHORIZATION']) || isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']),
];
if ($valid || !is_file($log) || filesize($log) < 200000) {
    file_put_contents($log, json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);
}
probe_json(['ok' => $valid], $valid ? 200 : 401);

<?php
// Shared helpers of the host probe. An include, not an endpoint: fetched
// directly it says nothing.
//
// Deliberately PHP 7.4-compatible (no `never`, `match`, str_contains, named
// arguments): finding out which PHP the domain really runs is one of the
// questions, and a parse error would hide the answer.
declare(strict_types=1);

if (!defined('PROBE')) {
    http_response_code(404);
    exit;
}

// A notice printed into the body would make every JSON report unreadable
// (PHP 8.5 deprecates things the 7.4-compatible code still has to call).
// Errors still reach the host's error log.
ini_set('display_errors', '0');

const PROBE_PRIVATE = __DIR__ . '/private';

function probe_config(): array
{
    static $config = null;
    if ($config === null) {
        $file = PROBE_PRIVATE . '/config.php';
        $config = is_file($file) ? (array) require $file : [];
    }
    return $config;
}

/** The key as it arrived, and how: header (Bearer / X-Probe-Key), query, or not at all. */
function probe_key_given(): array
{
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (is_string($auth) && stripos($auth, 'Bearer ') === 0) {
        return [trim(substr($auth, 7)), 'header'];
    }
    if (!empty($_SERVER['HTTP_X_PROBE_KEY'])) {
        return [(string) $_SERVER['HTTP_X_PROBE_KEY'], 'x-probe-key'];
    }
    if (isset($_GET['key']) && is_string($_GET['key'])) {
        return [$_GET['key'], 'query'];
    }
    return ['', 'none'];
}

function probe_key_valid(): bool
{
    $expected = (string) (probe_config()['key'] ?? '');
    [$given] = probe_key_given();
    return strlen($expected) >= 16 && hash_equals($expected, (string) $given);
}

function probe_require_key(): void
{
    if (!probe_key_valid()) {
        probe_json(['error' => 'unauthorized'], 401);
    }
}

function probe_var_dir(): string
{
    $dir = PROBE_PRIVATE . '/var';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

function probe_json(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** Minimal curl wrapper: status, timings, body (capped), transport error. */
function probe_http(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 15): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'curl extension missing'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $start = microtime(true);
    $raw = curl_exec($ch);
    $ms = (int) round((microtime(true) - $start) * 1000);
    $info = curl_getinfo($ch);
    $error = $raw === false ? curl_error($ch) : null;
    if (PHP_VERSION_ID < 80000) {
        curl_close($ch); // a no-op since PHP 8.0, deprecated in 8.5
    }
    return [
        'ok' => $raw !== false,
        'status' => (int) ($info['http_code'] ?? 0),
        'ms' => $ms,
        // The *_us variants (microseconds) are the precise ones where present.
        'dns_ms' => probe_ms($info, 'namelookup_time'),
        'connect_ms' => probe_ms($info, 'connect_time'),
        'tls_ms' => probe_ms($info, 'appconnect_time'),
        'content_type' => (string) ($info['content_type'] ?? ''),
        'bytes' => is_string($raw) ? strlen($raw) : 0,
        'error' => $error,
        'body' => is_string($raw) ? $raw : '',
    ];
}

function probe_ms(array $info, string $key): int
{
    if (isset($info[$key . '_us']) && $info[$key . '_us'] > 0) {
        return (int) round($info[$key . '_us'] / 1000);
    }
    return (int) round(((float) ($info[$key] ?? 0)) * 1000);
}

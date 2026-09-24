<?php
// For a konsoleH cron that runs a PHP SCRIPT instead of calling a URL:
//   <php binary> /path/to/web-root/_probe/cron-cli.php
// Logs into the same cron.log (via "cli"), so cron-probe.php?read=1 shows
// whether a CLI tick is possible — and with which PHP. Over HTTP it is a 404.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('PROBE', true);
require __DIR__ . '/_common.php';

$entry = [
    't' => microtime(true),
    'valid' => true,
    'via' => 'cli',
    'method' => 'CLI',
    'sapi' => PHP_SAPI,
    'php' => PHP_VERSION,
    'binary' => PHP_BINARY,
    'argv' => array_slice($_SERVER['argv'] ?? [], 1),
    'cwd' => getcwd(),
    'user' => get_current_user(),
    'max_execution_time' => ini_get('max_execution_time'),
];
file_put_contents(probe_var_dir() . '/cron.log', json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);
echo "logged\n";

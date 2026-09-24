<?php
declare(strict_types=1);

// A tick from the command line: local Docker, tests, or a host cron that can
// run PHP directly (no FPM time limit applies then — see scripts/probe).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$app = require dirname(__DIR__) . '/app/bootstrap.php';
echo json_encode($app->tick()->run('cli'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";

<?php
// Does an Authorization header reach PHP WITHOUT the SetEnvIf line? This file
// sits outside htaccess-test/, so only /_probe/.htaccess applies (no SetEnvIf).
// Compare with htaccess-test/echo.php, which has it. Never echoes the value.
declare(strict_types=1);

define('PROBE', true);
require __DIR__ . '/_common.php';
probe_json([
    'where' => 'no SetEnvIf',
    'http_authorization' => isset($_SERVER['HTTP_AUTHORIZATION']),
    'redirect_http_authorization' => isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']),
    'x_probe_key' => isset($_SERVER['HTTP_X_PROBE_KEY']),
]);

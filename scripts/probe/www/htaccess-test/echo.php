<?php
// With the SetEnvIf line (inherited from htaccess-test/.htaccess): does a
// Bearer header reach PHP? Never echoes the value.
header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode([
    'where' => 'with SetEnvIf',
    'http_authorization' => isset($_SERVER['HTTP_AUTHORIZATION']),
    'redirect_http_authorization' => isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']),
    'sapi' => PHP_SAPI,
]);

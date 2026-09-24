<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode([
    'where' => 'CGIPassAuth',
    'http_authorization' => isset($_SERVER['HTTP_AUTHORIZATION']),
    'redirect_http_authorization' => isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']),
]);

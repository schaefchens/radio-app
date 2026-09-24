<?php
// Which upload/time limits apply in this directory (after .user.ini / .htaccess)?
header("Content-Type: application/json");
header("Cache-Control: no-store");
$out = [];
foreach (["upload_max_filesize", "post_max_size", "max_execution_time", "memory_limit", "user_ini.filename", "user_ini.cache_ttl"] as $k) {
    $out[$k] = ini_get($k);
}
$out["dir"] = basename(__DIR__);
echo json_encode($out);

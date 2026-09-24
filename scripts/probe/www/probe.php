<?php
// Host probe: what this webhosting really offers ARCHE, as one JSON report.
//
//   probe.php?key=…          environment, SQLite/WAL, flock, outbound HTTPS
//   probe.php?key=…&ai=1     + one real Claude call and one OpenAI TTS call
//                              (only when private/config.php carries the keys)
//   probe.php?key=…&cli=1    + try to run PHP CLI candidates (`php -v`) when
//                              exec() is allowed
//
// Every decision it feeds is listed in scripts/probe/README.md.
declare(strict_types=1);

define('PROBE', true);
require __DIR__ . '/_common.php';
probe_require_key();

$report = [
    'time_ms' => (int) round(microtime(true) * 1000),
    'php' => [
        'version' => PHP_VERSION,
        'sapi' => PHP_SAPI,
        'binary' => PHP_BINARY,
        'os' => PHP_OS_FAMILY,
        'uname' => php_uname('s') . ' ' . php_uname('r') . ' ' . php_uname('m'),
        'user' => get_current_user(),
        'uid' => function_exists('getmyuid') ? getmyuid() : null,
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? null,
        'https' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ],
];

// --- extensions and functions ARCHE depends on -----------------------------------

$wanted = ['pdo_sqlite', 'sqlite3', 'curl', 'mbstring', 'intl', 'gd', 'sodium', 'openssl', 'json', 'fileinfo', 'zip', 'exif', 'Zend OPcache'];
$ext = [];
foreach ($wanted as $name) {
    $ext[$name] = extension_loaded($name);
}
$loaded = get_loaded_extensions();
sort($loaded);
$report['extensions'] = ['wanted' => $ext, 'loaded' => $loaded];

$disabled = array_values(array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions')))));
$report['functions'] = [
    'fastcgi_finish_request' => function_exists('fastcgi_finish_request'),
    'flock' => function_exists('flock'),
    'set_time_limit' => function_exists('set_time_limit') && !in_array('set_time_limit', $disabled, true),
    'exec' => function_exists('exec') && !in_array('exec', $disabled, true),
    'proc_open' => function_exists('proc_open') && !in_array('proc_open', $disabled, true),
    'sodium_crypto_sign_detached' => function_exists('sodium_crypto_sign_detached'),
    'password_argon2id' => defined('PASSWORD_ARGON2ID'),
    'imagewebp' => function_exists('imagewebp'),
    'imagecreatefromjpeg' => function_exists('imagecreatefromjpeg'),
    'disabled' => $disabled,
];

// Argon2id cost on this CPU — the identity login hashes once per claim/login.
if (defined('PASSWORD_ARGON2ID')) {
    $t = microtime(true);
    $hash = password_hash('probe-' . bin2hex(random_bytes(8)), PASSWORD_ARGON2ID);
    $report['functions']['argon2id_ms'] = (int) round((microtime(true) - $t) * 1000);
    $report['functions']['argon2id_params'] = is_string($hash) ? substr($hash, 0, strpos($hash, '$', 10) ?: 30) : null;
}

// --- ini values that bound a tick, an upload, a request ---------------------------

$keys = [
    'max_execution_time', 'max_input_time', 'memory_limit', 'upload_max_filesize', 'post_max_size',
    'max_file_uploads', 'file_uploads', 'user_ini.filename', 'user_ini.cache_ttl', 'open_basedir',
    'allow_url_fopen', 'default_socket_timeout', 'output_buffering', 'zlib.output_compression',
    'date.timezone', 'opcache.enable', 'opcache.validate_timestamps', 'opcache.revalidate_freq',
    'display_errors', 'log_errors', 'error_log', 'session.save_path',
];
$ini = [];
foreach ($keys as $k) {
    $v = ini_get($k);
    $ini[$k] = $v === false ? null : $v;
}
$report['ini'] = $ini;
$report['notes'] = [
    'PHP-FPM request_terminate_timeout is not visible from PHP; background.php measures how long a request may keep running after its response.',
    'On Linux max_execution_time counts CPU time, not waiting on the network or sleep — the wall-clock limit is the FPM/Apache one.',
];

// --- where things are -------------------------------------------------------------

$report['paths'] = [
    'script_dir' => __DIR__,
    'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? null,
    'tmp' => sys_get_temp_dir(),
    'private_writable' => is_writable(PROBE_PRIVATE),
    'disk_free_mb' => ($free = @disk_free_space(__DIR__)) !== false ? (int) round($free / 1048576) : null,
];

// A PHP CLI the konsoleH cron could run directly (no FPM time limit then).
$candidates = array_unique(array_filter([
    '/usr/bin/php', '/usr/local/bin/php', '/usr/local/php85/bin/php', '/usr/local/php8.5/bin/php',
    '/opt/php85/bin/php', dirname(PHP_BINARY) . '/php', dirname(dirname(PHP_BINARY)) . '/bin/php',
]));
$cli = [];
foreach ($candidates as $path) {
    $cli[$path] = @is_file($path) ? (@is_executable($path) ? 'executable' : 'present') : 'absent';
}
if (!empty($_GET['cli']) && $report['functions']['exec']) {
    foreach ($candidates as $path) {
        if ($cli[$path] !== 'executable') {
            continue;
        }
        $out = [];
        $code = 0;
        @exec(escapeshellarg($path) . ' -v 2>&1', $out, $code);
        $cli[$path] = ['exit' => $code, 'first_line' => $out[0] ?? ''];
    }
}
$report['cli_candidates'] = $cli;

// --- SQLite: WAL support and lock behaviour ----------------------------------------

function probe_sqlite(): array
{
    if (!extension_loaded('pdo_sqlite')) {
        return ['ok' => false, 'error' => 'pdo_sqlite missing'];
    }
    $dir = probe_var_dir();
    $file = $dir . '/probe.sqlite';
    foreach (['', '-wal', '-shm', '-journal'] as $suffix) {
        @unlink($file . $suffix);
    }
    $open = static function () use ($file): PDO {
        $db = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('PRAGMA busy_timeout=300');
        return $db;
    };
    try {
        $a = $open();
        $mode = (string) $a->query('PRAGMA journal_mode=WAL')->fetchColumn();
        $a->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT NOT NULL)');
        $t = microtime(true);
        $a->beginTransaction();
        $ins = $a->prepare('INSERT INTO t(v) VALUES(?)');
        for ($i = 0; $i < 500; $i++) {
            $ins->execute([str_repeat('x', 64)]);
        }
        $a->commit();
        $batchMs = (int) round((microtime(true) - $t) * 1000);

        // Single small commits: what the API does per pulse / submission.
        $t = microtime(true);
        for ($i = 0; $i < 50; $i++) {
            $a->exec("INSERT INTO t(v) VALUES('single')");
        }
        $singleMs = round((microtime(true) - $t) * 1000 / 50, 2);

        // Two connections: can one read while the other holds a write lock,
        // and does a second writer get SQLITE_BUSY instead of corrupting?
        $b = $open();
        $a->exec('BEGIN IMMEDIATE');
        $a->exec("INSERT INTO t(v) VALUES('held')");
        $readWhileWriting = null;
        try {
            $readWhileWriting = (int) $b->query('SELECT COUNT(*) FROM t')->fetchColumn();
        } catch (PDOException $e) {
            $readWhileWriting = 'error: ' . $e->getMessage();
        }
        $secondWriter = 'succeeded (unexpected)';
        try {
            $b->exec("INSERT INTO t(v) VALUES('competing')");
        } catch (PDOException $e) {
            $secondWriter = stripos($e->getMessage(), 'locked') !== false || stripos($e->getMessage(), 'busy') !== false
                ? 'busy (expected)' : 'error: ' . $e->getMessage();
        }
        $a->exec('COMMIT');
        $b->exec("INSERT INTO t(v) VALUES('after')");
        $after = (int) $b->query('SELECT COUNT(*) FROM t')->fetchColumn();

        $c = $open();
        $persisted = (string) $c->query('PRAGMA journal_mode')->fetchColumn();
        $integrity = (string) $c->query('PRAGMA quick_check')->fetchColumn();
        return [
            'ok' => true,
            'sqlite_version' => (string) $a->query('SELECT sqlite_version()')->fetchColumn(),
            'journal_mode_requested_wal' => $mode,
            'journal_mode_on_reopen' => $persisted,
            'wal_files_seen' => ['wal' => is_file($file . '-wal'), 'shm' => is_file($file . '-shm')],
            'batch_500_inserts_ms' => $batchMs,
            'single_commit_ms' => $singleMs,
            'read_while_writing' => $readWhileWriting,
            'second_writer' => $secondWriter,
            'rows_after' => $after,
            'quick_check' => $integrity,
        ];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => get_class($e) . ': ' . $e->getMessage()];
    }
}
$report['sqlite'] = probe_sqlite();

// --- flock: the tick's non-blocking locks -------------------------------------------

$lockFile = probe_var_dir() . '/probe.lock';
$h1 = @fopen($lockFile, 'c');
$h2 = @fopen($lockFile, 'c');
$wouldBlock = 0;
$report['flock'] = [
    'first_exclusive_nb' => $h1 ? flock($h1, LOCK_EX | LOCK_NB) : 'fopen failed',
    'second_exclusive_nb' => $h2 ? flock($h2, LOCK_EX | LOCK_NB, $wouldBlock) : 'fopen failed',
    'second_would_block' => (bool) $wouldBlock,
];
if ($h1) {
    flock($h1, LOCK_UN);
    fclose($h1);
}
if ($h2) {
    fclose($h2);
}

// --- outbound HTTPS (no key needed: a 401 proves the path works) ---------------------

$out = [];
foreach ([
    'anthropic' => 'https://api.anthropic.com/v1/models',
    'openai' => 'https://api.openai.com/v1/models',
    'youtube' => 'https://www.googleapis.com/youtube/v3/videos?part=id&id=dQw4w9WgXcQ',
    'hetzner' => 'https://api.hetzner.cloud/v1/locations',
] as $name => $url) {
    $r = probe_http('GET', $url, [], null, 10);
    unset($r['body']);
    $out[$name] = $r;
}
$report['outbound'] = $out;

// --- optional real AI calls -------------------------------------------------------------

if (!empty($_GET['ai'])) {
    $cfg = probe_config();
    $ai = [];
    if (!empty($cfg['anthropic_key'])) {
        // Thinking off so the 64-token cap cannot be spent before the answer.
        $r = probe_http('POST', 'https://api.anthropic.com/v1/messages', [
            'x-api-key: ' . $cfg['anthropic_key'],
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ], json_encode([
            'model' => 'claude-opus-5',
            'max_tokens' => 64,
            'thinking' => ['type' => 'disabled'],
            'messages' => [['role' => 'user', 'content' => 'Reply with OK']],
        ]), 30);
        $data = json_decode($r['body'], true);
        $text = '';
        foreach ((array) ($data['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= (string) $block['text'];
            }
        }
        $ai['anthropic'] = [
            'status' => $r['status'],
            'ms' => $r['ms'],
            'model' => $data['model'] ?? null,
            'stop_reason' => $data['stop_reason'] ?? null,
            'text' => function_exists('mb_substr') ? mb_substr($text, 0, 40) : substr($text, 0, 40),
            'usage' => $data['usage'] ?? null,
            'error' => $data['error']['type'] ?? $r['error'],
        ];
    } else {
        $ai['anthropic'] = 'no key in private/config.php (probe.sh --upload --with-ai)';
    }
    if (!empty($cfg['openai_key'])) {
        $r = probe_http('POST', 'https://api.openai.com/v1/audio/speech', [
            'Authorization: Bearer ' . $cfg['openai_key'],
            'Content-Type: application/json',
        ], json_encode([
            'model' => 'gpt-4o-mini-tts',
            'voice' => 'coral',
            'input' => 'Hallo und willkommen bei ARCHE.',
            'response_format' => 'mp3',
        ]), 30);
        if ($r['status'] === 200) {
            @file_put_contents(probe_var_dir() . '/tts.mp3', $r['body']);
        }
        $err = $r['status'] === 200 ? null : (json_decode($r['body'], true)['error']['type'] ?? $r['error']);
        $ai['openai_tts'] = [
            'status' => $r['status'],
            'ms' => $r['ms'],
            'bytes' => $r['bytes'],
            'content_type' => $r['content_type'],
            'error' => $err,
        ];
    } else {
        $ai['openai_tts'] = 'no key in private/config.php (probe.sh --upload --with-ai)';
    }
    $report['ai'] = $ai;
}

$report['elapsed_ms'] = (int) round(microtime(true) * 1000) - $report['time_ms'];
probe_json($report);

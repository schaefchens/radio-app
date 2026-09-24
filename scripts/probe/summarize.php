<?php
// Turns one scripts/probe/results/<time>/ directory into the readable summary
// `probe.sh --read` prints, ending in the decisions the probe exists to make.
// Runs locally (not on the host), so modern PHP is fine here.
declare(strict_types=1);

$dir = $argv[1] ?? '';
if ($dir === '' || !is_dir($dir)) {
    fwrite(STDERR, "usage: php summarize.php <results dir>\n");
    exit(2);
}

$tty = function_exists('posix_isatty') && posix_isatty(STDOUT);
$c = static fn(string $code, string $s): string => $tty ? "\033[{$code}m{$s}\033[0m" : $s;
$head = static fn(string $s) => print("\n" . $c('34', '==> ') . $s . "\n");
$okLine = static fn(string $s) => print('  ' . $c('32', '✓') . ' ' . $s . "\n");
$badLine = static fn(string $s) => print('  ' . $c('31', '✗') . ' ' . $s . "\n");
$warnLine = static fn(string $s) => print('  ' . $c('33', '!') . ' ' . $s . "\n");
$line = static fn(string $s) => print('    ' . $s . "\n");

$load = static function (string $name) use ($dir): ?array {
    $file = "$dir/$name";
    if (!is_file($file)) return null;
    $data = json_decode((string) file_get_contents($file), true);
    if (is_array($data)) return $data;
    $status = @file_get_contents(preg_replace('/\.json$/', '.status', $file)) ?: '?';
    $raw = trim(substr((string) file_get_contents($file), 0, 200));
    return ['_raw' => "HTTP $status" . ($raw !== '' ? " — $raw" : ' — empty body (a PHP fatal error? see the host\'s PHP error log)')];
};
$yes = static fn($v): string => $v === true ? 'yes' : ($v === false ? 'no' : (string) json_encode($v));

$decisions = [];

// --- environment -----------------------------------------------------------------------

$probe = $load('probe.json');
$head('PHP on the host');
if ($probe === null || isset($probe['_raw']) || isset($probe['error'])) {
    $badLine('probe.php gave no report: ' . json_encode($probe['error'] ?? $probe['_raw'] ?? 'missing'));
} else {
    $php = $probe['php'] ?? [];
    $okLine(sprintf('PHP %s (%s) on %s, user %s', $php['version'] ?? '?', $php['sapi'] ?? '?', $php['uname'] ?? '?', $php['user'] ?? '?'));
    if (version_compare((string) ($php['version'] ?? '0'), '8.5.0', '<')) {
        $badLine('ARCHE targets PHP 8.5 — switch the domain to PHP 8.5 in konsoleH');
    }
    $missing = array_keys(array_filter($probe['extensions']['wanted'] ?? [], static fn($v) => $v !== true));
    $needed = ['pdo_sqlite', 'curl', 'mbstring', 'intl', 'gd', 'sodium', 'openssl', 'json'];
    $missingNeeded = array_values(array_intersect($missing, $needed));
    $missingNeeded ? $badLine('missing required extensions: ' . implode(', ', $missingNeeded)) : $okLine('required extensions present: ' . implode(', ', $needed));
    $optional = array_values(array_diff($missing, $needed));
    if ($optional) $warnLine('optional extensions missing: ' . implode(', ', $optional));

    $f = $probe['functions'] ?? [];
    ($f['fastcgi_finish_request'] ?? false) ? $okLine('fastcgi_finish_request available (answer the cron first, work after)') : $badLine('no fastcgi_finish_request — the tick cannot answer before it works');
    ($f['password_argon2id'] ?? false) ? $okLine(sprintf('Argon2id available, one hash ≈ %s ms', $f['argon2id_ms'] ?? '?')) : $warnLine('no Argon2id — passphrase logins fall back to bcrypt');
    $okLine('exec ' . $yes($f['exec'] ?? null) . ', proc_open ' . $yes($f['proc_open'] ?? null) . ', set_time_limit ' . $yes($f['set_time_limit'] ?? null) . ', imagewebp ' . $yes($f['imagewebp'] ?? null));
    if (!empty($f['disabled'])) $line('disabled functions: ' . implode(', ', array_slice($f['disabled'], 0, 25)));

    $ini = $probe['ini'] ?? [];
    $line(sprintf('max_execution_time %s · memory_limit %s · upload_max_filesize %s · post_max_size %s · user_ini %s (ttl %s s)',
        $ini['max_execution_time'] ?? '?', $ini['memory_limit'] ?? '?', $ini['upload_max_filesize'] ?? '?',
        $ini['post_max_size'] ?? '?', $ini['user_ini.filename'] ?? '?', $ini['user_ini.cache_ttl'] ?? '?'));
    $paths = $probe['paths'] ?? [];
    $line(sprintf('web root %s · probe dir %s · free disk %s MB', $paths['document_root'] ?? '?', $paths['script_dir'] ?? '?', $paths['disk_free_mb'] ?? '?'));

    $cli = array_filter($probe['cli_candidates'] ?? [], static fn($v) => $v !== 'absent');
    if ($cli) {
        foreach ($cli as $path => $v) $line("PHP CLI candidate $path: " . (is_array($v) ? ('exit ' . $v['exit'] . ' — ' . $v['first_line']) : $v));
    } else {
        $line('no PHP CLI binary visible from FPM (a PHP-script cron may still work — see the cron section)');
    }

    // SQLite
    $head('SQLite');
    $s = $probe['sqlite'] ?? [];
    if (!($s['ok'] ?? false)) {
        $badLine('SQLite test failed: ' . ($s['error'] ?? '?'));
        $decisions[] = 'SQLite: FAILED — the core cannot run here until this is fixed';
    } else {
        $wal = ($s['journal_mode_requested_wal'] ?? '') === 'wal' && ($s['journal_mode_on_reopen'] ?? '') === 'wal';
        $okLine(sprintf('SQLite %s · journal_mode after "WAL": %s, on reopen: %s · -wal/-shm seen: %s/%s',
            $s['sqlite_version'] ?? '?', $s['journal_mode_requested_wal'] ?? '?', $s['journal_mode_on_reopen'] ?? '?',
            $yes($s['wal_files_seen']['wal'] ?? null), $yes($s['wal_files_seen']['shm'] ?? null)));
        $line(sprintf('500 inserts in one transaction: %s ms · single commit: %s ms · read while another writes: %s · second writer: %s · quick_check: %s',
            $s['batch_500_inserts_ms'] ?? '?', $s['single_commit_ms'] ?? '?', is_int($s['read_while_writing'] ?? null) ? 'works' : ($s['read_while_writing'] ?? '?'),
            $s['second_writer'] ?? '?', $s['quick_check'] ?? '?'));
        $decisions[] = $wal
            ? 'SQLITE_WAL=1 — WAL works here (readers never wait for the tick)'
            : 'SQLITE_WAL=0 — WAL did not stick; use the rollback journal';
    }
    $fl = $probe['flock'] ?? [];
    ($fl['first_exclusive_nb'] ?? null) === true && ($fl['second_exclusive_nb'] ?? null) === false
        ? $okLine('flock works (a second non-blocking lock is refused) — overlapping ticks will skip')
        : $badLine('flock behaves unexpectedly: ' . json_encode($fl));

    // Outbound
    $head('Outbound HTTPS');
    foreach ($probe['outbound'] ?? [] as $name => $r) {
        $st = (int) ($r['status'] ?? 0);
        $msg = sprintf('%-9s → HTTP %s in %s ms (dns %s, connect %s, tls %s)', $name, $st ?: '—', $r['ms'] ?? '?', $r['dns_ms'] ?? '?', $r['connect_ms'] ?? '?', $r['tls_ms'] ?? '?');
        // Without keys a 401/403/400 is the expected, reachable answer.
        ($st >= 200 && $st < 500) ? $okLine($msg) : $badLine($msg . ' ' . ($r['error'] ?? ''));
    }

    if (isset($probe['ai'])) {
        $head('Real AI calls (?ai=1)');
        foreach ($probe['ai'] as $name => $r) {
            if (!is_array($r)) { $warnLine("$name: $r"); continue; }
            $msg = sprintf('%s → HTTP %s in %s ms', $name, $r['status'] ?? '?', $r['ms'] ?? '?');
            if (isset($r['stop_reason'])) $msg .= sprintf(' · %s · "%s"', $r['stop_reason'], $r['text'] ?? '');
            if (isset($r['bytes'])) $msg .= sprintf(' · %s bytes %s', $r['bytes'], $r['content_type'] ?? '');
            ((int) ($r['status'] ?? 0) === 200) ? $okLine($msg) : $badLine($msg . ' · ' . json_encode($r['error'] ?? null));
        }
    }
}

// --- background run: the tick's wall-clock budget --------------------------------------

$head('Background run after fastcgi_finish_request (the tick budget)');
$bg = $load('background.json');
if ($bg === null || isset($bg['error']) || isset($bg['_raw'])) {
    $warnLine('no background result: ' . json_encode($bg['error'] ?? $bg['_raw'] ?? 'missing'));
} else {
    $ran = $bg['ran_seconds'];
    $finished = $bg['finished']['event'] ?? null;
    $okLine(sprintf('ran %s s · %s', $ran ?? '?', $bg['verdict'] ?? '?'));
    if ($ran === null) {
        $decisions[] = 'TICK_BUDGET: unknown — start a run (probe.sh --background) and read again after 10 minutes';
    } elseif ($finished === 'done' || $ran >= 590) {
        $decisions[] = 'TICK_BUDGET=50 — no practical limit seen in 600 s; stay under the 60 s cron interval';
    } elseif ($finished === null && ($bg['last_line_age_seconds'] ?? 0) < 15) {
        $decisions[] = sprintf('TICK_BUDGET: still measuring (%s s so far) — read again later', $ran);
    } else {
        $budget = max(10, (int) floor(min(50, $ran - 8)));
        $decisions[] = sprintf('TICK_BUDGET=%d — requests are killed after about %s s', $budget, $ran);
    }
}

// --- cron: interval, key transport, CLI ---------------------------------------------------

$head('Cron arrivals (cron-probe.php / cron-cli.php)');
$cron = $load('cron.json');
if ($cron === null || isset($cron['error']) || isset($cron['_raw'])) {
    $warnLine('no cron log: ' . json_encode($cron['error'] ?? $cron['_raw'] ?? 'missing'));
} else {
    $v = $cron['stats']['valid'] ?? [];
    $inv = $cron['stats']['invalid'] ?? [];
    $via = $v['via'] ?? [];
    if (($v['arrivals'] ?? 0) === 0) {
        $warnLine('no authenticated arrival yet — is the konsoleH cron set up (probe.sh --upload printed the lines)?');
        $decisions[] = 'Cron: not measured yet';
    } else {
        $iv = $v['interval_seconds'] ?? null;
        $okLine(sprintf('%d arrivals %s → %s (last %s s ago) · via %s', $v['arrivals'], $v['first'] ?? '?', $v['last'] ?? '?',
            $v['last_age_seconds'] ?? '?', json_encode($via)));
        if ($iv) $line(sprintf('interval min %s s · median %s s · max %s s · gaps over 90 s: %d', $iv['min'], $iv['median'], $iv['max'], $iv['over_90s']));
        $span = strtotime((string) $v['last']) - strtotime((string) $v['first']);
        if ($span < 6 * 3600) $warnLine(sprintf('only %.1f h measured — the plan asks for 24 h', $span / 3600));
        if (!$iv || $span < 3600) {
            $decisions[] = sprintf('Cron: not enough data yet (%d min measured) — read again after a few hours', (int) round($span / 60));
        } elseif ($iv['median'] >= 45 && $iv['median'] <= 75) {
            $decisions[] = sprintf('Cron: every minute confirmed (median %s s, %d gaps over 90 s) — the 1-minute plan holds', $iv['median'], $iv['over_90s']);
        } elseif ($iv['median'] >= 3000) {
            $decisions[] = 'Cron: HOURLY — grow the look-ahead and make ticks triggered by app requests the main driver';
        } elseif ($iv['median'] < 45) {
            $decisions[] = sprintf('Cron: arrivals every ~%s s — more than one job set up, or manual calls mixed in; the per-job interval is unclear', $iv['median']);
        } else {
            $decisions[] = sprintf('Cron: every ~%d s — set the commit horizon above twice that', (int) $iv['median']);
        }
        if (isset($via['header'])) {
            $decisions[] = 'Cron key: Authorization header arrives — use the header form (npm run cron-command)';
        } elseif (isset($via['query'])) {
            $decisions[] = 'Cron key: only ?key= arrives — use the query fallback line of npm run cron-command';
        }
        if (isset($via['cli'])) {
            $decisions[] = 'CLI: a PHP-script cron runs here — bin/tick.php can drive the station without the FPM time limit';
        }
    }
    if (($inv['arrivals'] ?? 0) > 0) {
        $warnLine(sprintf('%d arrival(s) WITHOUT a valid key (via %s) — a panel that drops the header shows up here', $inv['arrivals'], json_encode($inv['via'] ?? [])));
    }
}

// --- .htaccess and friends -------------------------------------------------------------------

$head('.htaccess directives and PHP settings');
$rows = [];
foreach (is_file("$dir/checks.tsv") ? file("$dir/checks.tsv", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $l) {
    $p = explode("\t", $l);
    $rows[] = ['group' => $p[0] ?? '', 'label' => $p[1] ?? '', 'path' => $p[2] ?? '', 'status' => $p[3] ?? '',
        'type' => $p[4] ?? '', 'header' => $p[5] ?? '', 'verdict' => $p[6] ?? '', 'note' => $p[7] ?? '', 'json' => $p[8] ?? ''];
}
$byLabel = [];
foreach ($rows as $r) {
    $byLabel[$r['label']] = $r;
    if (in_array($r['group'], ['auth', 'ini'], true)) continue;
    $r['verdict'] === 'pass'
        ? $okLine(sprintf('%s (%s → %s)', $r['label'], $r['path'], $r['status']))
        : $badLine(sprintf('%s (%s → %s: %s)', $r['label'], $r['path'], $r['status'], $r['note']));
}
if (($byLabel['directives accepted (DirectoryIndex)']['status'] ?? '') === '500') {
    $decisions[] = '.htaccess: htaccess-test/.htaccess gives a 500 — one of its directives is forbidden here; bisect before deploying server/public';
}

$authOf = static function (array $r): ?bool {
    $j = json_decode($r['json'] ?? '', true);
    return is_array($j) ? (($j['http_authorization'] ?? false) || ($j['redirect_http_authorization'] ?? false)) : null;
};
$a1 = isset($byLabel['Bearer reaches PHP with SetEnvIf']) ? $authOf($byLabel['Bearer reaches PHP with SetEnvIf']) : null;
$a2 = isset($byLabel['Bearer reaches PHP without SetEnvIf']) ? $authOf($byLabel['Bearer reaches PHP without SetEnvIf']) : null;
$a3 = isset($byLabel['Bearer reaches PHP via CGIPassAuth']) ? $authOf($byLabel['Bearer reaches PHP via CGIPassAuth']) : null;
$line(sprintf('Authorization header reaches PHP — with SetEnvIf: %s · plain: %s · CGIPassAuth: %s',
    $a1 === null ? '?' : $yes($a1), $a2 === null ? '?' : $yes($a2), $a3 === null ? '?' : $yes($a3)));
if ($a1 === true) {
    $decisions[] = 'Auth: keep `SetEnvIf Authorization` in the /api and /cron.php .htaccess' . ($a2 === true ? ' (the header even arrives without it)' : ' (without it the header is lost)');
} elseif ($a3 === true) {
    $decisions[] = 'Auth: SetEnvIf does not pass the header, CGIPassAuth does — use `CGIPassAuth On`';
} elseif ($a1 === false) {
    $decisions[] = 'Auth: the Authorization header NEVER reaches PHP — every Bearer endpoint needs a second transport (X-Arche-Key header or query)';
}

$ini = $byLabel['.user.ini upload limits'] ?? null;
$j = $ini ? json_decode($ini['json'], true) : null;
if (is_array($j)) {
    $applied = ($j['upload_max_filesize'] ?? '') === '8M' && ($j['post_max_size'] ?? '') === '10M';
    $applied
        ? $okLine('.user.ini applied (upload_max_filesize 8M, post_max_size 10M)')
        : $warnLine(sprintf('.user.ini not (yet) applied: upload_max_filesize %s, post_max_size %s — FPM caches it %s s; read again later',
            $j['upload_max_filesize'] ?? '?', $j['post_max_size'] ?? '?', $probe['ini']['user_ini.cache_ttl'] ?? '300'));
    $decisions[] = $applied ? 'Limits: .user.ini works — server/public/.user.ini sets the upload limits' : 'Limits: .user.ini not confirmed yet';
} elseif ($ini) {
    $badLine('.user.ini test: ' . $ini['status'] . ' ' . $ini['note']);
}
$pv = $byLabel['php_value in .htaccess'] ?? null;
if ($pv) {
    $pj = json_decode($pv['json'], true);
    $line('php_value in .htaccess: ' . ($pv['status'] === '500' ? 'hard 500 (not allowed — never use it)' : (is_array($pj) && ($pj['upload_max_filesize'] ?? '') === '8M' ? 'works (htscanner)' : 'accepted but no effect')));
}
$pf = $byLabel['php_flag in .htaccess'] ?? null;
if ($pf) {
    $line('php_flag in .htaccess: ' . ($pf['status'] === '500' ? 'hard 500 (not allowed)' : ($pf['type'] === 'application/json' ? 'accepted but no effect (PHP still ran)' : 'works (engine off served the file unexecuted)')));
}

// --- decisions -----------------------------------------------------------------------------------

$head('Decisions');
foreach ($decisions as $d) print('  • ' . $d . "\n");
print("\n  raw results: $dir\n");

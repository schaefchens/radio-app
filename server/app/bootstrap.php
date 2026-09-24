<?php
declare(strict_types=1);

// Loaded by every PHP entry point: /api/index.php, /cron.php, bin/*, tests.
//
// On the webhosting this file is /_arche/app/bootstrap.php and the vendor dir
// is /_arche/vendor — the same relative layout as server/ in the repo, so the
// one `dirname(__DIR__)` works in both places without a lookup.
require dirname(__DIR__) . '/vendor/autoload.php';

// Runtime files (SQLite, uploads, logs) are private; published program and
// media files and their directories get explicit 0644 / 0755 (Files).
umask(0027);

return Arche\App::boot();

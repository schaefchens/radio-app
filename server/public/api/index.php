<?php
declare(strict_types=1);

// Front controller for /api/*. Everything private lives in /_arche, which is
// denied over HTTP by its own .htaccess.
$app = require dirname(__DIR__) . '/_arche/app/bootstrap.php';
Arche\Http\Kernel::run($app);

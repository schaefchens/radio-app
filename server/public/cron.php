<?php
declare(strict_types=1);

// Called once a minute by the konsoleH cron (see `npm run cron-command`).
$app = require __DIR__ . '/_arche/app/bootstrap.php';
Arche\Tick\CronEndpoint::run($app);

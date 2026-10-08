<?php
declare(strict_types=1);

// Replace this local station's setup with one exported from production
// (/mod › Status › "Station setup", admins; Plan\StationSetup): channels,
// programs, plans, the library and its groups, the hosts with their lineups
// and recorded lines, and the media they use. Local stacks only; the
// database is copied to /_arche/var/backups first. scripts/import-setup.sh
// pipes the file in:
//   npm run setup:import -- ~/Downloads/arche-setup-20261008.json
if (PHP_SAPI !== 'cli') exit(1);
$app = require dirname(__DIR__) . '/app/bootstrap.php';
try {
    $data = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) throw new InvalidArgumentException('This is not a station setup exported from /mod.');
    $out = (new Arche\Plan\StationSetup($app))->import($data, static function (string $line): void {
        echo $line, "\n";
    });
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
foreach ($out['media']['failed'] as $path) echo "not fetched: $path\n";
echo "Done. The next tick starts the program afresh.\n";

<?php
declare(strict_types=1);

// The server test harness (enoch's style: plain PHP, no framework).
//   php tests/run.php            all tests
//   php tests/run.php generator  only tests whose name contains "generator"
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/TestKit.php';

// The php container's environment (compose.yaml) configures the dev station;
// a process variable outranks any config (by design), so a test would see
// the dev station's AI mode or realtime driver instead of its own settings.
foreach (['AI_MODE', 'REALTIME_DRIVER', 'CDN_BASE_URL', 'ARCHE_ENV', 'ARCHE_ENV_FILE', 'ARCHE_PUBLIC_DIR', 'ARCHE_DATA_DIR'] as $var) putenv($var);

$tests = [];
$checks = 0;

function test(string $name, callable $fn): void
{
    $GLOBALS['tests'][] = [$name, $fn];
}

function check(bool $ok, string $label): void
{
    $GLOBALS['checks']++;
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
}

function eq(mixed $actual, mixed $expected, string $label): void
{
    check($actual === $expected, $label . ' — expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

/** True when $fn throws (optionally an ApiError with this code). */
function refuses(callable $fn, ?string $error = null): bool
{
    try {
        $fn();
        return false;
    } catch (Arche\ApiError $e) {
        return $error === null || $e->error === $error;
    } catch (Throwable) {
        return $error === null;
    }
}

foreach (glob(__DIR__ . '/cases/*.php') ?: [] as $file) require $file;

$filter = $argv[1] ?? '';
$failed = 0;
$ran = 0;
foreach ($tests as [$name, $fn]) {
    if ($filter !== '' && !str_contains($name, $filter)) continue;
    $ran++;
    try {
        $fn();
        echo "PASS $name\n";
    } catch (Throwable $e) {
        $failed++;
        echo "FAIL $name\n     " . $e->getMessage() . "\n     at " . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    } finally {
        TestKit::cleanup();
    }
}
echo "\n$ran tests, $checks checks, $failed failed\n";
exit($failed > 0 ? 1 : 0);

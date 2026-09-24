<?php
declare(strict_types=1);

use Arche\Program\Timing;

/**
 * shared/fixtures is the contract with every reader (shared/tests checks the
 * readers accept it). Here: what the generator writes has the same shape —
 * same keys, same kinds of values — as the fixture of the same kind.
 */
function shape(mixed $actual, mixed $fixture, string $path, array $maps): void
{
    if ($fixture === null || $actual === null) return;
    $key = substr($path, strrpos($path, '.') + 1);
    if (is_object($fixture)) {
        check(is_object($actual), "$path is an object");
        $f = get_object_vars($fixture);
        $a = get_object_vars($actual);
        if (in_array($key, $maps, true)) {
            $sample = reset($f);
            foreach ($a as $k => $v) shape($v, $sample, "$path.$k", $maps);
            return;
        }
        $missing = array_diff(array_keys($f), array_keys($a));
        $extra = array_diff(array_keys($a), array_keys($f));
        check(!$missing, "$path lacks " . implode(',', $missing));
        check(!$extra, "$path has unexpected " . implode(',', $extra));
        foreach ($a as $k => $v) shape($v, $f[$k], "$path.$k", $maps);
        return;
    }
    if (is_array($fixture)) {
        check(is_array($actual), "$path is a list");
        foreach ($actual as $i => $v) {
            $sample = $fixture[0] ?? null;
            // Lists of typed items compare against the fixture item of the same type.
            if (is_object($v) && isset($v->type)) {
                foreach ($fixture as $candidate) if (is_object($candidate) && ($candidate->type ?? null) === $v->type) $sample = $candidate;
                check($sample !== null && ($sample->type ?? null) === $v->type, "$path[$i] type {$v->type} is in the fixture");
            }
            shape($v, $sample, "$path[$i]", $maps);
        }
        return;
    }
    $t = fn($v) => is_int($v) || is_float($v) ? 'number' : gettype($v);
    eq($t($actual), $t($fixture), "$path type");
}

function fixture(string $name): mixed
{
    // Repo root: server/tests/cases → three levels up (in Docker, /srv holds
    // both server/ and shared/).
    $file = dirname(__DIR__, 3) . "/shared/fixtures/$name";
    if (!is_file($file)) throw new RuntimeException("fixture missing: $file");
    return json_decode((string) file_get_contents($file));
}

test('contract: generated program files match shared/fixtures', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $j = $app->store()->insert('library_items', ['kind' => 'jingle', 'audio' => '/media/jingles/x.mp3', 'title' => 'Ident', 'duration_ms' => 6000, 'created' => 1, 'updated' => 1]);
    unset($j);
    $ch = TestKit::main($app);
    $app->catalog()->saveProgram((int) $ch['fallback_program_id'], (int) $ch['id'], ['settings' => ['silence' => ['every_min' => 20, 'dur_s' => 30]]], 'test');
    for ($i = 0; $i < 40; $i++) {
        $app->tick()->run('test');
        TestKit::clock($app)->advance(60_000);
    }
    $now = $app->clock->nowMs();
    $maps = ['programs', 'submissions', 'audio', 'text', 'caption'];
    $types = [];
    foreach (glob($app->publicPath('program/main/slots/*/*.json')) as $file) {
        $slot = json_decode((string) file_get_contents($file));
        shape($slot, fixture('slot.json'), 'slot', $maps);
        foreach ($slot->items as $it) $types[$it->type] = true;
    }
    foreach (['song', 'host', 'jingle', 'silence', 'gap'] as $t) check(isset($types[$t]), "a published $t item");

    $day = json_decode((string) file_get_contents($app->publicPath('program/main/days/' . gmdate('Y-m-d', intdiv($now, 1000)) . '.json')));
    shape($day, fixture('day.json'), 'day', ['programs']);
    check(count($day->played) > 0, 'played list filled');
    shape(json_decode((string) file_get_contents($app->publicPath('program/main/live.json'))), fixture('live.json'), 'live', []);
    $channels = json_decode((string) file_get_contents($app->publicPath('program/channels.json')));
    shape($channels, fixture('channels.json'), 'channels', []);
    check(is_string($channels->channels[0]->evergreen), 'evergreen pointer');
    shape(json_decode((string) file_get_contents($app->publicPath(ltrim($channels->channels[0]->evergreen, '/')))), fixture('evergreen.json'), 'evergreen', []);
    unset($j, $types, $now, $maps);
    eq(Timing::COMMIT, Timing::WINDOW + Timing::LEAD, 'timing constants');
});

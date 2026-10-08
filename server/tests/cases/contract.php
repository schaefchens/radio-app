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
        // Keys only some files carry: a prayer hour's wall and count, the station's own request, a sender's name,
        // a host moment's notice after an item of a group, the page of a fact it told.
        $missing = array_diff(array_keys($f), array_keys($a), ['collected', 'from', 'source', 'texts', 'name', 'place', 'notice', 'cite']);
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
            } elseif (is_object($v)) {
                // Others against the first that has all its keys (a wall entry with a name, the station's own).
                foreach ($fixture as $candidate) {
                    if (is_object($candidate) && !array_diff(array_keys(get_object_vars($v)), array_keys(get_object_vars($candidate)))) {
                        $sample = $candidate;
                        break;
                    }
                }
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
    // One prayer on the wall, or live.json's wall would be an empty list whose entries are never compared.
    $identity = listener($app);
    $app->store()->insert('submissions', ['public_id' => 'k3v9q2m7x4tb', 'identity_id' => (int) $identity['id'], 'channel_id' => (int) $ch['id'],
        'program_id' => (int) $ch['fallback_program_id'], 'type' => 'prayer', 'mode' => 'text', 'status' => 'aired', 'name' => 'Ruth',
        'place' => 'Lagos', 'text' => 'Please pray for my mother.', 'consent_air' => 1, 'created' => $app->clock->now(), 'updated' => $app->clock->now()]);
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
    // Prayer music airs only in a prayer hour: one piece, published like any committed item.
    $bed = $app->timeline()->addDraft((int) $ch['id'], ['type' => 'bed', 'dur_ms' => 60_000, 'est_start' => $now, 'block_start' => $now,
        'block_end' => $now + 3_600_000, 'program_id' => (int) $ch['fallback_program_id'],
        'payload' => ['audio' => '/media/beds/x.mp3', 'label' => ['en' => 'What can we pray for?', 'de' => 'Wofür dürfen wir beten?'], 'offset' => 0]]);
    $app->timeline()->commit($bed['id'], $now, 60_000);
    $fixtureBed = array_values(array_filter(fixture('slot.json')->items, fn($i) => $i->type === 'bed'))[0];
    shape(json_decode((string) json_encode($app->publisher()->item($app->timeline()->get($bed['id']) ?? []))), $fixtureBed, 'bed', $maps);

    $day = json_decode((string) file_get_contents($app->publicPath('program/main/days/' . gmdate('Y-m-d', intdiv($now, 1000)) . '.json')));
    shape($day, fixture('day.json'), 'day', ['programs']);
    check(count($day->played) > 0, 'played list filled');
    foreach ($day->played as $p) check($p->type !== 'song' || preg_match('/^[\w-]{11}$/', $p->yt) === 1, 'a played song names its video');
    $live = json_decode((string) file_get_contents($app->publicPath('program/main/live.json')));
    shape($live, fixture('live.json'), 'live', []);
    eq(count($live->wall), 1, 'a wall entry was compared');
    eq([$live->wall[0]->name ?? null, $live->wall[0]->place ?? null], ['Ruth', 'Lagos'], 'with the first name and place its sender gave');
    $channels = json_decode((string) file_get_contents($app->publicPath('program/channels.json')));
    shape($channels, fixture('channels.json'), 'channels', []);
    check(is_string($channels->channels[0]->evergreen), 'evergreen pointer');
    shape(json_decode((string) file_get_contents($app->publicPath(ltrim($channels->channels[0]->evergreen, '/')))), fixture('evergreen.json'), 'evergreen', []);
    unset($j, $types, $now, $maps, $live, $identity, $bed, $fixtureBed);
    eq(Timing::COMMIT, Timing::WINDOW + Timing::LEAD, 'timing constants');
});

test('contract: a prayer hour\'s files match shared/fixtures — its readings, its wall as it is read, its count', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $app->store()->set('opendoors', ['guid' => '1', 'published' => TestKit::T0, 'country_de' => 'Nigeria', 'de' => 'Beten wir für die Christen in Nigeria.',
        'country_en' => 'Nigeria', 'en' => 'Let us pray for the Christians in Nigeria.']);
    $p = prayerHour($app, 735, 60, ['opendoors' => true]);
    $counted = false;
    for ($m = 0; $m < 40; $m++) {
        if ($m === 16) prayFor($app, 'Contract');
        if ($m === 34) prayAs($app, 'Contract');
        $app->tick()->run('test');
        $live = json_decode((string) file_get_contents($app->publicPath('program/main/live.json')));
        shape($live, fixture('live.json'), 'live', []);
        $counted = $counted || isset($live->collected);
        TestKit::clock($app)->advance(60_000);
    }
    check($counted, 'live.json counted the hour\'s requests');
    $wall = json_decode((string) file_get_contents($app->publicPath('program/main/live.json')))->wall;
    check(count(array_filter($wall, fn($e) => isset($e->from))) === 2 && count(array_filter($wall, fn($e) => isset($e->source, $e->texts))) === 1,
        'the wall carried the moment each was read, and the station\'s own with its source and translation');
    $kinds = [];
    foreach (glob($app->publicPath('program/main/slots/*/*.json')) as $file) {
        $slot = json_decode((string) file_get_contents($file));
        shape($slot, fixture('slot.json'), 'slot', ['programs', 'submissions', 'audio', 'text', 'caption']);
        foreach ($slot->items as $it) if ($it->type === 'host') $kinds[$it->kind] = true;
    }
    foreach (['intro', 'present', 'reading', 'prayertime', 'intercession'] as $k) check(isset($kinds[$k]), "a published $k");
    unset($p);
});

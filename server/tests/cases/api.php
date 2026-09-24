<?php
declare(strict_types=1);

use Arche\Http\Kernel;
use Arche\Http\Request;

function call(Arche\App $app, string $method, string $path, array $body = [], array $headers = []): array
{
    $req = new Request($method, $path, [], $headers, $body ? json_encode($body) : '');
    $res = (new Kernel($app))->handle($req);
    return [$res->status, $res->data];
}

function authHeaders(): array
{
    [$d, $s] = device();
    return ['x-arche-id' => $d, 'x-arche-secret' => $s];
}

test('api: time, session, unknown route, wrong method', function () {
    $app = TestKit::app();
    [$st, $d] = call($app, 'GET', '/api/time');
    eq([$st, $d['now']], [200, TestKit::T0], 'time');
    [$st, $d] = call($app, 'POST', '/api/session', [], authHeaders());
    eq($st, 200, 'session');
    eq($d['identity'], null, 'no identity row for a fresh device');
    check($d['config']['setupNeeded'] === true, 'setup is needed on a fresh install');
    eq(call($app, 'GET', '/api/nope')[0], 404, '404');
    eq(call($app, 'DELETE', '/api/time')[0], 405, '405');
});

test('cron: one tick per 20 s, and frequent calls cannot hold it off', function () {
    $app = TestKit::app();
    $store = $app->store();
    check(Arche\Tick\CronEndpoint::due($store, 1000), 'the first call ticks');
    check(!Arche\Tick\CronEndpoint::due($store, 1010), 'not again within 20 s');
    check(!Arche\Tick\CronEndpoint::due($store, 1019), 'still not');
    check(Arche\Tick\CronEndpoint::due($store, 1021), 'calls every few seconds still get a tick every 20 s');
    for ($t = 1024; $t < 1100; $t += 3) {
        if (Arche\Tick\CronEndpoint::due($store, $t)) check($t >= 1041, "tick at $t is ≥ 20 s after the last");
    }
    check((int) $store->get('cron_tick') >= 1061, 'kept ticking under a stream of calls');
    eq((int) $store->get('cron_seen'), 1099, 'the last call is recorded for the status page');
});

test('api: pulse counts the listener without creating an identity row', function () {
    $app = TestKit::app();
    [$st] = call($app, 'POST', '/api/pulse', ['channel' => 'main'], authHeaders());
    eq($st, 200, 'pulse');
    eq($app->presence()->listeners('main'), 1, 'one listener');
    eq((int) $app->store()->value('SELECT COUNT(*) FROM identities'), 0, 'still no identity rows');
    eq(call($app, 'POST', '/api/pulse', ['channel' => 'main'], ['x-arche-id' => 'nope'])[0], 401, 'bad device id');
});

test('api: moderator routes are closed to listeners; setup makes the first admin once', function () {
    $app = TestKit::app();
    $h = authHeaders();
    eq(call($app, 'GET', '/api/mod/overview', [], $h)[0], 403, 'listener forbidden');
    eq(call($app, 'POST', '/api/setup/admin', ['key' => str_repeat('s', 40)], $h)[1]['error'], 'needs_passphrase', 'needs a passphrase first');
    [$cid, $cs] = credential();
    eq(call($app, 'POST', '/api/identity/claim', ['credId' => $cid, 'credSecret' => $cs], $h)[0], 200, 'claim');
    eq(call($app, 'POST', '/api/setup/admin', ['key' => 'wrong'], $h)[1]['error'], 'bad_setup_key', 'wrong key');
    [$st, $d] = call($app, 'POST', '/api/setup/admin', ['key' => str_repeat('s', 40)], $h);
    eq([$st, $d['identity']['role']], [200, 'admin'], 'admin');
    eq(call($app, 'GET', '/api/mod/overview', [], $h)[0], 200, 'admin sees /mod');
    eq(call($app, 'POST', '/api/setup/admin', ['key' => str_repeat('s', 40)], authHeaders())[0], 409, 'setup is closed afterwards');
});

test('api: a moderator builds the program: program, day plan, week plan', function () {
    $app = TestKit::app();
    $h = authHeaders();
    [$cid, $cs] = credential();
    call($app, 'POST', '/api/identity/claim', ['credId' => $cid, 'credSecret' => $cs], $h);
    call($app, 'POST', '/api/setup/admin', ['key' => str_repeat('s', 40)], $h);
    $ch = TestKit::main($app);
    [$st, $d] = call($app, 'POST', "/api/mod/channels/{$ch['id']}/programs", ['slug' => 'prayer', 'title_en' => 'Prayer Hour', 'title_de' => 'Gebetsstunde', 'allowed' => ['prayer'], 'themes' => ['prayer']], $h);
    eq($st, 200, 'program created');
    $pid = $d['program']['id'];
    [$st, $d] = call($app, 'POST', "/api/mod/channels/{$ch['id']}/day-plans", ['name' => 'Weekday', 'blocks' => [
        ['start_min' => 0, 'end_min' => 420, 'program_id' => $ch['fallback_program_id']],
        ['start_min' => 420, 'end_min' => 480, 'program_id' => $pid],
    ]], $h);
    eq($st, 200, 'day plan');
    [$st] = call($app, 'PUT', "/api/mod/channels/{$ch['id']}/week", ['week' => [1 => $d['id'], 2 => $d['id'], 3 => $d['id']]], $h);
    eq($st, 200, 'week plan');
    $blocks = $app->resolver()->blocksForDate(TestKit::main($app), '2026-09-23');
    check(in_array($pid, array_column($blocks, 'program_id'), true), 'Wednesday now has the prayer hour');
    eq(call($app, 'DELETE', "/api/mod/programs/$pid", [], $h)[1]['error'] ?? null, 'program_in_use', 'a program in a plan cannot be deleted');
});

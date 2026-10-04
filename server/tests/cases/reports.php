<?php
declare(strict_types=1);

/**
 * What the app stores ask of user content: a filter on what is posted, and a
 * way for listeners to report what others wrote — answered by moderators.
 */

/**
 * Headers of a listener device, and that listener's identity row: known for
 * two days (only those count toward taking a wall request down), or new.
 *
 * @return array{0:array<string,string>,1:array<string,mixed>}
 */
function reporter(Arche\App $app, bool $known = true): array
{
    [$d, $s] = device();
    $i = $app->identities()->resolve($d, $s, true);
    if ($known) $app->store()->update('identities', ['created' => $app->clock->now() - 2 * 86400], 'id = ?', [(int) $i['id']]);
    return [['x-arche-id' => $d, 'x-arche-secret' => $s], $app->identities()->get((int) $i['id'])];
}

test('names: the moderators’ word list applies to display names too', function () {
    $app = TestKit::app();
    $app->store()->set('chat_blocklist', ['badword']);
    [$h] = reporter($app);
    [$st, $d] = call($app, 'PATCH', '/api/me', ['name' => 'Ana BADWORD'], $h);
    eq([$st, $d['error'] ?? null], [422, 'name_not_allowed'], 'refused, whatever the case');
    eq(call($app, 'PATCH', '/api/me', ['name' => "bad\u{200B}word"], $h)[1]['error'] ?? null, 'name_not_allowed', 'an invisible character does not smuggle it past');
    [$st, $d] = call($app, 'PATCH', '/api/me', ['name' => "  Ana \t Lisa "], $h);
    eq([$st, $d['identity']['name']], [200, 'Ana Lisa'], 'a clean name, whitespace collapsed');
    $app->store()->set('chat_blocklist', []);
    eq(call($app, 'PATCH', '/api/me', ['name' => 'badword fan'], $h)[0], 200, 'an empty list filters nothing');
});

test('reports: a wall request reported by three listeners comes off at once; one listener counts once', function () {
    $app = TestKit::app();
    $main = TestKit::main($app);
    $id = prayerRow($app, $main);
    $other = prayerRow($app, $main);
    $live = fn() => array_column(json_decode((string) file_get_contents($app->publicPath('program/main/live.json')), true)['wall'], 'id');
    $app->publisher()->publishLive($main);
    check(in_array('p' . $id, $live(), true), 'on the wall');

    eq(call($app, 'POST', '/api/wall/pnope/report', [], reporter($app)[0])[0], 404, 'an unknown request');
    eq(call($app, 'POST', '/api/wall/p' . prayerRow($app, $main, ['consent_air' => 0]) . '/report', [], reporter($app)[0])[0], 404, 'one no wall shows');

    [$first] = reporter($app);
    for ($i = 0; $i < 3; $i++) eq(call($app, 'POST', "/api/wall/p$id/report", ['reason' => 'mean'], $first)[1], ['ok' => true, 'hidden' => false], 'reporting again is no new report');
    eq((int) $app->store()->value('SELECT COUNT(*) FROM wall_reports'), 1, 'one report');
    eq(call($app, 'POST', "/api/wall/p$id/report", [], reporter($app)[0])[1]['hidden'], false, 'two listeners: still shown');
    eq(call($app, 'POST', "/api/wall/p$id/report", [], reporter($app)[0])[1]['hidden'], true, 'the third takes it down');
    check(!in_array('p' . $id, $live(), true), 'gone from live.json at once');
    check(in_array('p' . $other, $live(), true), 'the others stay');
    eq((int) $app->submissions()->byPublicId($id)['hidden'], 2, 'taken down by reports, not by a moderator');
});

test('reports: devices made just now do not take a request down, and one a moderator kept stays up', function () {
    $app = TestKit::app();
    $main = TestKit::main($app);
    $admin = modHeaders($app);
    $id = prayerRow($app, $main);
    for ($i = 0; $i < 3; $i++) eq(call($app, 'POST', "/api/wall/p$id/report", [], reporter($app, false)[0])[1]['hidden'], false, 'a new device reports');
    eq((int) $app->store()->value("SELECT COUNT(*) FROM wall_reports WHERE status = 'open'"), 3, 'the moderators see every report');
    eq((int) $app->submissions()->byPublicId($id)['hidden'], 0, 'but device ids cost nothing: still on the wall');
    eq(call($app, 'POST', "/api/wall/p$id/report", [], reporter($app)[0])[1]['hidden'], false, 'one listener known for a day');
    eq(call($app, 'POST', "/api/wall/p$id/report", [], reporter($app)[0])[1]['hidden'], false, 'two');
    eq(call($app, 'POST', "/api/wall/p$id/report", [], reporter($app)[0])[1]['hidden'], true, 'three take it down');

    eq(call($app, 'POST', "/api/mod/review/$id/wall", ['hidden' => false], $admin)[0], 200, 'a moderator keeps it');
    for ($i = 0; $i < 3; $i++) eq(call($app, 'POST', "/api/wall/p$id/report", [], reporter($app)[0])[1]['hidden'], false, 'reported again');
    eq((int) $app->submissions()->byPublicId($id)['hidden'], 0, 'it stays up: the decision was made');
    eq(call($app, 'GET', '/api/mod/overview', [], $admin)[1]['wallReports'], 1, 'the new reports still reach the moderators');
});

test('reports: moderators see reported requests first, keep one, take one down and ban its sender', function () {
    $app = TestKit::app();
    $main = TestKit::main($app);
    $admin = modHeaders($app);
    $kept = prayerRow($app, $main);
    $banned = prayerRow($app, $main);
    prayerRow($app, $main, ['created' => $app->clock->now() + 10]);
    foreach ([$kept, $banned] as $id) for ($i = 0; $i < 3; $i++) call($app, 'POST', "/api/wall/p$id/report", [], reporter($app)[0]);

    [, $o] = call($app, 'GET', '/api/mod/overview', [], $admin);
    eq($o['wallReports'], 2, 'counted in the overview');
    [, $r] = modGet($app, '/api/mod/review', ['status' => 'wall'], $admin);
    eq(array_slice(array_column($r['items'], 'id'), 0, 2), [$banned, $kept], 'reported ones first');
    eq([$r['items'][0]['reports'], $r['items'][0]['hiddenBy']], [3, 'reports'], 'how often, and by whom it was taken down');

    eq(call($app, 'POST', "/api/mod/review/$kept/wall", ['hidden' => false], $admin)[0], 200, 'kept');
    eq((int) $app->submissions()->byPublicId($kept)['hidden'], 0, 'back on the wall');
    call($app, 'POST', "/api/mod/review/$banned/wall", ['hidden' => true, 'ban' => true], $admin);
    eq((int) $app->submissions()->byPublicId($banned)['hidden'], 1, 'taken down by the moderator');
    $sender = $app->identities()->get((int) $app->submissions()->byPublicId($banned)['identity_id']);
    eq($sender['banned'], 1, 'its sender banned');
    eq((int) $app->store()->value("SELECT COUNT(*) FROM wall_reports WHERE status = 'open'"), 0, 'every report answered');
    eq(call($app, 'GET', '/api/mod/overview', [], $admin)[1]['wallReports'], 0, 'nothing left to decide');
});

test('reports: a community voice goes to the chat reports, labelled; removing it takes it out of live.json', function () {
    $app = TestKit::app();
    $main = TestKit::main($app);
    $admin = modHeaders($app);
    $now = $app->clock->now();
    $app->store()->query(
        "INSERT INTO highlights(uid, channel, sub, name, country, text, status, at, created, updated) VALUES('h1', 'main', 'author1', 'Anna', 'DE', 'Something rude', 'approved', ?, ?, ?)",
        [$now * 1000, $now, $now],
    );
    $app->store()->query(
        "INSERT INTO highlights(uid, channel, sub, name, country, text, status, at, created, updated) VALUES('h2', 'main', 'author2', 'Ben', 'DE', 'Not shown yet', 'candidate', ?, ?, ?)",
        [$now * 1000, $now, $now],
    );
    eq(call($app, 'POST', '/api/voices/h2/report', [], reporter($app)[0])[0], 404, 'only a voice that is shown');
    [$h, $me] = reporter($app);
    eq(call($app, 'POST', '/api/voices/h1/report', ['reason' => 'rude'], $h)[0], 200, 'reported');
    call($app, 'POST', '/api/voices/h1/report', [], $h);
    [, $r] = call($app, 'GET', '/api/mod/reports', [], $admin);
    eq(count($r['reports']), 1, 'once per listener');
    eq([$r['reports'][0]['author'], $r['reports'][0]['reporter'], (int) $r['reports'][0]['voice']], ['author1', $me['public_id'], 1], 'its author, the reporter, and that it is a voice');

    $app->publisher()->publishLive($main);
    call($app, 'POST', '/api/mod/reports/' . $r['reports'][0]['id'], ['action' => 'remove'], $admin);
    $voices = json_decode((string) file_get_contents($app->publicPath('program/main/live.json')), true)['voices'];
    eq(array_column($voices, 'id'), [], 'gone from live.json at once');
});

test('reports: limited per listener; old ones and old playback errors are purged', function () {
    $app = TestKit::app();
    $main = TestKit::main($app);
    [$h] = reporter($app);
    $codes = [];
    for ($i = 0; $i < 11; $i++) $codes[] = call($app, 'POST', '/api/wall/p' . prayerRow($app, $main) . '/report', [], $h)[0];
    eq(array_count_values($codes), [200 => 10, 429 => 1], 'ten an hour');

    $store = $app->store();
    $store->query('INSERT INTO playback_errors(library_id, reporter, code, time) VALUES(1, ?, 150, ?)', ['dev', $app->clock->now()]);
    TestKit::clock($app)->advance(31 * 86400 * 1000);
    $app->tick()->run('test');
    eq((int) $store->value('SELECT COUNT(*) FROM wall_reports'), 0, 'wall reports after 30 days');
    eq((int) $store->value('SELECT COUNT(*) FROM playback_errors'), 0, 'playback errors after 30 days');
});

test('reports: a community voice carries its author’s mark, not their public id', function () {
    $app = TestKit::app();
    TestKit::main($app);
    // The same vector as app/tests/unit/blocking.test.ts (lib/blocking.ts voiceTag).
    eq(Arche\Presence\Presence::voiceTag('k3v9q2m7x4'), '6e59a2360912', 'the app computes the same mark');
    $now = $app->clock->now();
    $app->store()->query(
        "INSERT INTO highlights(uid, channel, sub, name, country, text, status, at, created, updated) VALUES('h1', 'main', 'k3v9q2m7x4', 'Anna', 'DE', 'Amen!', 'approved', ?, ?, ?)",
        [$now * 1000, $now, $now],
    );
    $v = $app->presence()->voices('main')[0];
    eq([$v['id'], $v['by']], ['h1', '6e59a2360912'], 'marked');
    check(!str_contains((string) json_encode($v), 'k3v9q2m7x4'), 'never the public id itself');
});

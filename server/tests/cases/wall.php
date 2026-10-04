<?php
declare(strict_types=1);

use Arche\Support\Ids;

/**
 * A submission row as the pipeline leaves it, without running the pipeline:
 * the wall only reads what moderation decided.
 *
 * @param array<string,mixed> $fields
 */
function prayerRow(Arche\App $app, array $channel, array $fields = []): string
{
    $identity = listener($app);
    $publicId = Ids::short(12);
    $now = $app->clock->now();
    $app->store()->insert('submissions', $fields + [
        'public_id' => $publicId, 'identity_id' => (int) $identity['id'], 'channel_id' => (int) $channel['id'],
        'program_id' => (int) $channel['fallback_program_id'], 'type' => 'prayer', 'mode' => 'text', 'status' => 'approved',
        'name' => 'Ruth', 'place' => 'Lagos', 'text' => "Please pray for us ($publicId).", 'consent_air' => 1,
        'created' => $now, 'updated' => $now,
    ]);
    return $publicId;
}

test('wall: accepted typed prayers shown with consent, anonymous, newest first, per channel', function () {
    $app = TestKit::app();
    $main = TestKit::main($app);
    $other = $app->catalog()->saveChannel(null, ['slug' => 'night', 'name_en' => 'Night', 'name_de' => 'Nacht'], 'test');
    $t = $app->clock->now();
    $approved = prayerRow($app, $main, ['created' => $t - 300]);
    $scheduled = prayerRow($app, $main, ['status' => 'scheduled', 'created' => $t - 200]);
    $aired = prayerRow($app, $main, ['status' => 'aired', 'created' => $t - 100, 'text' => 'Pray for my mother in hospital.']);
    foreach (['received', 'checking', 'review', 'rejected', 'missed'] as $status) prayerRow($app, $main, ['status' => $status]);
    prayerRow($app, $main, ['consent_air' => 0]);
    prayerRow($app, $main, ['hidden' => 1]);
    prayerRow($app, $main, ['mode' => 'audio', 'text' => '', 'audio' => '/media/contrib/x.mp3', 'audio_ms' => 20_000]);
    prayerRow($app, $main, ['type' => 'song', 'mode' => '', 'yt_id' => 'AbCdEfGhIjK', 'text' => '']);
    $elsewhere = prayerRow($app, $other);

    $wall = $app->submissions()->wall('main');
    eq(array_column($wall, 'id'), ['p' . $aired, 'p' . $scheduled, 'p' . $approved], 'approved, scheduled and aired only, newest first');
    eq($wall[0], ['id' => 'p' . $aired, 'text' => 'Pray for my mother in hospital.', 'at' => ($t - 100) * 1000], 'text and day only');
    foreach ($wall as $e) eq(array_keys($e), ['id', 'text', 'at'], 'no name, no place');
    check(!str_contains((string) json_encode($wall), 'Ruth') && !str_contains((string) json_encode($wall), 'Lagos'), 'the sender is not named anywhere');
    eq(array_column($app->submissions()->wall('night'), 'id'), ['p' . $elsewhere], 'each channel its own wall');
    eq(array_column($app->submissions()->wall('main', 2), 'id'), ['p' . $aired, 'p' . $scheduled], 'the newest $limit');
    for ($i = 0; $i < 30; $i++) prayerRow($app, $main, ['created' => $t - 1000 - $i]);
    eq(count($app->submissions()->wall('main')), 30, 'at most 30 by default');

    $app->publisher()->publishLive($main);
    $live = json_decode((string) file_get_contents($app->publicPath('program/main/live.json')), true);
    eq(array_slice(array_column($live['wall'], 'id'), 0, 3), ['p' . $aired, 'p' . $scheduled, 'p' . $approved], 'published in live.json');
});

test('wall: community voices carry chat highlights only, no prayers', function () {
    $app = TestKit::app();
    $main = TestKit::main($app);
    prayerRow($app, $main);
    eq($app->presence()->voices('main'), [], 'an accepted prayer is not a voice');
    $now = $app->clock->now();
    $app->store()->query(
        "INSERT INTO highlights(uid, channel, sub, name, country, text, status, at, created, updated) VALUES('h1', 'main', 's', 'Anna', 'DE', 'Amen!', 'approved', ?, ?, ?)",
        [$now * 1000, $now, $now],
    );
    eq(array_column($app->presence()->voices('main'), 'id'), ['h1'], 'a highlight is');
    eq(count($app->submissions()->wall('main')), 1, 'the prayer is on the wall instead');
});

test('wall: a moderator takes a prayer off the wall and puts it back; listeners cannot', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $app->tick()->run('test');
    $main = TestKit::main($app);
    $sub = $app->submissions()->submitPrayer(listener($app), $main, ['text' => 'Please pray for peace in our town.', 'name' => 'Ruth', 'place' => 'Lagos', 'consent_air' => '1']);
    runJobs($app);
    eq(array_column($app->submissions()->wall('main'), 'id'), ['p' . $sub['id']], 'on the wall once accepted');
    $noConsent = prayerRow($app, $main, ['consent_air' => 0]);
    $song = prayerRow($app, $main, ['type' => 'song', 'mode' => '', 'yt_id' => 'AbCdEfGhIjK', 'text' => '']);
    $liveWall = fn() => json_decode((string) file_get_contents($app->publicPath('program/main/live.json')), true)['wall'];

    $h = modHeaders($app);
    eq(call($app, 'POST', "/api/mod/review/{$sub['id']}/wall", ['hidden' => true], authHeaders())[0], 403, 'not for listeners');
    eq(count($app->submissions()->wall('main')), 1, 'so still on the wall');
    [$st, $d] = call($app, 'POST', "/api/mod/review/{$sub['id']}/wall", ['hidden' => 'yes'], $h);
    eq([$st, $d['error'] ?? null], [422, 'bad_hidden'], 'only a real boolean');

    [$st, $d] = call($app, 'POST', "/api/mod/review/{$sub['id']}/wall", ['hidden' => true], $h);
    eq([$st, $d], [200, ['ok' => true, 'hidden' => true]], 'taken down');
    eq($app->submissions()->wall('main'), [], 'off the wall');
    eq($liveWall(), [], 'and off live.json at once, without waiting for a tick');
    eq($app->submissions()->byPublicId($sub['id'])['status'], 'approved', 'its status, and so the prayer on air, is unchanged');
    check(in_array('Removed from the prayer wall', array_column($app->store()->all('SELECT event FROM audit'), 'event'), true), 'audited');

    [$st, $d] = modGet($app, '/api/mod/review', ['status' => 'wall'], $h);
    eq([$st, array_column($d['items'], 'id')], [200, [$sub['id']]], 'the wall list shows it although hidden, not the one without consent');
    eq([$d['items'][0]['hidden'], $d['items'][0]['consentAir'], $d['items'][0]['wall']], [true, true, true], 'with its wall state');
    $all = array_column(modGet($app, '/api/mod/review', ['status' => 'all'], $h)[1]['items'], null, 'id');
    eq([$all[$noConsent]['hidden'], $all[$noConsent]['consentAir'], $all[$noConsent]['wall']], [false, false, false], 'every review item says all three');

    [$st, $d] = call($app, 'POST', "/api/mod/review/{$sub['id']}/wall", ['hidden' => false], $h);
    eq([$st, $d], [200, ['ok' => true, 'hidden' => false]], 'put back');
    eq(array_column($liveWall(), 'id'), ['p' . $sub['id']], 'back in live.json');

    eq(call($app, 'POST', "/api/mod/review/$song/wall", ['hidden' => true], $h)[0], 404, 'only typed prayers are on the wall');
    eq(call($app, 'POST', '/api/mod/review/nope/wall', ['hidden' => true], $h)[0], 404, 'unknown id');
});

test('reactions: the new kinds count on songs and voices, unknown kinds still do not', function () {
    preg_match("/REACTION_KINDS = \\[([^\\]]*)\\]/", (string) file_get_contents(dirname(__DIR__, 3) . '/shared/src/constants.ts'), $m);
    preg_match_all("/'([a-z]+)'/", $m[1] ?? '', $kinds);
    eq(array_keys(Arche\Presence\Trends::WEIGHTS), $kinds[1], 'a weight for every kind the app can send, and no other');

    $app = TestKit::app();
    TestKit::songs($app, 12);
    $app->tick()->run('test');
    $song = array_values(array_filter(TestKit::committed($app), fn($i) => $i['type'] === 'song'))[0];
    $now = $app->clock->now();
    $app->store()->query(
        "INSERT INTO highlights(uid, channel, sub, name, text, status, at, created, updated) VALUES('h1', 'main', 's', 'Anna', 'Amen!', 'approved', ?, ?, ?)",
        [$now * 1000, $now, $now],
    );
    [$st] = call($app, 'POST', '/api/pulse', ['channel' => 'main',
        'reactions' => [['item' => $song['uid'], 'kind' => 'love', 'n' => 2], ['item' => $song['uid'], 'kind' => 'celebrate'], ['item' => $song['uid'], 'kind' => 'hologram', 'n' => 5]],
        'voices' => [['voice' => 'h1', 'kind' => 'moved'], ['voice' => 'h1', 'kind' => 'hologram']],
    ], authHeaders());
    eq($st, 200, 'pulse');
    $rows = $app->store()->all('SELECT kind, n FROM reactions WHERE library_id = ? ORDER BY kind', [(int) $song['library_id']]);
    eq(array_column($rows, 'n', 'kind'), ['celebrate' => 1, 'love' => 2], 'love and celebrate counted, the unknown kind ignored');
    $score = (float) $app->store()->value('SELECT trend_score FROM library_items WHERE id = ?', [(int) $song['library_id']]);
    check(abs($score - 2.4) < 1e-9, "weighted 0.8 each (got $score)");
    eq((int) $app->store()->value("SELECT reactions FROM highlights WHERE uid = 'h1'"), 1, 'a voice counts the new kind, not the unknown one');
});

test('wall: while a prayer hour is on air it shows that hour\'s requests as they are read out, then the usual wall again', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $main = TestKit::main($app);
    $older = prayerRow($app, $main, ['created' => $app->clock->now() - 3600]);
    $p = prayerHour($app, 735);
    $ids = fn() => array_column($app->submissions()->wall('main'), 'id');
    eq($ids(), ['p' . $older], 'before the hour: the usual wall');
    ticks($app, 17);
    eq($ids(), [], 'the hour begins with an empty wall of its own');
    $mine = prayFor($app, 'Hour');
    $app->runner()->runUntilBudget();
    $notTicked = prayFor($app, 'Private', false);
    $app->runner()->runUntilBudget();
    eq([$ids(), $app->submissions()->collected('main')], [[], 2], 'sent, approved, not yet read: counted, not shown');
    check(!$app->submissions()->prayAlong($mine, 'd-' . str_repeat('a', 30)), 'nobody can pray along with what the wall does not show yet');
    ticks($app, 15);
    eq($ids(), ['p' . $notTicked, 'p' . $mine], 'read out: every request of the hour, ticked or not, the latest reading first');
    check($app->submissions()->prayAlong($notTicked, 'd-' . str_repeat('a', 30)), 'and each can be prayed along with');
    [, $d] = modGet($app, '/api/mod/review', ['status' => 'wall'], modHeaders($app));
    $listed = array_column($d['items'], null, 'id');
    eq([$listed[$notTicked]['wall'] ?? null, $listed[$notTicked]['consentAir'] ?? null], [true, false], 'the moderators see it on the wall, though not ticked, and can take it down');
    // Already read out (approved ones the hour cannot reach any more would be missed at its end).
    for ($i = 0; $i < 40; $i++) prayerRow($app, $main, ['program_id' => (int) $p['id'], 'status' => 'aired', 'aired_at' => $app->clock->nowMs() - 1_000 - $i,
        'created' => intdiv(TestKit::T0, 1000) + 15 * 60 + $i]);
    eq(count($app->submissions()->wall('main')), 42, 'more than the usual 30: the hour\'s requests, up to 60');
    ticks($app, 60);
    eq(count($ids()), 30, 'after the hour the newest 30 of all programs');
    eq($ids()[0], 'p' . $mine, 'with the hour\'s newest on top');
    check(!in_array('p' . $older, $ids(), true), 'pushing the older ones out');
    check(!in_array('p' . $notTicked, $ids(), true), 'and without the one its sender did not tick');
});

test('praying along: 🙏 on a wall request counts once per device, only for a request a wall may show; who prayed is forgotten, the number stays', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $app->tick()->run('test');
    $main = TestKit::main($app);
    $shown = $app->submissions()->submitPrayer(listener($app), $main, ['text' => 'Please pray for my son.', 'name' => 'Ruth', 'place' => 'Lagos', 'consent_air' => '1'])['id'];
    runJobs($app);
    $private = prayerRow($app, $main, ['consent_air' => 0]);
    $hidden = prayerRow($app, $main, ['hidden' => 1]);
    $pulse = fn(array $h, string $voice, string $kind) => call($app, 'POST', '/api/pulse', ['channel' => 'main', 'voices' => [['voice' => $voice, 'kind' => $kind]]], $h);
    $a = authHeaders();
    $b = authHeaders();
    foreach ([$a, $a, $b] as $h) $pulse($h, 'p' . $shown, 'pray');
    $pulse(authHeaders(), 'p' . $shown, 'love');
    $pulse($a, 'p' . $private, 'pray');
    $pulse($a, 'p' . $hidden, 'pray');
    $count = fn(string $public) => (int) $app->submissions()->byPublicId($public)['prayed_count'];
    eq([$count($shown), $count($private), $count($hidden)], [2, 0, 0], 'two devices prayed along (one of them twice: once), a heart does not count, nor a request no wall shows');
    eq($app->submissions()->publicView($app->submissions()->byPublicId($shown))['prayedWith'], 2, 'the sender sees it in their list');
    [$st, $d] = modGet($app, '/api/mod/review', ['status' => 'wall'], modHeaders($app));
    eq([$st, array_column($d['items'], 'prayedWith', 'id')[$shown] ?? null], [200, 2], 'and the moderators too');
    $rows = array_column($app->store()->all('SELECT who FROM prayed_along'), 'who');
    $presence = array_column($app->store()->all('SELECT device FROM presence'), 'device');
    check(count($rows) === 2 && array_intersect($rows, $presence) === [], 'the rows cannot be joined to presence');

    eq($app->submissions()->forgetPrayedAlong(), 0, 'kept while the wall shows the request');
    $app->submissions()->setHidden($shown, true);
    eq($app->submissions()->forgetPrayedAlong(), 2, 'off the wall, who prayed along is forgotten');
    eq($count($shown), 2, 'the number stays');
});

test('praying along: one address counts at most PRAY_ALONG_PER_IP_HOUR an hour', function () {
    $app = TestKit::app(['PRAY_ALONG_PER_IP_HOUR' => '3']);
    $main = TestKit::main($app);
    $public = prayerRow($app, $main, ['status' => 'aired']);
    for ($i = 0; $i < 5; $i++) call($app, 'POST', '/api/pulse', ['channel' => 'main', 'voices' => [['voice' => 'p' . $public, 'kind' => 'pray']]], authHeaders());
    eq((int) $app->submissions()->byPublicId($public)['prayed_count'], 3, 'five made-up devices on one address count three times');
});

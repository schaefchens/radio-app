<?php
declare(strict_types=1);

/**
 * Deleting an account (Identity\Erasure): everything it handed in goes at
 * once, the program keeps playing, and published minute files stay as they
 * are (immutable).
 */

/** @return array{0:array<string,string>,1:array<string,mixed>} headers and identity of a fresh device with a name */
function account(Arche\App $app, string $name): array
{
    [$d, $s] = device();
    $h = ['x-arche-id' => $d, 'x-arche-secret' => $s];
    call($app, 'PATCH', '/api/me', ['name' => $name], $h);
    return [$h, $app->identities()->resolve($d, $s, false)];
}

/** A song request of this identity, through the pipeline. @return int submission id */
function requestAs(Arche\App $app, array $identity, string $yt, string $name): int
{
    $pub = $app->submissions()->submitSong($identity, TestKit::main($app), ['url' => "https://youtu.be/$yt", 'name' => $name, 'place' => 'Hamburg']);
    runJobs($app);
    return (int) $app->submissions()->byPublicId($pub['id'])['id'];
}

/** A second admin, made by the first (setup is closed once there is one). @return array<string,string> */
function modHeaders2(Arche\App $app): array
{
    $h = authHeaders();
    [$cid, $cs] = credential();
    call($app, 'POST', '/api/identity/claim', ['credId' => $cid, 'credSecret' => $cs], $h);
    $i = $app->identities()->resolve($h['x-arche-id'], $h['x-arche-secret'], false);
    $app->identities()->setRole((string) $i['public_id'], 'admin', 'test');
    return $h;
}

/** A host that says every name it was given, as a real one would (the stub's words name nobody). */
function namingHost(Arche\App $app): void
{
    foreach (['host_announce', 'host_contrib', 'host_break', 'host_outro'] as $kind) {
        $app->text()->respond($kind, function (string $system, string $user): array {
            preg_match_all('/"name": "([^"]+)"/', $user, $m);
            $t = 'With us: ' . implode(', ', $m[1]) . '.';
            return ['en' => ['text' => $t], 'de' => ['text' => $t]];
        });
    }
}

/** Every minute file and its bytes. @return array<string,string> */
function minuteFiles(Arche\App $app): array
{
    $out = [];
    foreach (glob($app->publicPath('program/main/slots/*/*.json')) ?: [] as $f) $out[$f] = (string) file_get_contents($f);
    return $out;
}

test('erasure: the account and every device logged in with its words go; the words open nothing any more', function () {
    $app = TestKit::app();
    $main = TestKit::main($app);
    [$h1, $me] = account($app, 'Ruth');
    [$cid, $cs] = credential();
    call($app, 'POST', '/api/identity/claim', ['credId' => $cid, 'credSecret' => $cs], $h1);
    [$d2, $s2] = device();
    $h2 = ['x-arche-id' => $d2, 'x-arche-secret' => $s2];
    eq(call($app, 'POST', '/api/identity/login', ['credId' => $cid, 'credSecret' => $cs], $h2)[0], 200, 'a second device logs in');
    $wall = prayerRow($app, $main, ['identity_id' => $me['id']]);
    $other = prayerRow($app, $main);
    $now = $app->clock->now();
    $store = $app->store();
    $store->query("INSERT INTO highlights(uid, channel, sub, name, text, status, at, created, updated) VALUES('h1', 'main', ?, 'Ruth', 'Amen', 'approved', ?, ?, ?)", [$me['public_id'], $now * 1000, $now, $now]);
    $store->query("INSERT INTO chat_reports(msg, text, author, reporter, status, at, created) VALUES('m1', 'hi', ?, 'someone', 'open', 1, ?)", [$me['public_id'], $now]);
    $store->query("INSERT INTO chat_reports(msg, text, author, reporter, status, at, created) VALUES('m2', 'rude', 'someone', ?, 'open', 1, ?)", [$me['public_id'], $now]);

    [$st, $d] = call($app, 'DELETE', '/api/me', [], $h2);
    eq([$st, $d], [200, ['deleted' => true, 'self' => true]], 'deleted from the second device');
    eq((int) $store->value('SELECT COUNT(*) FROM identities WHERE id = ? OR canonical_id = ?', [$me['id'], $me['id']]), 0, 'both devices gone');
    eq($app->submissions()->byPublicId($wall), null, 'what it handed in');
    check($app->submissions()->byPublicId($other) !== null, 'nobody else’s');
    $live = json_decode((string) file_get_contents($app->publicPath('program/main/live.json')), true);
    eq(array_column($live['wall'], 'id'), ['p' . $other], 'off the wall at once');
    eq((int) $store->value("SELECT COUNT(*) FROM highlights WHERE sub = ?", [$me['public_id']]), 0, 'its community voices');
    eq((int) $store->value("SELECT COUNT(*) FROM chat_reports WHERE author = ?", [$me['public_id']]), 0, 'reports about it');
    eq((string) $store->value("SELECT reporter FROM chat_reports WHERE msg = 'm2'"), 'gone:' . $store->value("SELECT id FROM chat_reports WHERE msg = 'm2'"), 'a report it filed stays, without who filed it');
    // Earlier entries (a login, a claim) keep their pseudonymous id for the log's 90 days (privacy policy).
    check(!str_contains((string) json_encode($store->all("SELECT * FROM audit WHERE event = 'Account deleted'")), $me['public_id']), 'the deletion is logged as how many, not whose');
    eq(call($app, 'POST', '/api/identity/login', ['credId' => $cid, 'credSecret' => $cs], authHeaders())[1]['error'] ?? null, 'unknown_passphrase', 'the words open nothing');
});

test('erasure: from any browser with the 12 words; a device without an account; guards', function () {
    $app = TestKit::app();
    [$h, $me] = account($app, 'Tom');
    [$cid, $cs] = credential();
    call($app, 'POST', '/api/identity/claim', ['credId' => $cid, 'credSecret' => $cs], $h);
    $browser = authHeaders();
    [, $other] = account($app, 'Uli');
    eq(call($app, 'DELETE', '/api/me', ['credId' => $cid, 'credSecret' => bin2hex(random_bytes(32))], $browser)[1]['error'] ?? null, 'unknown_passphrase', 'wrong words');
    [$st, $d] = call($app, 'DELETE', '/api/me', ['credId' => $cid, 'credSecret' => $cs], $browser);
    eq([$st, $d], [200, ['deleted' => true, 'self' => false]], 'with the right ones, from a browser that is not the account');
    eq($app->identities()->get((int) $me['id']), null, 'the account is gone');
    check($app->identities()->get((int) $other['id']) !== null, 'another account is not');

    $fresh = authHeaders();
    [$st, $d] = call($app, 'DELETE', '/api/me', [], $fresh);
    eq([$st, $d], [200, ['deleted' => false, 'self' => true]], 'a device without an account: nothing on the server');
    eq($app->identities()->resolve($fresh['x-arche-id'], $fresh['x-arche-secret'], false), null, 'and still no row');

    $admin = modHeaders($app);
    eq(call($app, 'DELETE', '/api/me', [], $admin)[1]['error'] ?? null, 'last_admin', 'the last admin stays (the station would need its setup key again)');
    $second = modHeaders2($app);
    eq(call($app, 'DELETE', '/api/me', [], $admin)[0], 200, 'with a second admin, either may go');
    eq(call($app, 'GET', '/api/mod/overview', [], $second)[0], 200, 'the other still works');

    [$bh, $banned] = account($app, 'Vic');
    $app->identities()->setBanned($banned['public_id'], true, 'test');
    eq(call($app, 'DELETE', '/api/me', [], $bh)[1]['deleted'] ?? null, true, 'a banned listener may delete too');

    $alone = authHeaders();
    eq(call($app, 'DELETE', '/api/me', [], ['x-arche-id' => $alone['x-arche-id']])[1]['error'] ?? null, 'identity_required', 'a device id alone deletes nothing');

    $codes = [];
    for ($i = 0; $i < 8; $i++) $codes[] = call($app, 'DELETE', '/api/me', [], authHeaders())[0];
    // Seven before these; ten an hour per address.
    eq(array_count_values($codes)[429] ?? 0, 5, 'ten an hour per address');
});

test('erasure: a request drafted and voiced never airs, and no script names it', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    ticks($app, 10);
    [$h, $jonas] = account($app, 'Jonas');
    $id = requestAs($app, $jonas, 'EraseSongJ1', 'Jonas');
    $voiced = null;
    for ($i = 0; $i < 10 && $voiced === null; $i++) {
        ticks($app, 1);
        $voiced = $app->store()->one(
            "SELECT t.id FROM timeline_items t JOIN host_breaks h ON h.id = t.host_break_id
             WHERE t.state = 'draft' AND h.state = 'ready' AND json_extract(h.context, '$.submission_id') = ?",
            [$id],
        );
    }
    check($voiced !== null, 'its announcement is drafted and voiced, not yet committed');

    eq(call($app, 'DELETE', '/api/me', [], $h)[0], 200, 'Jonas deletes his account');
    ticks($app, 20);
    foreach (TestKit::committed($app) as $it) {
        check(!str_contains((string) json_encode($it['payload']), 'Jonas'), 'no committed payload names Jonas: ' . $it['type'] . ' ' . json_encode($it['payload']) . ' start ' . ($it['start_ms'] - $app->clock->nowMs()));
        check($it['submission_id'] !== $id, 'no reference to his submission');
    }
    check(!str_contains(implode('', minuteFiles($app)), 'Jonas'), 'no minute file names him');
    check(!str_contains((string) json_encode($app->store()->all('SELECT context, texts FROM host_breaks')), 'Jonas'), 'nor any host script');
    check(!str_contains((string) json_encode($app->store()->all('SELECT payload FROM timeline_items')), 'Jonas'), 'nor any row of the plan, the dropped ones included');
    assertContiguous(TestKit::committed($app), 'the program plays on without a gap');
});

test('erasure: a request already committed is blocked for every listener; written minute files stay byte for byte, later ones do not name it', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    ticks($app, 10);
    [$h, $mia] = account($app, 'Mia');
    $id = requestAs($app, $mia, 'EraseSongM1', 'Mia');
    $committed = null;
    for ($i = 0; $i < 15 && $committed === null; $i++) {
        ticks($app, 1);
        $row = $app->store()->one("SELECT * FROM timeline_items WHERE submission_id = ? AND state = 'committed'", [$id]);
        if ($row !== null && (int) $row['start_ms'] > $app->clock->nowMs()) $committed = $row;
    }
    check($committed !== null, 'committed, not on air yet');
    $unit = array_column($app->store()->all("SELECT uid FROM timeline_items WHERE unit = ? AND state = 'committed'", [$committed['unit']]), 'uid');

    $before = minuteFiles($app);
    eq(call($app, 'DELETE', '/api/me', [], $h)[0], 200, 'Mia deletes her account');
    $live = json_decode((string) file_get_contents($app->publicPath('program/main/live.json')), true);
    check($unit !== [] && !array_diff($unit, $live['blocked']), 'her unit is blocked in live.json');
    foreach ($before as $f => $bytes) eq((string) file_get_contents($f), $bytes, 'unchanged: ' . basename(dirname($f)) . '/' . basename($f));

    ticks($app, 20);
    $after = array_diff_key(minuteFiles($app), $before);
    check($after !== [], 'new minute files written');
    foreach ($after as $f => $bytes) if (str_contains($bytes, 'Mia')) check(false, 'none of them names her: ' . basename($f) . ' ' . substr($bytes, max(0, strpos($bytes, 'Mia') - 300), 600));
    foreach (TestKit::committed($app) as $it) check($it['submission_id'] !== $id, 'no reference to her submission');
    check(!str_contains((string) json_encode($app->store()->all('SELECT context, texts FROM host_breaks')), 'Mia'), 'no host script names her');
    assertContiguous(TestKit::committed($app), 'the program plays on without a gap');
    $song = $app->library()->byYouTube('EraseSongM1');
    check($song === null || $song['submission_id'] === null, 'a requested song may stay in the selection, without its request');
});

test('erasure: an aired recording is deleted, at the edge too; the day files no longer name it', function () {
    $app = TestKit::app(CDN_ENV);
    // No real calls to Bunny from the jobs phase.
    $app->set('http', new FakeHttp());
    TestKit::songs($app, 12);
    ticks($app, 5);
    [$h, $lena] = account($app, 'Lena');
    $tmp = tempnam(sys_get_temp_dir(), 'rec');
    copy(dirname(__DIR__, 2) . '/resources/stub-voice.mp3', $tmp);
    $pub = $app->submissions()->submitAudio($lena, TestKit::main($app), ['type' => 'story', 'name' => 'Lena', 'place' => 'Bonn', 'consent_air' => '1', 'consent_replay' => '1'], $tmp)['id'];
    runJobs($app);
    $sub = $app->submissions()->byPublicId($pub);
    $audio = (string) $sub['audio'];
    check($audio !== '' && is_file($app->media()->path($audio)), 'published');
    ticks($app, 25);
    eq($app->submissions()->byPublicId($pub)['status'], 'aired', 'aired');
    $today = gmdate('Y-m-d', intdiv($app->clock->nowMs(), 1000));
    $dayFile = $app->publicPath("program/main/days/$today.json");
    check(str_contains((string) file_get_contents($dayFile), 'Lena, Bonn'), 'the day file lists it');
    // A moderator switched its replay on, and it aired again from the library.
    $lib = (int) $app->store()->value("SELECT id FROM library_items WHERE kind = 'contrib'");
    $at = $app->clock->nowMs() - 60_000;
    $app->store()->insert('timeline_items', ['uid' => 'replay1', 'channel_id' => (int) TestKit::main($app)['id'], 'state' => 'committed', 'seq' => 0.5,
        'est_start' => $at, 'start_ms' => $at, 'dur_ms' => 30_000, 'type' => 'contrib', 'block_start' => $at, 'block_end' => $at + 3_600_000, 'library_id' => $lib,
        'payload' => json_encode(['name' => 'Lena', 'place' => 'Bonn', 'audio' => $audio, 'caption' => ['en' => 'Lena from Bonn']]), 'created' => $app->clock->now()]);

    eq(call($app, 'DELETE', '/api/me', [], $h)[0], 200, 'deleted');
    check(!str_contains((string) json_encode($app->store()->all('SELECT payload FROM timeline_items')), 'Lena'), 'no row of the plan names it, its replay included');
    eq((int) $app->store()->value('SELECT COUNT(*) FROM timeline_items WHERE library_id = ?', [$lib]), 0, 'and none points at the library item');
    check(!is_file((string) $app->media()->path($audio)), 'the recording is gone here');
    check(in_array($audio, (array) $app->store()->get('cdn_purge'), true), 'and queued for the edge');
    check(!str_contains((string) file_get_contents($dayFile), 'Lena'), 'the day file no longer names it');
    eq((int) $app->store()->value("SELECT COUNT(*) FROM library_items WHERE kind = 'contrib'"), 0, 'not kept for replays either');
});

test('erasure: a shared prayer break gives the other request back, and it is prayed for later', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $app->tick()->run('test');
    [$h, $ana] = account($app, 'Ana');
    $mine = $app->submissions()->submitPrayer($ana, TestKit::main($app), ['text' => 'Please pray for my brother in hospital.', 'name' => 'Ana', 'place' => 'Porto']);
    $theirs = $app->submissions()->submitPrayer(listener($app), TestKit::main($app), ['text' => 'Please pray for peace in our town.', 'name' => 'Ben', 'place' => 'Kiel']);
    runJobs($app);
    $mineId = (int) $app->submissions()->byPublicId($mine['id'])['id'];
    $theirsId = (int) $app->submissions()->byPublicId($theirs['id'])['id'];
    $break = prayerBreakFor($app, $mineId);
    $ids = json_decode((string) $app->store()->value('SELECT context FROM host_breaks WHERE id = ?', [$break]), true)['prayer_ids'];
    check(in_array($theirsId, $ids, true), 'one break prays for both');

    eq(call($app, 'DELETE', '/api/me', [], $h)[0], 200, 'Ana deletes her account');
    eq($app->submissions()->get($theirsId)['status'], 'approved', 'Ben’s request waits again');
    ticks($app, 30);
    check(in_array($app->submissions()->get($theirsId)['status'], ['scheduled', 'aired'], true), 'and is prayed for later');
    check(!str_contains((string) json_encode($app->store()->all('SELECT context, texts FROM host_breaks')), 'brother in hospital'), 'Ana’s prayer is in no script');
});

test('erasure: the sweep scrubs a script written back after the account went, never a newer one that reused an id', function () {
    $app = TestKit::app();
    TestKit::main($app);
    $store = $app->store();
    $t = $app->clock->now();
    $store->insert('erasures', ['subs' => '[]', 'submission_ids' => '[41]', 'created' => $t]);
    $old = $store->insert('host_breaks', ['channel_id' => 1, 'kind' => 'announce', 'state' => 'ready', 'context' => json_encode(['submission_id' => 41, 'request' => ['name' => 'Zoe']]),
        'texts' => json_encode(['en' => 'For Zoe!']), 'created' => $t - 60, 'updated' => $t + 5]);
    $new = $store->insert('host_breaks', ['channel_id' => 1, 'kind' => 'announce', 'state' => 'pending', 'context' => json_encode(['submission_id' => 41, 'request' => ['name' => 'Yan']]),
        'texts' => '{}', 'created' => $t + 30, 'updated' => $t + 30]);
    TestKit::clock($app)->advance(60_000);
    eq($app->erasure()->sweep(), 1, 'one scrubbed');
    $get = fn(int $id) => $app->hostBreaks()->get($id);
    eq([$get($old)['texts'], isset($get($old)['context']['request'])], [[], false], 'the stale one is empty');
    eq($get($new)['context']['request']['name'], 'Yan', 'the new one keeps its listener');
});

test('erasure: the chat nodes are told to forget the account, and what they still report of it is not stored', function () {
    $app = TestKit::app();
    TestKit::main($app);
    [$h, $me] = account($app, 'Kim');
    eq(call($app, 'DELETE', '/api/me', [], $h)[0], 200, 'deleted');
    eq($app->erasure()->erasedSince($app->clock->now() - 60), [$me['public_id']], 'remembered for the nodes');
    $out = $app->nodes()->ingest('local', [
        'v' => 1, 'connections' => 1,
        'candidates' => [['id' => 'm1', 'text' => 'Amen', 'sub' => $me['public_id'], 'name' => 'Kim', 'likes' => 9, 'channel' => 'main']],
        'reports' => [['msg' => 'm2', 'text' => 'x', 'sub' => $me['public_id'], 'by' => 'someone']],
    ]);
    eq($out['forget'] ?? null, [$me['public_id']], 'the node is told');
    eq((int) $app->store()->value('SELECT COUNT(*) FROM highlights'), 0, 'its message is no candidate again');
    eq((int) $app->store()->value('SELECT COUNT(*) FROM chat_reports'), 0, 'nor reported again');
    TestKit::clock($app)->advance(2 * 3600 * 1000);
    eq($app->erasure()->erasedSince($app->clock->now() - 3600), [], 'an hour later the nodes need not know any more');
});

test('erasure: an aired request takes the host’s words around it along — the reaction after it too, with its clips', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    namingHost($app);
    ticks($app, 10);
    [$h, $mia] = account($app, 'Mia');
    $id = requestAs($app, $mia, 'EraseSongM1', 'Mia');
    for ($i = 0; $i < 40 && $app->submissions()->get($id)['status'] !== 'aired'; $i++) ticks($app, 1);
    eq($app->submissions()->get($id)['status'], 'aired', 'her request aired');
    ticks($app, 8);
    $song = Arche\Program\Timeline::decode($app->store()->one('SELECT * FROM timeline_items WHERE submission_id = ?', [$id]));
    $after = $app->timeline()->after($song['channel_id'], $song['seq']);
    eq([$after['type'] ?? '', $after['state'] ?? ''], ['host', 'committed'], 'the host spoke after it');
    $reaction = $app->hostBreaks()->get((int) $after['host_break_id']);
    eq($reaction['context']['previous_request']['name'] ?? '', 'Mia', 'reacting to her request');
    check(str_contains(implode(' ', $reaction['texts']), 'Mia'), 'by name');
    $clips = array_values($reaction['audio']);
    check($clips !== [] && is_file((string) $app->media()->path($clips[0])), 'voiced');

    $before = minuteFiles($app);
    eq(call($app, 'DELETE', '/api/me', [], $h)[0], 200, 'Mia deletes her account');
    foreach ($clips as $url) check(!is_file((string) $app->media()->path($url)), 'the reaction’s clip is gone: ' . $url);
    check(!str_contains((string) json_encode($app->store()->all('SELECT context, texts, audio FROM host_breaks')), 'Mia'), 'no host script names her');
    check(!str_contains((string) json_encode($app->store()->all('SELECT payload FROM timeline_items')), 'Mia'), 'no row of the plan, whatever its state');
    ticks($app, 5);
    foreach (array_diff_key(minuteFiles($app), $before) as $f => $bytes) check(!str_contains($bytes, 'Mia'), 'a later minute file does not name her: ' . basename($f));
});

test('erasure: another listener’s request right after it keeps its place; its announcement is written afresh, without the reaction', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    namingHost($app);
    ticks($app, 10);
    [$h, $jonas] = account($app, 'Jonas');
    $mine = requestAs($app, $jonas, 'EraseSongJ1', 'Jonas');
    $anna = requestAs($app, listener($app), 'EraseSongA1', 'Anna');
    $announce = null;
    for ($i = 0; $i < 10 && $announce === null; $i++) {
        ticks($app, 1);
        $announce = $app->store()->one(
            "SELECT t.* FROM timeline_items t JOIN host_breaks h ON h.id = t.host_break_id
             WHERE t.state = 'draft' AND h.state = 'ready' AND json_extract(h.context, '$.submission_id') = ? AND json_extract(h.context, '$.previous_id') = ?",
            [$anna, $mine],
        );
    }
    check($announce !== null, 'Anna’s announcement is drafted and voiced, reacting to Jonas');

    eq(call($app, 'DELETE', '/api/me', [], $h)[0], 200, 'Jonas deletes his account');
    $row = $app->store()->one('SELECT * FROM timeline_items WHERE id = ?', [(int) $announce['id']]);
    eq($row['state'], 'draft', 'her announcement keeps its place in the plan');
    check((int) $row['host_break_id'] !== (int) $announce['host_break_id'], 'with a break written afresh');
    eq((int) $app->store()->value("SELECT COUNT(*) FROM timeline_items WHERE submission_id = ? AND state = 'draft'", [$anna]), 1, 'and her song keeps its place too');
    ticks($app, 20);
    eq($app->submissions()->get($anna)['status'], 'aired', 'her request airs');
    check(!str_contains((string) json_encode($app->store()->all('SELECT context, texts FROM host_breaks')), 'Jonas'), 'no script names him');
    foreach (TestKit::committed($app) as $it) check(!str_contains((string) json_encode($it['payload']), 'Jonas'), 'nothing on air names him');
    assertContiguous(TestKit::committed($app), 'the program plays on without a gap');
    foreach ($app->text()->calls as $c) check(!str_contains($c['user'], 'previous_id') && !str_contains($c['user'], 'community_by'), 'the model is never given the ids');
});

test('erasure: its community voice leaves live.json, the plan and the scripts that quote it; a script that does not keeps its words', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    namingHost($app);
    ticks($app, 3);
    [$h, $me] = account($app, 'Ruthie');
    $store = $app->store();
    $now = $app->clock->now();
    $store->query("INSERT INTO highlights(uid, channel, sub, name, text, status, at, created, updated) VALUES('h1', 'main', ?, 'Ruthie', 'Amen brothers', 'approved', ?, ?, ?)", [$me['public_id'], $now * 1000, $now, $now]);
    // A break given the voice whose script does not use it.
    $quiet = $store->insert('host_breaks', ['channel_id' => (int) TestKit::main($app)['id'], 'kind' => 'break', 'state' => 'ready',
        'context' => json_encode(['community' => [['name' => 'Ruthie', 'country' => '', 'text' => 'Amen brothers']], 'community_by' => [Arche\Presence\Presence::voiceTag((string) $me['public_id'])]]),
        'texts' => json_encode(['en' => 'Lovely music tonight.']), 'created' => $now, 'updated' => $now]);
    for ($i = 0; $i < 30; $i++) {
        ticks($app, 1);
        runJobs($app);
    }
    check(str_contains((string) json_encode($store->all("SELECT payload FROM timeline_items WHERE state = 'committed'")), 'Ruthie'), 'the voice is in committed host moments');
    check(str_contains((string) json_encode($store->all('SELECT texts FROM host_breaks')), 'Ruthie'), 'and the host quotes it');

    $before = minuteFiles($app);
    eq(call($app, 'DELETE', '/api/me', [], $h)[0], 200, 'Ruthie deletes her account');
    check(!str_contains((string) json_encode($store->all('SELECT payload FROM timeline_items')), 'Ruthie'), 'no row of the plan carries it');
    check(!str_contains((string) json_encode($store->all('SELECT context, texts FROM host_breaks')), 'Ruthie'), 'no script quotes it, no context keeps it');
    eq($app->hostBreaks()->get($quiet)['texts'], ['en' => 'Lovely music tonight.'], 'a script that did not use it keeps its words');
    eq(json_decode((string) file_get_contents($app->publicPath('program/main/live.json')), true)['voices'], [], 'off live.json at once');
    ticks($app, 3);
    foreach (array_diff_key(minuteFiles($app), $before) as $f => $bytes) check(!str_contains($bytes, 'Ruthie'), 'later minute files do not carry it: ' . basename($f));
});

test('erasure: while the generator holds its lock nothing is deleted, and the listener is asked to try again', function () {
    $app = TestKit::app();
    TestKit::main($app);
    [$h, $me] = account($app, 'Noa');
    $dir = $app->config->dataDir . '/locks';
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    $held = fopen("$dir/publish.lock", 'c');
    flock($held, LOCK_EX);
    check(refuses(fn() => (new Arche\Identity\Erasure($app, 2))->erase($me, $h['x-arche-id']), 'busy'), 'busy, not done without the lock');
    check($app->identities()->get((int) $me['id']) !== null, 'nothing deleted');
    flock($held, LOCK_UN);
    fclose($held);
    eq(call($app, 'DELETE', '/api/me', [], $h)[0], 200, 'once the tick is done, it works');
});

test('host breaks: a script finished after its break was forgotten is not saved', function () {
    $app = TestKit::app();
    $main = TestKit::main($app);
    $program = $app->catalog()->program((int) $main['fallback_program_id']);
    $id = $app->hostBreaks()->create($main, $program, 'break', ['est_start' => $app->clock->nowMs() + 300_000, 'unit' => null]);
    $asked = false;
    $app->text()->respond('host_break', function () use ($app, $id, &$asked): array {
        $asked = true;
        // An account deleted while the model writes (Erasure::erase).
        $app->hostBreaks()->forget([$id], []);
        return ['en' => ['text' => 'For Zoe, who wrote to us!'], 'de' => ['text' => 'Für Zoe, die uns geschrieben hat!']];
    });
    $job = $app->store()->one("SELECT * FROM jobs WHERE type = 'host' AND ref_id = ?", [$id]);
    eq($app->hostBreaks()->runPhase($job), null, 'the job ends');
    check($asked, 'the model was asked');
    $hb = $app->hostBreaks()->get($id);
    eq([$hb['state'], $hb['texts']], ['cancelled', []], 'cancelled, and the script was not saved');
});

test('erasure: the sweep also scrubs a reaction and a quoted voice written back after the account went', function () {
    $app = TestKit::app();
    TestKit::main($app);
    $store = $app->store();
    $t = $app->clock->now();
    $store->insert('erasures', ['subs' => json_encode(['gonepub01']), 'submission_ids' => '[41]', 'created' => $t]);
    $tag = Arche\Presence\Presence::voiceTag('gonepub01');
    $voices = ['community' => [['name' => 'Zoe', 'text' => 'Amen'], ['name' => 'Pia', 'text' => 'Hallelujah']], 'community_by' => [$tag, 'other']];
    $row = fn(string $kind, array $context, string $text) => $store->insert('host_breaks', ['channel_id' => 1, 'kind' => $kind, 'state' => 'ready',
        'context' => json_encode($context), 'texts' => json_encode(['en' => $text]), 'created' => $t - 60, 'updated' => $t + 5]);
    $reaction = $row('announce', ['previous_id' => 41, 'previous_request' => ['name' => 'Zoe']], 'Thanks, Zoe!');
    $quote = $row('break', $voices, 'Zoe says Amen.');
    $unused = $row('break', $voices, 'Pia says Hallelujah.');
    TestKit::clock($app)->advance(60_000);
    eq($app->erasure()->sweep(), 2, 'two scrubbed');
    $get = fn(int $id) => $app->hostBreaks()->get($id);
    eq([$get($reaction)['texts'], isset($get($reaction)['context']['previous_request'])], [[], false], 'the reaction');
    eq($get($quote)['texts'], [], 'the quote');
    eq([$get($unused)['texts']['en'], array_column($get($unused)['context']['community'], 'name'), $get($unused)['context']['community_by']], ['Pia says Hallelujah.', ['Pia'], ['other']], 'a script that does not use it keeps its words; the voice leaves its context');
});

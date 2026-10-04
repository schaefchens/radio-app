<?php
declare(strict_types=1);

use Arche\Ai\StubText;
use Arche\Host\HostBreaks;
use Arche\Program\SubmissionWindow;

function listener(Arche\App $app): array
{
    [$d, $s] = device();
    return $app->identities()->resolve($d, $s, true);
}

function runJobs(Arche\App $app, int $rounds = 6): void
{
    for ($i = 0; $i < $rounds; $i++) $app->runner()->runUntilBudget();
}

test('jobs: breaks at the end of the plan do not keep a listener waiting', function () {
    $app = TestKit::app();
    $jobs = $app->jobs();
    $now = $app->clock->nowMs();
    for ($i = 1; $i <= 20; $i++) $jobs->enqueue('host', $i, 10, $now + Arche\Program\Timing::DRAFT);
    $jobs->enqueue('moderate', 1, 30, $now);
    $jobs->enqueue('host', 99, 20, $now + Arche\Program\Timing::COMMIT + 60_000);
    $first = $jobs->lease();
    eq([$first['type'], (int) $first['ref_id']], ['host', 99], 'a break that must be voiced before its commit goes first');
    eq($jobs->lease()['type'], 'moderate', 'then the submission a listener waits for');
    eq($jobs->lease()['type'], 'host', 'then the breaks just drafted at the end of the plan');
});

test('submissions: a song request is checked, approved, graduates and airs announced', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $app->tick()->run('test');
    $me = listener($app);
    $ch = TestKit::main($app);
    $sub = $app->submissions()->submitSong($me, $ch, ['url' => 'https://youtu.be/AbCdEfGhIjK', 'message' => 'For my mum, happy birthday!', 'name' => 'Jenny', 'place' => 'Munich']);
    eq($sub['status'], 'pending', 'pending while checked');
    runJobs($app);
    $row = $app->submissions()->byPublicId($sub['id']);
    eq($row['status'], 'approved', 'approved by the stub moderator');
    $lib = $app->library()->byYouTube('AbCdEfGhIjK');
    check($lib !== null && $lib['source'] === 'submission', 'graduated into the library');
    check(!str_contains(json_encode($lib), 'birthday'), 'the dedication is not a library tag');
    for ($i = 0; $i < 50; $i++) {
        $app->tick()->run('test');
        TestKit::clock($app)->advance(60_000);
    }
    $items = TestKit::committed($app);
    $song = array_values(array_filter($items, fn($i) => $i['submission_id'] === (int) $row['id'] && $i['type'] === 'song'))[0] ?? null;
    check($song !== null, 'the requested song aired');
    eq($song['payload']['request']['name'] ?? null, 'Jenny', 'with the requester on the card');
    $announce = array_values(array_filter($items, fn($i) => $i['type'] === 'host' && ($i['payload']['kind'] ?? '') === 'announce'));
    check(count($announce) === 1 && $announce[0]['start_ms'] + $announce[0]['dur_ms'] === $song['start_ms'], 'announced right before the song');
    eq($app->submissions()->byPublicId($sub['id'])['status'], 'aired', 'marked aired');
});

test('submissions: rejection is generic, uncertain fails closed, errors fail closed', function () {
    $app = TestKit::app();
    $claude = $app->text();
    check($claude instanceof StubText, 'stub');
    $me = listener($app);
    $ch = TestKit::main($app);

    $claude->respond('moderate_song', fn() => ['safe' => true, 'christian' => false, 'program_fit' => true, 'message_ok' => true, 'verdict' => 'reject', 'themes' => [], 'moods' => [], 'languages' => []]);
    $a = $app->submissions()->submitSong($me, $ch, ['url' => 'https://www.youtube.com/watch?v=Zz9Zz9Zz9Zz']);
    runJobs($app);
    $view = $app->submissions()->publicView($app->submissions()->byPublicId($a['id']));
    eq([$view['status'], $view['reason']], ['rejected', 'not_suitable'], 'generic reason');

    $claude->respond('moderate_song', fn() => ['safe' => true, 'christian' => true, 'program_fit' => true, 'message_ok' => true, 'verdict' => 'uncertain', 'themes' => [], 'moods' => [], 'languages' => []]);
    $b = $app->submissions()->submitSong($me, $ch, ['url' => 'https://youtu.be/Yy8Yy8Yy8Yy']);
    runJobs($app);
    eq($app->submissions()->byPublicId($b['id'])['status'], 'rejected', 'uncertain → not this time');

    $claude->respond('moderate_song', fn() => throw new RuntimeException('network'));
    $c = $app->submissions()->submitSong($me, $ch, ['url' => 'https://youtu.be/Xx7Xx7Xx7Xx']);
    runJobs($app, 10);
    eq($app->submissions()->byPublicId($c['id'])['status'], 'rejected', 'repeated errors → rejected, never aired unchecked');

    check(refuses(fn() => $app->submissions()->submitSong($me, $ch, ['url' => 'https://youtu.be/Ww6Ww6Ww6Ww']), 'rate_limited'), 'rate limited after three an hour');
});

test('submissions: kept 90 days as the privacy policy says, then deleted with their recording unless it stays for replays', function () {
    $app = TestKit::app();
    $store = $app->store();
    $me = listener($app);
    $ch = TestKit::main($app);
    $prayer = $app->submissions()->submitPrayer($me, $ch, ['text' => 'Please pray for my brother.', 'name' => 'Lea', 'place' => 'Köln', 'consent_air' => true]);
    $plain = $app->media()->put('contrib', 'plainrec0001.mp3', 'aired once');
    $kept = $app->media()->put('contrib', 'keptrec00001.mp3', 'replays allowed');
    $recording = fn(string $audio, int $replay) => $store->insert('submissions', [
        'public_id' => Arche\Support\Ids::short(12), 'identity_id' => (int) $me['id'], 'channel_id' => (int) $ch['id'], 'program_id' => 1,
        'type' => 'story', 'mode' => 'audio', 'status' => 'aired', 'name' => 'Esther', 'place' => 'Nairobi', 'audio' => $audio,
        'consent_air' => 1, 'consent_replay' => $replay, 'created' => $app->clock->now(), 'updated' => $app->clock->now(),
    ]);
    $recording($plain, 0);
    $keptId = $recording($kept, 1);
    $libraryId = $store->insert('library_items', ['kind' => 'contrib', 'audio' => $kept, 'title' => 'Esther, Nairobi', 'duration_ms' => 20_000,
        'submission_id' => $keptId, 'source' => 'submission', 'created' => 1, 'updated' => 1]);
    $store->query("INSERT INTO highlights(uid, channel, sub, name, text, at, created, updated) VALUES('h1', 'main', 's', 'Anna', 'Amen', 0, ?, ?)", [$app->clock->now(), $app->clock->now()]);
    $store->query("INSERT INTO chat_reports(msg, text, author, reporter, at, created) VALUES('m1', 'x', 'a', 'b', 0, ?)", [$app->clock->now()]);

    TestKit::clock($app)->advance(89 * 86400 * 1000);
    $app->tick()->run('test');
    check($app->submissions()->byPublicId($prayer['id']) !== null, 'still there after 89 days');
    eq((int) $store->value('SELECT COUNT(*) FROM highlights'), 0, 'community voices from chat go after 7 days');
    eq((int) $store->value('SELECT COUNT(*) FROM chat_reports'), 0, 'chat reports go after 30 days');

    TestKit::clock($app)->advance(2 * 86400 * 1000);
    $app->tick()->run('test');
    eq((int) $store->value('SELECT COUNT(*) FROM submissions'), 0, 'every submission is gone after 90 days');
    check(!is_file((string) $app->media()->path($plain)), 'a recording aired once is deleted with it');
    check(is_file((string) $app->media()->path($kept)), 'one the listener allowed to be replayed stays in the library');
    eq($store->value('SELECT submission_id FROM library_items WHERE id = ?', [$libraryId]), null, 'the library no longer points at the deleted row');
});

test('submissions: a message that fails its check rejects the whole request', function () {
    $app = TestKit::app();
    $app->text()->respond('moderate_song', fn() => ['safe' => true, 'christian' => true, 'program_fit' => true, 'message_ok' => false, 'verdict' => 'reject', 'themes' => [], 'moods' => [], 'languages' => []]);
    $sub = $app->submissions()->submitSong(listener($app), TestKit::main($app), ['url' => 'https://youtu.be/Vv5Vv5Vv5Vv', 'message' => 'call me 0151 1234567']);
    runJobs($app);
    eq($app->submissions()->byPublicId($sub['id'])['status'], 'rejected', 'rejected, not downgraded to silent');
});

test('submissions: a recording is transcribed, published only after approval, and aired with its intro', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $app->tick()->run('test');
    $tmp = tempnam(sys_get_temp_dir(), 'rec');
    copy(dirname(__DIR__, 2) . '/resources/stub-voice.mp3', $tmp);
    $sub = $app->submissions()->submitAudio(listener($app), TestKit::main($app), ['type' => 'story', 'name' => 'Lena', 'place' => 'Brazil', 'consent_air' => '1'], $tmp);
    check(!glob($app->publicPath('media/contrib/*.mp3')), 'nothing public before approval');
    runJobs($app);
    $row = $app->submissions()->byPublicId($sub['id']);
    eq($row['status'], 'approved', 'approved');
    check(str_contains($row['transcript'], 'God'), 'transcribed');
    check(is_file($app->publicPath(ltrim((string) $row['audio'], '/'))), 'published to /media/contrib');
    for ($i = 0; $i < 50; $i++) {
        $app->tick()->run('test');
        TestKit::clock($app)->advance(60_000);
    }
    $contrib = array_values(array_filter(TestKit::committed($app), fn($i) => $i['type'] === 'contrib'));
    check(count($contrib) === 1, 'aired once');
    check(($contrib[0]['payload']['caption']['de'] ?? '') !== '', 'with a German caption');
});

test('submissions: typed prayer requests are read out word for word, then the host invites everyone to pray — it never prays itself', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $app->tick()->run('test');
    $shown = $app->submissions()->submitPrayer(listener($app), TestKit::main($app), ['text' => 'Please pray for my mother in hospital.', 'name' => 'Maria', 'place' => 'Germany', 'consent_air' => '1']);
    $named = $app->submissions()->submitPrayer(listener($app), TestKit::main($app), ['text' => 'Pray for my new job, please.', 'name' => 'Tom', 'place' => 'Berlin', 'lang' => 'de']);
    runJobs($app);
    eq($app->submissions()->byPublicId($shown['id'])['status'], 'approved', 'approved');
    eq(array_column($app->submissions()->wall('main'), 'id'), ['p' . $shown['id']], 'the one with the tick is shown on the prayer wall');
    for ($i = 0; $i < 50; $i++) {
        $app->tick()->run('test');
        TestKit::clock($app)->advance(60_000);
    }
    $items = TestKit::committed($app);
    $readings = array_values(array_filter($items, fn($i) => $i['type'] === 'host' && ($i['payload']['kind'] ?? '') === 'reading'));
    eq(count($readings), 2, 'each request read out once');
    $byId = [];
    foreach ($readings as $r) $byId[hostContext($app, $r)['prayer_ids'][0] ?? 0] = $r;
    $idShown = (int) $app->submissions()->byPublicId($shown['id'])['id'];
    $idNamed = (int) $app->submissions()->byPublicId($named['id'])['id'];
    $wallText = (string) ($byId[$idShown]['payload']['text']['en'] ?? '');
    check(str_ends_with($wallText, ' Please pray for my mother in hospital.') && str_contains($wallText, 'Maria from Germany'),
        'the wall\'s request word for word, with the first name and place its sender gave (the wall itself shows none): ' . $wallText);
    eq(array_keys($byId[$idShown]['payload']['text']), ['en'], 'in the language it was written in only (the stub check says English)');
    $namedText = (string) ($byId[$idNamed]['payload']['text']['en'] ?? '');
    check(str_contains($namedText, 'Tom from Berlin') && str_ends_with($namedText, ' Pray for my new job, please.'), 'the other one with first name and place: ' . $namedText);
    eq($byId[$idShown]['payload']['prayers'], ['p' . $shown['id']], 'the app marks the wall\'s request as the one on air');
    $hb = $app->hostBreaks()->get((int) $byId[$idShown]['host_break_id']) ?? [];
    eq($hb['source'], 'listener', 'no model wrote it');
    check(!isset($hb['context']['prayers']), 'and nobody\'s words were copied into its context');
    $last = end($readings);
    $after = array_values(array_filter($items, fn($i) => $i['start_ms'] === $last['start_ms'] + $last['dur_ms']))[0] ?? null;
    eq([$after['type'] ?? null, $after['payload']['kind'] ?? null], ['host', 'prayer'], 'then the host invites everyone to pray');
    eq(hostContext($app, $after)['requests'] ?? null, 2, 'for both — knowing only how many');
    check($last['dur_ms'] >= 4_000 + 2_000, 'a few seconds of quiet after a reading, inside its item');
    $row = $app->submissions()->byPublicId($shown['id']);
    eq([$row['status'], (int) $row['aired_at']], ['aired', $byId[$idShown]['start_ms']], 'marked aired, at the start of its reading');
    eq($app->submissions()->publicView($row)['airedAt'], $byId[$idShown]['start_ms'], 'and the sender sees when');
});

/**
 * Drafts minute by minute until a reading takes the request, before any job
 * runs for it. @return int the reading's host break id
 */
function readingFor(Arche\App $app, int $submissionId): int
{
    for ($i = 0; $i < 30; $i++) {
        TestKit::clock($app)->advance(60_000);
        $app->drafter()->draft(TestKit::main($app));
        foreach ($app->store()->all("SELECT id, context FROM host_breaks WHERE kind IN ('reading', 'intercession') AND state = 'pending'") as $h) {
            if (in_array($submissionId, json_decode((string) $h['context'], true)['prayer_ids'] ?? [], true)) return (int) $h['id'];
        }
        $app->tick()->run('test');
    }
    throw new RuntimeException('no reading took the request');
}

test('submissions: a reading that is not voiced in time gives its request to a later one', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $app->tick()->run('test');
    $sub = $app->submissions()->submitPrayer(listener($app), TestKit::main($app), ['text' => 'Please pray for my brother in hospital.', 'name' => 'Ana', 'place' => 'Porto']);
    runJobs($app);
    $id = (int) $app->submissions()->byPublicId($sub['id'])['id'];
    $stuck = readingFor($app, $id);
    eq($app->submissions()->get($id)['status'], 'scheduled', 'taken into the plan');
    // Its voice never comes: the job is stuck.
    $app->store()->query("UPDATE jobs SET status = 'done' WHERE type = 'host' AND ref_id = ?", [$stuck]);
    ticks($app, 30);
    eq($app->store()->value('SELECT state FROM timeline_items WHERE host_break_id = ?', [$stuck]), 'dropped', 'the late reading went, the music went on');
    $read = array_values(array_filter(TestKit::committed($app), fn($i) => $i['type'] === 'host' && in_array($id, hostContext($app, $i)['prayer_ids'] ?? [], true)));
    eq(count($read), 1, 'a later reading read the request, once');
    eq([$app->submissions()->get($id)['status'], (int) $app->submissions()->get($id)['aired_at']], ['aired', $read[0]['start_ms']], 'and it aired then');
});

test('submissions: a plan change gives the requests of drafted readings back', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $app->tick()->run('test');
    $sub = $app->submissions()->submitPrayer(listener($app), TestKit::main($app), ['text' => 'Please pray for peace in our town.', 'name' => 'Lea', 'place' => 'Kiel']);
    runJobs($app);
    $id = (int) $app->submissions()->byPublicId($sub['id'])['id'];
    $hb = readingFor($app, $id);
    $app->timeline()->discardDrafts((int) TestKit::main($app)['id']);
    eq($app->hostBreaks()->get($hb)['state'], 'cancelled', 'its reading is cancelled');
    eq($app->submissions()->get($id)['status'], 'approved', 'the request waits again');
    ticks($app, 20);
    eq($app->submissions()->get($id)['status'], 'aired', 'and a new reading reads it');
});

test('submissions: requests read together stay short, and one taken off the wall is not read out', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $app->tick()->run('test');
    $ch = TestKit::main($app);
    $long = fn(string $n) => $app->submissions()->submitPrayer(listener($app), $ch, ['text' => str_repeat("Please pray for $n. ", 18), 'name' => $n, 'consent_air' => '1']);
    $a = $long('Ada');
    $b = $long('Bea');
    $hidden = $app->submissions()->submitPrayer(listener($app), $ch, ['text' => 'Please pray for Cleo.', 'name' => 'Cleo', 'consent_air' => '1']);
    runJobs($app);
    $app->submissions()->setHidden($hidden['id'], true);
    $idA = (int) $app->submissions()->byPublicId($a['id'])['id'];
    readingFor($app, $idA);
    $taken = $app->store()->all("SELECT context FROM host_breaks WHERE kind = 'reading'");
    eq(count($taken), 1, 'two of almost 400 characters are more than one break should read: the second waits for the next');
    ticks($app, 40);
    eq([$app->submissions()->byPublicId($a['id'])['status'], $app->submissions()->byPublicId($b['id'])['status']], ['aired', 'aired'], 'both were read');
    eq($app->submissions()->byPublicId($hidden['id'])['status'], 'approved', 'the one taken off the wall was never taken');

    // Taken off while its reading waits in the plan: the reading goes, and the request waits again.
    $d = $app->submissions()->submitPrayer(listener($app), $ch, ['text' => 'Please pray for Dina.', 'name' => 'Dina', 'consent_air' => '1']);
    runJobs($app);
    $idD = (int) $app->submissions()->byPublicId($d['id'])['id'];
    $reading = readingFor($app, $idD);
    $app->submissions()->setHidden($d['id'], true);
    eq([$app->hostBreaks()->get($reading)['state'], $app->submissions()->get($idD)['status']], ['cancelled', 'approved'], 'its planned reading went and gave it back, never left "scheduled"');
});

test('submissions: waiting typed prayers count towards a full queue', function () {
    $app = TestKit::app();
    $ch = TestKit::main($app);
    $pid = (int) $ch['fallback_program_id'];
    $app->catalog()->saveProgram($pid, (int) $ch['id'], ['settings' => ['max_queue_min' => 5]], 'test');
    $program = $app->catalog()->program($pid) ?? [];
    $block = $app->resolver()->blockAt($ch, TestKit::T0);
    $state = fn() => SubmissionWindow::states($app, $ch, $program, $block, TestKit::T0)['prayer'] ?? null;
    eq($state(), 'open', 'open with nothing waiting');
    // Each is read out by its length: 300 characters take about 27 seconds with the pause after it.
    $long = fn() => prayerRow($app, $ch, ['text' => str_repeat('Pray for us. ', 23) . 'Amen.']);
    for ($i = 0; $i < 9; $i++) $long();
    eq($state(), 'closing', 'nine long ones waiting fill most of five minutes');
    for ($i = 0; $i < 2; $i++) $long();
    eq($state(), 'closed', 'eleven fill them');
});

test('submissions: the prayers the old code left "scheduled" are repaired once', function () {
    $app = TestKit::app();
    $ch = TestKit::main($app);
    $cid = (int) $ch['id'];
    $store = $app->store();
    $stuck = fn(string $text) => (int) $app->submissions()->byPublicId(prayerRow($app, $ch, ['status' => 'scheduled', 'text' => $text]))['id'];
    $aired = $stuck('Pray for my mother.');
    $missed = $stuck('Pray for my father.');
    $planned = $stuck('Pray for my sister.');
    $legacy = $stuck('Pray for my brother.');
    $now = $app->clock->nowMs();
    $item = function (string $state, int $start, array $context) use ($app, $cid) {
        $hb = $app->store()->insert('host_breaks', ['channel_id' => $cid, 'kind' => 'prayer', 'state' => $state === 'committed' ? 'ready' : 'pending',
            'context' => json_encode($context), 'created' => 1, 'updated' => 1]);
        $app->timeline()->addDraft($cid, ['type' => 'host', 'dur_ms' => 25_000, 'est_start' => $start, 'block_start' => 0, 'block_end' => $start + 3_600_000,
            'host_break_id' => $hb, 'payload' => ['kind' => 'prayer']]);
        $row = $app->store()->one('SELECT id FROM timeline_items WHERE host_break_id = ?', [$hb]);
        if ($state === 'committed') $app->timeline()->commit((int) $row['id'], $start, 25_000);
        return $hb;
    };
    // Written with the old code: the script replaced the ids with the texts.
    $item('committed', $now - 3_600_000, ['prayers' => [['name' => 'Ruth', 'place' => 'Lagos', 'text' => 'Pray for my mother.']]]);
    $draft = $item('draft', $now + 400_000, ['prayers' => [['name' => 'Ruth', 'place' => 'Lagos', 'text' => 'Pray for my sister.']]]);
    $item('draft', $now + 300_000, ['prayers' => [$legacy]]);
    $store->set('schema', 3);
    Arche\Schema::migrate($store, $now, 4);

    eq([$app->submissions()->get($aired)['status'], (int) $app->submissions()->get($aired)['aired_at']], ['scheduled', $now - 3_600_000], 'the one that aired gets its time (and turns "aired" with the next tick)');
    eq($app->submissions()->get($missed)['status'], 'approved', 'the one whose break never aired waits again');
    eq($app->hostBreaks()->get($draft)['context']['prayer_ids'] ?? null, [$planned], 'a break still in the plan gets its ids back');
    eq($app->submissions()->get($legacy)['status'], 'scheduled', 'a break not yet written keeps its ids where they were');
    eq(HostBreaks::prayerIds($app->hostBreaks()->get($draft)), [$planned], 'and both are read as the break\'s requests');
    $app->submissions()->markAired();
    eq($app->submissions()->get($aired)['status'], 'aired', 'aired');
});

test('submissions: the host\'s scripts go with the timeline after 30 days', function () {
    $app = TestKit::app();
    $cid = (int) TestKit::main($app)['id'];
    $old = $app->clock->now() - 31 * 86400;
    $gone = $app->store()->insert('host_breaks', ['channel_id' => $cid, 'kind' => 'prayer', 'state' => 'ready', 'context' => '{"prayers":[{"name":"Ruth"}]}', 'created' => $old, 'updated' => $old]);
    $kept = $app->store()->insert('host_breaks', ['channel_id' => $cid, 'kind' => 'break', 'state' => 'ready', 'created' => $old, 'updated' => $old]);
    $app->timeline()->addDraft($cid, ['type' => 'host', 'dur_ms' => 25_000, 'est_start' => $app->clock->nowMs(), 'block_start' => 0, 'block_end' => 0, 'host_break_id' => $kept]);
    $recent = $app->store()->insert('host_breaks', ['channel_id' => $cid, 'kind' => 'break', 'state' => 'ready', 'created' => $app->clock->now(), 'updated' => $app->clock->now()]);
    $app->tick()->run('test');
    eq([$app->hostBreaks()->get($gone), $app->hostBreaks()->get($kept) !== null, $app->hostBreaks()->get($recent) !== null], [null, true, true],
        'an old script nothing points at is deleted; one the timeline still uses, and a recent one, stay');
});

test('submissions: intake follows the program: types it does not allow are refused', function () {
    $app = TestKit::app();
    $ch = TestKit::main($app);
    $app->catalog()->saveProgram((int) $ch['fallback_program_id'], (int) $ch['id'], ['allowed' => ['song']], 'test');
    check(refuses(fn() => $app->submissions()->submitPrayer(listener($app), TestKit::main($app), ['text' => 'Pray for us please']), 'not_accepted_now'), 'prayer refused');
});

/** An admin's device headers (claimed passphrase + the setup key). */
function modHeaders(Arche\App $app): array
{
    $h = authHeaders();
    [$cid, $cs] = credential();
    call($app, 'POST', '/api/identity/claim', ['credId' => $cid, 'credSecret' => $cs], $h);
    eq(call($app, 'POST', '/api/setup/admin', ['key' => str_repeat('s', 40)], $h)[0], 200, 'admin');
    return $h;
}

/** @return array{0:int,1:array<string,mixed>} */
function modGet(Arche\App $app, string $path, array $query, array $h): array
{
    $res = (new Arche\Http\Kernel($app))->handle(new Arche\Http\Request('GET', $path, $query, $h, ''));
    return [$res->status, $res->data];
}

test('submissions: moderators see why a request was rejected and can overrule it', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $app->tick()->run('test');
    $me = listener($app);
    $app->text()->respond('moderate_song', fn() => ['safe' => true, 'christian' => true, 'program_fit' => false, 'message_ok' => true,
        'verdict' => 'reject', 'themes' => ['worship'], 'moods' => ['upbeat'], 'languages' => ['en'],
        'note' => 'An upbeat rock anthem does not fit a quiet prayer program.']);
    $sub = $app->submissions()->submitSong($me, TestKit::main($app), ['url' => 'https://youtu.be/GodNotDead1', 'message' => 'For my church', 'name' => 'Chris', 'place' => 'Lambsheim']);
    runJobs($app);
    eq($app->submissions()->publicView($app->submissions()->byPublicId($sub['id']))['reason'], 'not_program_fit', 'the listener sees the generic reason');
    check(!str_contains((string) json_encode($app->submissions()->forIdentity($me)), 'rock anthem'), 'and never the verdict');

    $h = modHeaders($app);
    eq(modGet($app, '/api/mod/review', [], $h)[1]['items'], [], 'nothing waits for review');
    [$st, $d] = modGet($app, '/api/mod/review', ['status' => 'rejected'], $h);
    $item = $d['items'][0] ?? [];
    eq([$st, $item['id'] ?? null, $item['status'] ?? null, $item['reason'] ?? null, array_key_exists('blocker', $item) ? $item['blocker'] : 'missing'], [200, $sub['id'], 'rejected', 'not_program_fit', null], 'listed, and it can be overruled');
    eq([$item['verdict']['program_fit'], $item['verdict']['type_allowed'], $item['verdict']['note']], [false, true, 'An upbeat rock anthem does not fit a quiet prayer program.'], 'with the exact reason');
    check(($item['video']['title'] ?? '') !== '', 'and which song it was');
    eq(count(modGet($app, '/api/mod/review', ['status' => 'all'], $h)[1]['items']), 1, 'all submissions');
    eq(call($app, 'GET', '/api/mod/review', [], authHeaders())[0], 403, 'not for listeners');

    [$st] = call($app, 'POST', '/api/mod/review/' . $sub['id'], ['decision' => 'approve', 'keepMessage' => false], $h);
    eq($st, 200, 'overruled');
    $row = $app->submissions()->byPublicId($sub['id']);
    eq([$row['status'], $row['message']], ['approved', ''], 'approved, and the dedication is not read out');
    check($app->library()->byYouTube('GodNotDead1') !== null, 'in the library');
    check(in_array('Rejection overruled', array_column($app->store()->all('SELECT event FROM audit'), 'event'), true), 'audited');
    eq(call($app, 'POST', '/api/mod/review/' . $sub['id'], ['decision' => 'approve'], $h)[0], 409, 'once');
});

test('submissions: an unplayable video and a deleted recording cannot be overruled; the length limit can', function () {
    $app = TestKit::app();
    $fine = ['ok' => true, 'error' => '', 'embeddable' => true, 'public' => true, 'live' => false, 'age_restricted' => false,
        'duration_ms' => 240_000, 'blocked' => [], 'allowed' => null];
    eq(Arche\Moderation\Moderator::videoProblems($fine, $app->config), [], 'a playable video');
    eq(Arche\Moderation\Moderator::videoProblems(['embeddable' => false, 'duration_ms' => 800_000, 'blocked' => ['DE']] + $fine, $app->config),
        ['not_embeddable', 'too_long', 'region'], 'every failed check by name');
    eq(Arche\Moderation\Moderator::videoProblems(['ok' => false, 'error' => 'not_found'] + $fine, $app->config), ['not_found'], 'gone');

    $me = listener($app);
    $app->text()->respond('moderate_song', fn() => ['safe' => true, 'christian' => false, 'program_fit' => true, 'message_ok' => true,
        'verdict' => 'reject', 'themes' => [], 'moods' => [], 'languages' => [], 'note' => 'Not a Christian song.']);
    $sub = $app->submissions()->submitSong($me, TestKit::main($app), ['url' => 'https://youtu.be/Qq1Qq1Qq1Qq']);
    runJobs($app);
    $subs = $app->submissions();
    $id = (int) $subs->byPublicId($sub['id'])['id'];
    $as = function (array $verdict) use ($app, $subs, $id): ?string {
        $app->store()->update('submissions', ['verdict' => json_encode($verdict)], 'id = ?', [$id]);
        return $subs->overruleBlocker($subs->get($id) ?? []);
    };
    eq($as(['christian' => false]), null, 'the classifier can be overruled');
    eq($as(['video' => ['not_embeddable', 'too_long']]), 'video_unplayable', 'a video that would not play cannot');
    eq($as(['video' => ['too_long'], 'duration_ms' => 800_000]), null, 'the station\'s length limit can');
    eq($as(['video' => 'unplayable']), 'video_unplayable', 'older rows too');
    eq($subs->overruleBlocker(['status' => 'rejected', 'mode' => 'audio', 'type' => 'story', 'meta' => '{}', 'verdict' => '{}']), 'recording_deleted', 'a rejected recording is deleted');

    $as(['video' => ['region'], 'blocked_in' => ['DE']]);
    [$st, $d] = call($app, 'POST', '/api/mod/review/' . $sub['id'], ['decision' => 'approve'], modHeaders($app));
    eq([$st, $d['error'] ?? null], [409, 'video_unplayable'], 'the API refuses it');
});

test('submissions: the check judges the song, not the links in its video description', function () {
    $clean = Arche\Moderation\Moderator::uploaderText("Official Music Video\nListen: https://newsboys.lnk.to/gnd\n\nFollow: www.instagram.com/newsboys @newsboys · booking@newsboys.com\nAlbum: God's Not Dead");
    check(!preg_match('~https?://|www\.|@~', $clean), 'no link, handle or address left');
    check(str_contains($clean, 'Official Music Video') && str_contains($clean, "Album: God's Not Dead"), 'the words that name the song stay');

    $app = TestKit::app(['YOUTUBE_API_KEY' => 'test-key']);
    $http = new FakeHttp();
    $app->set('http', $http);
    $http->answers[] = new Arche\Support\HttpResponse(200, (string) json_encode(['items' => [[
        'id' => 'S_OTz-lpDjw',
        'snippet' => [
            'title' => "Newsboys - God's Not Dead (Official Music Video)", 'channelTitle' => 'Newsboys', 'liveBroadcastContent' => 'none', 'tags' => ['newsboys'],
            'description' => "Official Music Video for God's Not Dead\nStream: https://newsboys.lnk.to/gnd\nFollow Newsboys: https://www.instagram.com/newsboys @newsboys\nDonate: www.example.org · info@newsboys.com",
        ],
        'contentDetails' => ['duration' => 'PT4M30S'],
        'status' => ['embeddable' => true, 'privacyStatus' => 'public', 'uploadStatus' => 'processed'],
    ]]]));
    $seen = ['', ''];
    $app->text()->respond('moderate_song', function (string $system, string $user) use (&$seen) {
        $seen = [$system, $user];
        return ['safe' => true, 'christian' => true, 'program_fit' => true, 'message_ok' => true, 'verdict' => 'approve',
            'themes' => ['worship'], 'moods' => ['upbeat'], 'languages' => ['en'], 'note' => 'A well-known worship song.'];
    });
    $sub = $app->submissions()->submitSong(listener($app), TestKit::main($app), ['url' => 'https://youtu.be/S_OTz-lpDjw']);
    runJobs($app);
    eq($app->submissions()->byPublicId($sub['id'])['status'], 'approved', 'approved');
    check(str_contains($seen[1], "God's Not Dead"), 'the check sees which song it is');
    check(!preg_match('~https?://|www\.|@~', $seen[1]), 'but none of the links, handles or addresses');
    check(str_contains($seen[0], "uploader's text"), 'and the rules say whose text the description is');
});

test('submissions: a listener\'s prayer — written or spoken — needs the consent to air it, has limits of its own, is never on the wall, and shows in My submissions', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    prayerHour($app, 735);
    ticks($app, 30);
    $ch = TestKit::main($app);
    $subs = $app->submissions();
    $me = listener($app);
    check(refuses(fn() => $subs->submitIntercession($me, $ch, ['text' => 'Lord, be with Anna.', 'name' => 'Ben']), 'consent_required'), 'only with the yes to air it word for word');
    check(refuses(fn() => $subs->submitIntercession($me, $ch, ['text' => str_repeat('a', 401), 'consent_air' => '1']), 'too_long'), '400 characters at most');
    $written = $subs->submitIntercession($me, $ch, ['text' => 'Lord, be with Anna and her family in these days.', 'name' => 'Ben', 'place' => 'Ulm', 'consent_air' => '1']);
    eq([$written['type'], $written['status'], $written['title']], ['intercession', 'pending', 'Lord, be with Anna and her family in these days.'], 'checked first, and the sender sees what they wrote');
    for ($i = 0; $i < 3; $i++) $subs->submitIntercession($me, $ch, ['text' => "Lord, hear us ($i).", 'consent_air' => '1']);
    check(refuses(fn() => $subs->submitIntercession($me, $ch, ['text' => 'Lord, once more.', 'consent_air' => '1']), 'rate_limited'), 'four an hour');

    $file = function (float $seconds): string {
        $tmp = tempnam(sys_get_temp_dir(), 'rec');
        copy(silentMp3($seconds), $tmp);
        return $tmp;
    };
    check(refuses(fn() => $subs->submitAudio($me, $ch, ['type' => 'intercession', 'consent_air' => '1'], $file(63)), 'invalid_audio'), 'a spoken prayer is a minute at most');
    $spoken = $subs->submitAudio($me, $ch, ['type' => 'intercession', 'name' => 'Ben', 'consent_air' => '1', 'consent_replay' => '1'], $file(30));
    eq((int) $subs->byPublicId($spoken['id'])['consent_replay'], 0, 'a prayer is for its hour: never kept for replays');
    $subs->submitAudio($me, $ch, ['type' => 'prayer', 'consent_air' => '1'], $file(20));
    // The one refused for its length counted too: the limit comes before the file is opened.
    $subs->submitAudio($me, $ch, ['type' => 'intercession', 'consent_air' => '1'], $file(20));
    check(refuses(fn() => $subs->submitAudio($me, $ch, ['type' => 'intercession', 'consent_air' => '1'], $file(20)), 'rate_limited'), 'three spoken prayers an hour, besides a recorded request');
    runJobs($app);
    eq($subs->byPublicId($written['id'])['status'], 'approved', 'approved by the check');
    $kinds = array_column($app->text()->calls, 'kind');
    check(in_array('moderate_intercession', $kinds, true), 'judged as a prayer');
    check(!in_array('p' . $written['id'], array_column($subs->wall('main'), 'id'), true), 'never on the prayer wall');
});

<?php
declare(strict_types=1);

use Arche\Ai\StubText;

function listener(Arche\App $app): array
{
    [$d, $s] = device();
    return $app->identities()->resolve($d, $s, true);
}

function runJobs(Arche\App $app, int $rounds = 6): void
{
    for ($i = 0; $i < $rounds; $i++) $app->runner()->runUntilBudget();
}

test('jobs: host breaks planned far ahead do not keep a listener waiting', function () {
    $app = TestKit::app();
    $jobs = $app->jobs();
    $now = $app->clock->nowMs();
    for ($i = 1; $i <= 20; $i++) $jobs->enqueue('host', $i, 10, $now + 40 * 60_000);
    $jobs->enqueue('moderate', 1, 30, $now);
    $jobs->enqueue('host', 99, 20, $now + 10 * 60_000);
    $first = $jobs->lease();
    eq([$first['type'], (int) $first['ref_id']], ['host', 99], 'a break that must be voiced before its commit goes first');
    eq($jobs->lease()['type'], 'moderate', 'then the submission a listener waits for');
    eq($jobs->lease()['type'], 'host', 'then the breaks planned half an hour ahead');
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

test('submissions: text prayers are prayed for by the host and shown as voices', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $app->tick()->run('test');
    $sub = $app->submissions()->submitPrayer(listener($app), TestKit::main($app), ['text' => 'Please pray for my mother in hospital.', 'name' => 'Maria', 'place' => 'Germany', 'consent_air' => '1']);
    runJobs($app);
    eq($app->submissions()->byPublicId($sub['id'])['status'], 'approved', 'approved');
    check(count($app->presence()->voices('main')) === 1, 'shown as a community voice');
    for ($i = 0; $i < 50; $i++) {
        $app->tick()->run('test');
        TestKit::clock($app)->advance(60_000);
    }
    $prayer = array_values(array_filter(TestKit::committed($app), fn($i) => $i['type'] === 'host' && ($i['payload']['kind'] ?? '') === 'prayer'));
    check(count($prayer) >= 1, 'a prayer break aired');
});

test('submissions: intake follows the program: types it does not allow are refused', function () {
    $app = TestKit::app();
    $ch = TestKit::main($app);
    $app->catalog()->saveProgram((int) $ch['fallback_program_id'], (int) $ch['id'], ['allowed' => ['song']], 'test');
    check(refuses(fn() => $app->submissions()->submitPrayer(listener($app), TestKit::main($app), ['text' => 'Pray for us please']), 'not_accepted_now'), 'prayer refused');
});

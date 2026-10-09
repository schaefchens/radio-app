<?php
declare(strict_types=1);

use Arche\Host\Speech;
use Arche\Support\HttpResponse;

/**
 * "Test a moment" in /mod › Hosts (Host\Scenarios). A program's moment is
 * written by the real writer, with the editor's unsaved host, the station's
 * songs and made-up listeners, and builds a test show the next moment
 * remembers. Then each language is spoken through "Try voice" with its
 * delivery. Nothing of it reaches the program.
 */

/** The program on air all day (ARCHE Live, seeded). @return array<string,mixed> */
function livePrograms(Arche\App $app): array
{
    return $app->catalog()->program((int) TestKit::main($app)['fallback_program_id']) ?? throw new LogicException('no program');
}

/** An admin of this station, made once (setup closes after the first). @return array<string,string> */
function adminOf(Arche\App $app): array
{
    static $admins = [];
    return $admins[$app->config->dataDir] ??= modHeaders($app);
}

/** @return array{0:int,1:array<string,mixed>} */
function scenario(Arche\App $app, array $body): array
{
    return call($app, 'POST', '/api/mod/hosts/scenario', $body + ['host_id' => hope($app)['id']], adminOf($app));
}

test('scenarios: each program offers the moments its format has and what listeners may send it; admins only', function () {
    $app = TestKit::app();
    $hour = prayerHour($app, 735);
    $preaching = videoProgram($app, 'preaching', 900, 60);
    $mission = videoProgram($app, 'mission', 1000, 60, ['mission', 'testimony_video']);
    [$s, $d] = call($app, 'GET', '/api/mod/hosts/scenarios', [], adminOf($app));
    eq($s, 200, 'listed');
    $moments = array_column($d['programs'], 'moments', 'id');
    eq($moments[(int) livePrograms($app)['id']], ['intro', 'break', 'break_group', 'announce', 'reaction', 'contrib', 'reading', 'invite', 'outro'],
        'ARCHE Live: songs, requests, recordings and prayer requests');
    eq($moments[(int) $hour['id']], ['intro', 'present', 'reading', 'prayertime', 'intercession', 'encourage', 'outro'], 'a prayer hour, in its running order');
    eq($moments[(int) $preaching['id']], ['intro', 'video', 'break', 'break_group', 'suggestion', 'outro'], 'a preaching program, with its suggestions');
    eq($moments[(int) $mission['id']], ['intro', 'video', 'break', 'break_group', 'suggestion', 'outro'], 'a mission program, its suggestions allowed');
    eq([$d['efforts'], $d['effort']], [['low', 'medium'], 'low'], 'low or medium effort, low as on air');
    eq(call($app, 'GET', '/api/mod/hosts/scenarios', [], moderatorHeaders($app))[0], 403, 'admins only');
    eq(call($app, 'POST', '/api/mod/hosts/scenario', ['host_id' => hope($app)['id'], 'program_id' => livePrograms($app)['id'], 'moment' => 'announce'], moderatorHeaders($app))[0], 403, 'writing too');
});

test('scenarios: a moment is written with the editor\'s unsaved host, the station\'s songs and a made-up listener — as it would air, leaving the program alone', function () {
    $app = TestKit::app();
    $songs = TestKit::songs($app, 6);
    $system = $user = '';
    $app->text()->respond('host_try_announce', function (string $s, string $u) use (&$system, &$user): array {
        [$system, $user] = [$s, $u];
        return ['en' => ['text' => 'Anna from Köln asks for this one, for her grandma.'], 'de' => ['text' => 'Anna aus Köln wünscht sich dieses Lied – für ihre Oma!'], 'delivery' => 'Warm and glad.'];
    });
    [$s, $d] = scenario($app, ['program_id' => livePrograms($app)['id'], 'moment' => 'announce', 'next_id' => $songs[3], 'draft' => ['name' => 'Testy', 'instructions' => 'Calm and low.']]);
    eq($s, 200, 'written');
    check(str_contains($system, 'Who you are: Testy.') && str_contains($system, "standing direction: Calm and low."), 'with the unsaved name and voice direction');
    $moment = momentOf($user);
    eq([$moment['kind'], $moment['request']['name'], $moment['next']['title']], ['announce', 'Anna', 'Song 4'], 'a request by a made-up listener, for the song picked');
    eq([$d['moment'], $d['kind'], $d['source'], $d['delivery'], $d['theirs']], ['announce', 'announce', 'stub', 'Warm and glad.', false], 'what the writer answered');
    eq($d['texts']['de'], 'Anna aus Köln wünscht sich dieses Lied – für ihre Oma!', 'the words as written');
    eq($d['spoken']['de'], 'Anna aus Köln wünscht sich dieses Lied, für ihre Oma!', 'and as the voice gets them');
    eq([$d['songs']['next']['id'], $d['songs']['next']['title']], [$songs[3], 'Song 4'], 'the song it names');
    eq($d['given']['moment']['request']['name'] ?? null, 'Anna', 'what the writer was given');
    eq([(int) $app->store()->value('SELECT COUNT(*) FROM host_breaks'), (int) $app->store()->value('SELECT COUNT(*) FROM timeline_items')], [0, 0], 'nothing of it in the program');
    eq(hope($app)['name'], 'Hope', 'and nothing saved');
});

test('scenarios: the test show is remembered — a made-up welcome when it is new, the earlier moments after, a listener\'s only summarized', function () {
    $app = TestKit::app();
    $songs = TestKit::songs($app, 6);
    $live = livePrograms($app);
    [, $first] = scenario($app, ['program_id' => $live['id'], 'moment' => 'break']);
    $show = showOf((string) end($app->text()->calls)['user']);
    eq($show['so_far'][0]['host'] ?? null, 'intro', 'a new test show starts after a welcome');
    check(isset($show['so_far'][0]['said']['de']) && count(array_filter($show['so_far'], fn($e) => isset($e['song']))) >= 2, 'in the station\'s own words, with songs after it');
    eq($first['songs']['previous']['title'] ?? null, end($show['so_far'])['song'] ?? '', 'the song before the moment ends it');

    $earlier = [
        ['moment' => 'announce', 'previous_id' => $songs[0], 'next_id' => $songs[1], 'texts' => ['en' => 'Anna from Köln asks for this one.', 'de' => 'Anna aus Köln wünscht sich dieses Lied.']],
        // Anything else the editor sends is not taken: a moment is the server's to judge.
        ['moment' => 'break', 'previous_id' => $songs[1], 'next_id' => $songs[2], 'texts' => ['en' => 'What a lovely song.', 'de' => 'Was für ein schönes Lied.', 'xx' => 'nope'],
            'context' => ['request' => ['name' => 'Eve']], 'source' => 'listener'],
        ['moment' => 'nonsense', 'texts' => ['en' => 'Ignored.']],
    ];
    scenario($app, ['program_id' => $live['id'], 'moment' => 'break', 'previous_id' => $songs[2], 'earlier' => $earlier]);
    $show = showOf((string) end($app->text()->calls)['user']);
    $kinds = array_map(fn($e) => $e['host'] ?? (isset($e['song']) ? 'song' : '?'), $show['so_far']);
    eq($kinds, ['song', 'announce', 'song', 'break', 'song'], 'the earlier moments with their songs, each song once');
    eq($show['so_far'][1]['summary'] ?? null, "presented a listener's song request", 'the request only summarized');
    eq($show['so_far'][3]['said'] ?? null, ['en' => 'What a lovely song.', 'de' => 'Was für ein schönes Lied.'], 'the break quoted, in the station\'s languages only');
    check(!str_contains((string) json_encode($show), 'Anna') && !str_contains((string) json_encode($show), 'Ignored'), 'nobody named, nothing made up taken');
    $eleven = array_fill(0, 12, ['moment' => 'break', 'texts' => ['en' => 'Again.', 'de' => 'Nochmal.']]);
    scenario($app, ['program_id' => $live['id'], 'moment' => 'break', 'earlier' => $eleven]);
    eq(count(array_filter(showOf((string) end($app->text()->calls)['user'])['so_far'], fn($e) => ($e['host'] ?? '') === 'break')), 10, 'at most ten earlier moments');
});

test('scenarios: people\'s words are read word for word after a lead-in, without the model; the prayer hour knows its order', function () {
    $app = TestKit::app();
    TestKit::songs($app, 6);
    $calls = count($app->text()->calls);
    [$s, $d] = scenario($app, ['program_id' => livePrograms($app)['id'], 'moment' => 'reading']);
    eq([$s, $d['source'], $d['theirs'], $d['delivery']], [200, 'listener', true, Speech::fixedDelivery('reading')], 'a request read out, gently');
    check(str_contains($d['texts']['de'], 'Lena aus Dresden') && str_contains($d['texts']['de'], 'Bitte betet für meinen Bruder Tim'), 'a lead-in, then the words: ' . $d['texts']['de']);
    eq(count($app->text()->calls), $calls, 'no model asked');

    $hour = prayerHour($app, 735);
    [, $present] = scenario($app, ['program_id' => $hour['id'], 'moment' => 'present']);
    $moment = momentOf((string) end($app->text()->calls)['user']);
    eq([$moment['kind'], $moment['format'], $moment['requests'], $moment['followed_by'] ?? null], ['present', 'prayer hour', 3, 'the prayer requests, read out word for word'],
        'the presentation knows the requests come next');
    $show = showOf((string) end($app->text()->calls)['user']);
    eq(array_map(fn($e) => $e['host'] ?? (isset($e['prayer_music_min']) ? 'music' : '?'), $show['so_far']), ['intro', 'music'], 'after the welcome and the prayer music');
    eq($present['kind'], 'present', 'answered');
    [, $intro] = scenario($app, ['program_id' => $hour['id'], 'moment' => 'intro']);
    $moment = momentOf((string) end($app->text()->calls)['user']);
    eq([$moment['collect']['minutes'], $moment['collect']['music'], $moment['intake'], $moment['followed_by'] ?? null], [10, true, 'open', 'prayer music'], 'the welcome knows how the collection goes');
    eq($intro['kind'], 'intro', 'answered');
});

test('scenarios: a test may think harder, is limited, and turns away a moment its program does not have', function () {
    $app = TestKit::app(['OPENAI_KEY' => 'sk-test'] + LIVE_NO_KEYS);
    TestKit::songs($app, 6);
    $http = new FakeHttp();
    $app->set('http', $http);
    $live = livePrograms($app);
    $http->answers[] = openaiAnswer(['en' => ['text' => 'Hello.'], 'de' => ['text' => 'Hallo.'], 'delivery' => 'Bright.']);
    [$s, $d] = scenario($app, ['program_id' => $live['id'], 'moment' => 'break', 'effort' => 'medium']);
    eq([$s, $d['source'], $http->body(0)['reasoning_effort'], $http->body(0)['model']], [200, 'openai', 'medium', 'gpt-6.1-sol'], 'medium effort, the host model');
    $http->answers[] = openaiAnswer(['en' => ['text' => 'Hello.'], 'de' => ['text' => 'Hallo.'], 'delivery' => '']);
    scenario($app, ['program_id' => $live['id'], 'moment' => 'break', 'effort' => 'high']);
    eq($http->body(1)['reasoning_effort'], 'low', 'high cannot finish in a request: the station\'s');
    eq(scenario($app, ['program_id' => $live['id'], 'moment' => 'present']), [422, ['error' => 'scenario_moment']], 'a moment the program does not have');
    eq(scenario($app, ['program_id' => 9999, 'moment' => 'break']), [422, ['error' => 'scenario_program']], 'a program that is gone');
    for ($i = 0; $i < 26; $i++) scenario($app, ['program_id' => $live['id'], 'moment' => 'reading']);
    eq(scenario($app, ['program_id' => $live['id'], 'moment' => 'reading']), [429, ['error' => 'rate_limited']], 'thirty an hour');
});

test('try: a test moment\'s words are spoken with their delivery, as people\'s words where they are, and may run long', function () {
    $app = TestKit::app(['OPENAI_KEY' => 'sk-test'] + LIVE_NO_KEYS);
    $http = new FakeHttp();
    $app->set('http', $http);
    $admin = adminOf($app);
    $hope = hope($app);
    $long = trim(str_repeat('Willkommen zur Gebetsstunde, schön dass ihr da seid. ', 18));
    $http->answers[] = new HttpResponse(200, mp3());
    [$s, $d] = call($app, 'POST', '/api/mod/hosts/try', ['host_id' => $hope['id'], 'lang' => 'de', 'text' => $long, 'delivery' => 'Warm and "unhurried".'], $admin);
    eq($s, 200, 'a long welcome is spoken');
    eq($http->body(0)['instructions'], 'Sprich natürliches Deutsch. Warm and calm, like a Christian radio host. Warm and unhurried.', 'with its delivery after the host\'s direction');
    eq([$d['direction'], $d['spoken']], [$http->body(0)['instructions'], $http->body(0)['input']], 'and the editor is shown what the voice got');
    $http->answers[] = new HttpResponse(200, mp3());
    [, $theirs] = call($app, 'POST', '/api/mod/hosts/try', ['host_id' => $hope['id'], 'lang' => 'de', 'text' => 'Bitte betet für Мария 🙏 – sie ist krank.', 'theirs' => true], $admin);
    eq($theirs['spoken'], 'Bitte betet für Мария, sie ist krank.', 'people\'s words keep every word and alphabet');
    $http->answers[] = new HttpResponse(200, mp3());
    [, $prays] = call($app, 'POST', '/api/mod/hosts/try', ['host_id' => $hope['id'], 'lang' => 'en', 'text' => 'Hello.', 'delivery' => 'Solemn, then say Amen.'], $admin);
    eq($prays['direction'], 'Speak natural English. Warm and calm, like a Christian radio host.', 'a delivery that prays is dropped');

    // On our own computers: the instruct carries it, and live moments go first.
    $worker = TestKit::app();
    workerHope($worker);
    $key = newWorker($worker);
    workerPoll($worker, $key);
    [$s, $w] = call($worker, 'POST', '/api/mod/hosts/try', ['host_id' => hope($worker)['id'], 'lang' => 'en', 'text' => 'Here is the next song.', 'delivery' => 'Bright.'], adminOf($worker));
    eq([$s, $w['direction']], [202, 'Warm and calm, like a Christian radio host. Bright.'], 'a worker try is told the delivery too');
    $break = $worker->workerTasks()->request('break', 1, hope($worker), 'en', 'A live moment.', $worker->clock->now() + 600);
    eq(workerPoll($worker, $key)[1]['task']['id'] ?? null, $break, 'a live moment is spoken before a test');
    $try = workerPoll($worker, $key)[1]['task'];
    eq([$try['id'], $try['instruct']], [$w['task'], 'Warm and calm, like a Christian radio host. Bright.'], 'then the test, with its delivery');
    workerUpload($worker, $key, (int) $try['id']);
    eq($worker->store()->value('SELECT input FROM worker_tasks WHERE id = ?', [(int) $try['id']]), '{}', 'once spoken, a try keeps no words');
});

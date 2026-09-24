<?php
declare(strict_types=1);

use Arche\Program\SubmissionWindow;
use Arche\Program\Timing;

/** The listeners' requests on air: how soon, in which order, what the host says around them, and what happens when a program runs out of time. */

function ticks(Arche\App $app, int $minutes): void
{
    for ($i = 0; $i < $minutes; $i++) {
        $app->tick()->run('test');
        TestKit::clock($app)->advance(60_000);
    }
}

/** Another program for an hour from $minuteOfDay (Berlin, on T0's date): the one on air ends there. */
function programChangeAt(Arche\App $app, int $minuteOfDay): void
{
    $cat = $app->catalog();
    $cid = (int) TestKit::main($app)['id'];
    $next = $cat->saveProgram(null, $cid, ['slug' => 'evening', 'title_en' => 'Evening', 'title_de' => 'Abend', 'allowed' => ['song']], 'test');
    $plan = $cat->saveDayPlan(null, $cid, 'Today', [['start_min' => $minuteOfDay, 'end_min' => $minuteOfDay + 60, 'program_id' => $next['id']]], 'test');
    $cat->addSpecialDay($cid, ['name' => 'Today', 'kind' => 'date', 'month' => 9, 'day' => 23, 'day_plan_id' => $plan], 'test');
}

/** What the host was given to speak about for a committed host item. @param array<string,mixed> $item @return array<string,mixed> */
function hostContext(Arche\App $app, array $item): array
{
    return json_decode((string) $app->store()->value('SELECT context FROM host_breaks WHERE id = ?', [(int) $item['host_break_id']]), true) ?: [];
}

/** @return string public id */
function recording(Arche\App $app, string $name): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'rec');
    copy(dirname(__DIR__, 2) . '/resources/stub-voice.mp3', $tmp);
    return $app->submissions()->submitAudio(listener($app), TestKit::main($app), ['type' => 'story', 'name' => $name, 'place' => 'Bonn', 'consent_air' => '1'], $tmp)['id'];
}

test('requests: approved now, one airs about eight minutes later — announced by name without a dedication, the host reacting after it', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    ticks($app, 10);
    $sub = $app->submissions()->submitSong(listener($app), TestKit::main($app), ['url' => 'https://youtu.be/ReqNoMsg001', 'name' => 'Jonas', 'place' => 'Hamburg']);
    runJobs($app);
    $approved = $app->clock->nowMs();
    $row = $app->submissions()->byPublicId($sub['id']);
    eq($row['status'], 'approved', 'approved');
    ticks($app, 20);

    $items = TestKit::committed($app);
    $at = array_key_first(array_filter($items, fn($i) => $i['submission_id'] === (int) $row['id']));
    check($at !== null, 'it aired');
    [$announce, $song, $after] = [$items[$at - 1], $items[$at], $items[$at + 1] ?? []];
    eq([$announce['type'], $announce['payload']['kind'] ?? ''], ['host', 'announce'], 'announced, although there is no dedication');
    $wait = $announce['start_ms'] - $approved;
    check($wait >= Timing::DRAFT - 2 * 60_000 && $wait <= Timing::DRAFT + 5 * 60_000, 'about DRAFT after approval: ' . intdiv($wait, 1000) . ' s');
    eq(hostContext($app, $announce)['request']['name'] ?? '', 'Jonas', 'the announcement is about Jonas');
    check(!isset(hostContext($app, $announce)['previous_request']), 'with nothing before it to react to');
    eq([$after['type'] ?? '', $after['payload']['kind'] ?? ''], ['host', 'break'], 'the host speaks after it');
    $before = hostContext($app, $after)['previous_request'] ?? [];
    eq([$before['name'] ?? '', $before['song']['title'] ?? ''], ['Jonas', $song['payload']['title']], "and reacts to Jonas's request");
    eq($app->submissions()->byPublicId($sub['id'])['status'], 'aired', 'marked aired');
});

test('requests: waiting together they form one block of three in fitting order, the host bridging; the next block waits two songs', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    ticks($app, 5);
    $theme = ['Anna' => 'hope', 'Ben' => 'joy', 'Clara' => 'hope', 'David' => 'peace', 'Eva' => 'peace'];
    $app->text()->respond('moderate_song', fn(string $system, string $user) => ['safe' => true, 'christian' => true, 'program_fit' => true, 'message_ok' => true,
        'verdict' => 'approve', 'themes' => [preg_match('/"name": "(\w+)"/', $user, $m) ? $theme[$m[1]] : 'worship'], 'moods' => [], 'languages' => ['en'], 'note' => 'Fine.']);
    $ids = [];
    foreach (array_keys($theme) as $i => $name) {
        $pub = $app->submissions()->submitSong(listener($app), TestKit::main($app), ['url' => 'https://youtu.be/ReqBlock00' . $i, 'name' => $name, 'place' => 'Berlin']);
        $ids[(int) $app->submissions()->byPublicId($pub['id'])['id']] = $name;
    }
    runJobs($app);
    ticks($app, 50);

    $items = TestKit::committed($app);
    $order = [];
    foreach ($items as $i => $it) if (isset($ids[$it['submission_id'] ?? -1])) $order[$ids[$it['submission_id']]] = $i;
    eq(array_keys($order), ['Anna', 'Clara', 'Ben', 'David', 'Eva'], 'the three waiting longest first, the two about hope together');
    ['Anna' => $a, 'Clara' => $c, 'Ben' => $b, 'David' => $d] = $order;
    eq([$c - $a, $b - $c], [2, 2], 'one host moment between two requests');
    eq(hostContext($app, $items[$c - 1])['previous_request']['name'] ?? '', 'Anna', 'the host reacts to Anna before announcing Clara');
    eq(hostContext($app, $items[$c - 1])['request']['name'] ?? '', 'Clara', '…and then announces her');
    eq(hostContext($app, $items[$b - 1])['previous_request']['name'] ?? '', 'Clara', 'the same between Clara and Ben');
    eq([$items[$b + 1]['type'], $items[$b + 1]['payload']['kind'] ?? '', hostContext($app, $items[$b + 1])['previous_request']['name'] ?? ''], ['host', 'break', 'Ben'], 'a word after the last one');
    $regular = count(array_filter(array_slice($items, $b + 1, $d - $b - 1), fn($i) => $i['type'] === 'song' && $i['submission_id'] === null));
    check($regular >= 2, "at least two regular songs before the next block ($regular)");
    eq($order['Eva'] - $d, 2, 'David and Eva are the next block');
    foreach (array_keys($theme) as $name) eq($app->submissions()->publicView($app->submissions()->get((int) array_search($name, $ids, true)) ?? [])['status'], 'aired', "$name aired");
});

test('requests: approved too late for their program, a song goes to the music selection, a recording is told it missed and stays private', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    programChangeAt($app, 12 * 60 + 30); // T0 is 12:00 in Berlin: this program ends in 30 minutes
    ticks($app, 1);
    $song = $app->submissions()->submitSong(listener($app), TestKit::main($app), ['url' => 'https://youtu.be/ReqLate0001', 'name' => 'Lukas', 'place' => 'Ulm']);
    $story = recording($app, 'Mia');
    // Decided only when the program is almost over (the check was stuck, or a moderator was late).
    TestKit::clock($app)->advance(24 * 60_000);
    runJobs($app);

    $view = fn(string $id) => $app->submissions()->publicView($app->submissions()->byPublicId($id) ?? []);
    eq($view($song['id'])['status'], 'library', 'the song may play in a later program');
    check($app->library()->byYouTube('ReqLate0001') !== null, 'it is in the music selection');
    eq($view($story)['status'], 'missed', 'the recording missed its program');
    $row = $app->submissions()->byPublicId($story) ?? [];
    check($row['audio'] === null && !glob($app->publicPath('media/contrib/*.mp3')), 'and is never published');
    check(!glob($app->config->dataDir . '/uploads/*.mp3'), 'nor kept');
    ticks($app, 10);
    check(!array_filter(TestKit::committed($app), fn($i) => $i['submission_id'] === (int) $row['id']), 'nothing of it airs');
});

test('requests: those a busy program cannot place any more leave the queue when it ends; a program going on past midnight keeps them', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    programChangeAt($app, 12 * 60 + 30);
    ticks($app, 1);
    $subs = [];
    foreach (['Nora', 'Otto', 'Paula'] as $i => $name) {
        $subs[$name] = $app->submissions()->submitSong(listener($app), TestKit::main($app), ['url' => 'https://youtu.be/ReqBusy000' . $i, 'name' => $name, 'place' => 'Kiel'])['id'];
    }
    $subs['Quirin'] = recording($app, 'Quirin');
    $subs['Rosa'] = $app->submissions()->submitSong(listener($app), TestKit::main($app), ['url' => 'https://youtu.be/ReqBusy0004', 'name' => 'Rosa', 'place' => 'Kiel'])['id'];
    runJobs($app);
    $audio = (string) $app->submissions()->byPublicId($subs['Quirin'])['audio'];
    check($audio !== '' && is_file((string) $app->media()->path($audio)), 'approved in time: the recording is published');
    ticks($app, 40);

    $status = fn(string $name) => $app->submissions()->byPublicId($subs[$name])['status'];
    eq(array_map($status, ['Nora', 'Otto', 'Paula']), ['aired', 'aired', 'aired'], 'the first block aired');
    eq([$status('Quirin'), $status('Rosa')], ['missed', 'library'], 'the rest could not follow before the program ended');
    eq((int) $app->store()->value("SELECT COUNT(*) FROM submissions WHERE status = 'approved'"), 0, 'nothing waits for good');
    check(!is_file((string) $app->media()->path($audio)), 'the recording that never aired is no longer public');

    $night = TestKit::app([], strtotime('2026-09-23T20:30:00Z') * 1000); // 22:30 in Berlin
    TestKit::songs($night, 12);
    ticks($night, 1);
    $sub = $night->submissions()->submitSong(listener($night), TestKit::main($night), ['url' => 'https://youtu.be/ReqMidnight', 'name' => 'Sara', 'place' => 'Jena']);
    TestKit::clock($night)->advance(85 * 60_000); // 23:56: its program block ends at midnight, and starts again right then
    runJobs($night);
    eq($night->submissions()->byPublicId($sub['id'])['status'], 'approved', 'still for its program, which goes on after midnight');
    ticks($night, 20);
    eq($night->submissions()->byPublicId($sub['id'])['status'], 'aired', 'and it aired after midnight');
});

test('requests: intake closes 15 minutes before a program ends, last chance from 25; old saved defaults move along', function () {
    $app = TestKit::app();
    programChangeAt($app, 12 * 60 + 30);
    $ch = TestKit::main($app);
    $pid = (int) $ch['fallback_program_id'];
    $program = $app->catalog()->program($pid) ?? [];
    $block = $app->resolver()->blockAt($ch, TestKit::T0);
    $state = function (int $minutesLeft) use ($app, $ch, $program, $block): string {
        $s = SubmissionWindow::states($app, $ch, $program, $block, $block['end'] - $minutesLeft * 60_000);
        return is_array($s) ? $s['song'] : 'none';
    };
    eq([$state(26), $state(24), $state(16), $state(14)], ['open', 'closing', 'closing', 'closed'], 'open, last chance from 25, closed from 15');

    $saved = $app->catalog()->saveProgram($pid, (int) $ch['id'], ['settings' => ['closed_min' => 5]], 'test');
    eq($saved['settings']['closed_min'], intdiv(Timing::DRAFT + Timing::MIN_SONG, 60_000) + 1, 'never below what a request sent at the last moment needs');

    $app->store()->query('UPDATE programs SET settings = ? WHERE id = ?', [json_encode(['closing_min' => 35, 'closed_min' => 20, 'max_queue_min' => 45]), $pid]);
    $app->store()->set('schema', 1);
    $version = $app->catalog()->version();
    Arche\Schema::migrate($app->store(), $app->clock->nowMs());
    $s = json_decode((string) $app->store()->value('SELECT settings FROM programs WHERE id = ?', [$pid]), true);
    eq([$s['closing_min'], $s['closed_min'], $s['max_queue_min']], [25, 15, 45], 'the old defaults become the new ones; the rest stays');
    eq($app->catalog()->version(), $version + 1, 'and the drafts made the old way are planned again');
});

test('requests: a requested song pulled from air takes its announcement along; the request is marked missed', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    ticks($app, 3);
    $sub = $app->submissions()->submitSong(listener($app), TestKit::main($app), ['url' => 'https://youtu.be/ReqPulled01', 'name' => 'Tim', 'place' => 'Hof', 'message' => 'For my father']);
    runJobs($app);
    $row = $app->submissions()->byPublicId($sub['id']) ?? [];
    for ($i = 0; $i < 6 && $app->submissions()->get((int) $row['id'])['status'] !== 'scheduled'; $i++) ticks($app, 1);
    $unit = (string) $app->store()->value("SELECT unit FROM timeline_items WHERE submission_id = ? AND state = 'draft'", [(int) $row['id']]);
    check($unit !== '' && (int) $app->store()->value("SELECT COUNT(*) FROM timeline_items WHERE unit = ? AND state = 'draft'", [$unit]) === 2, 'planned: the announcement and the song');

    [$st] = call($app, 'POST', '/api/mod/library/' . $row['library_id'] . '/pull', [], modHeaders($app));
    eq($st, 200, 'pulled from air');
    eq((int) $app->store()->value("SELECT COUNT(*) FROM timeline_items WHERE unit = ? AND state = 'draft'", [$unit]), 0, 'the song and its announcement left the plan');
    eq($app->submissions()->publicView($app->submissions()->byPublicId($sub['id']) ?? [])['status'], 'missed', 'the request cannot air any more');
    ticks($app, 15);
    check(!array_filter(TestKit::committed($app), fn($i) => $i['unit'] === $unit), 'nothing of it aired');
    assertContiguous(TestKit::committed($app), 'the plan closed the gap');
});

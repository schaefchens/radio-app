<?php
declare(strict_types=1);

use Arche\Host\ShowLog;

/**
 * The show so far (Host\ShowLog): what played and what was said since the
 * program's run began, given to the writer before each moment. It names no
 * listener, it is never stored, and it keeps its start from one moment to
 * the next so a prompt cache can serve it again.
 */

/** The moment's data in a writer's message (after the show so far). @return array<string,mixed> */
function momentOf(string $user): array
{
    $at = strrpos($user, Arche\Host\HostWriter::MOMENT);
    return json_decode($at === false ? $user : substr($user, $at + strlen(Arche\Host\HostWriter::MOMENT)), true) ?: [];
}

/** The show so far in a writer's message ([] when it has none). @return array<string,mixed> */
function showOf(string $user): array
{
    if (!str_starts_with($user, 'The show so far')) return [];
    $start = strpos($user, "\n") + 1;
    return json_decode(substr($user, $start, strrpos($user, "\n\n" . Arche\Host\HostWriter::MOMENT) - $start), true) ?: [];
}

/** Every writer's message for these kinds, in order. @return list<string> */
function writerMessages(Arche\App $app, string ...$kinds): array
{
    return array_values(array_map(fn($c) => $c['user'], array_filter($app->text()->calls, fn($c) => in_array($c['kind'], $kinds, true))));
}

test('show: the writer is given the show so far — the songs and the words aired, oldest first — and never stores it', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $n = 0;
    $app->text()->respond('host_break', function () use (&$n): array {
        $n++;
        return ['en' => ['text' => "Moment $n."], 'de' => ['text' => "Augenblick $n."]];
    });
    ticks($app, 70);
    $messages = writerMessages($app, 'host_break');
    check(count($messages) >= 4, 'several breaks were written');
    $last = showOf(end($messages));
    $said = array_values(array_filter(array_map(fn($e) => $e['said']['en'] ?? null, $last['so_far'] ?? []), fn($t) => $t !== null && str_starts_with($t, 'Moment')));
    eq($said, array_map(fn($i) => "Moment $i.", range(1, count($messages) - 1)), 'every break before it, in order');
    check(in_array('Augenblick 1.', array_column(array_column($last['so_far'], 'said'), 'de'), true), 'in both languages');
    $songs = array_values(array_filter($last['so_far'], fn($e) => isset($e['song'])));
    check(count($songs) >= 4 && str_starts_with($songs[0]['song'], 'Song ') && str_starts_with($songs[0]['by'], 'Artist '), 'and the songs, with who sings them');
    check(preg_match('/^\d\d:\d\d$/', $songs[0]['at']) === 1 && preg_match('/^\d\d:\d\d$/', momentOf(end($messages))['now'] ?? '') === 1, 'at the station\'s times, the moment\'s own too');
    foreach ($app->store()->all('SELECT context FROM host_breaks') as $r) check(!str_contains((string) $r['context'], 'so_far'), 'never stored with a moment');

    // One moment's memory is the start of the next one's: the provider's prompt cache serves it again.
    for ($i = 1; $i < count($messages); $i++) {
        $a = array_values(array_filter(showOf($messages[$i - 1])['so_far'], fn($e) => empty($e['planned'])));
        $b = showOf($messages[$i])['so_far'];
        eq(array_slice($b, 0, count($a)), $a, "memory $i begins with memory " . ($i - 1));
    }
});

test('show: dropped and blocked items are not in it', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    ticks($app, 40);
    $hb = $app->hostBreaks()->get((int) $app->store()->value("SELECT MAX(id) FROM host_breaks WHERE kind = 'break'"));
    $count = fn() => count(array_filter($app->showLog()->forBreak($hb)['so_far'], fn($e) => isset($e['song'])));
    $before = $count();
    $songs = $app->store()->all("SELECT id FROM timeline_items WHERE type = 'song' AND state = 'committed' ORDER BY seq LIMIT 2");
    $app->store()->update('timeline_items', ['blocked' => 1], 'id = ?', [(int) $songs[0]['id']]);
    eq($count(), $before - 1, 'a song pulled from air is not');
    $app->store()->update('timeline_items', ['state' => 'dropped'], 'id = ?', [(int) $songs[1]['id']]);
    eq($count(), $before - 2, 'nor one dropped');
});

test('show: a program\'s intro is given the last items of the program before; only intro and outro are told what a program is about', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    programChangeAt($app, 12 * 60 + 30);
    $eveningId = (int) $app->store()->value("SELECT id FROM programs WHERE slug = 'evening'");
    $app->catalog()->saveProgram($eveningId, (int) TestKit::main($app)['id'], ['description_en' => 'Quiet songs for the evening.', 'description_de' => 'Ruhige Lieder für den Abend.'], 'test');
    ticks($app, 50);
    $intros = writerMessages($app, 'host_intro');
    $evening = null;
    foreach ($intros as $u) {
        if ((momentOf($u)['program']['title']['en'] ?? '') === 'Evening') $evening = $u;
    }
    check($evening !== null, 'the evening program was opened');
    $show = showOf($evening);
    eq($show['so_far'] ?? null, [], 'nothing of its own yet');
    $before = $show['before'] ?? [];
    check(count($before) === 2 && isset(end($before)['song']) && in_array('break', array_column($before, 'host'), true), 'the last song and words of the program before');
    eq(momentOf($evening)['program']['description'] ?? null, ['en' => 'Quiet songs for the evening.', 'de' => 'Ruhige Lieder für den Abend.'], 'the intro is told what the program is about');
    $breaks = writerMessages($app, 'host_break');
    check($breaks !== [] && !isset(momentOf($breaks[0])['program']['description']), 'a break is not');
});

test('show: a moment that named a listener is summarized; nobody\'s name, place or words are in the memory', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    namingHost($app);
    ticks($app, 10);
    $pub = $app->submissions()->submitSong(listener($app), TestKit::main($app), ['url' => 'https://youtu.be/ShowSongL1x', 'name' => 'Lena', 'place' => 'Hamburg', 'message' => 'Für meine Oma Gisela']);
    runJobs($app);
    $id = (int) $app->submissions()->byPublicId($pub['id'])['id'];
    for ($i = 0; $i < 40 && $app->submissions()->get($id)['status'] !== 'aired'; $i++) ticks($app, 1);
    eq($app->submissions()->get($id)['status'], 'aired', 'her request aired');
    ticks($app, 20);
    $named = 0;
    foreach (writerMessages($app, 'host_break', 'host_announce', 'host_outro') as $u) {
        $memory = (string) json_encode(showOf($u), JSON_UNESCAPED_UNICODE);
        foreach (['Lena', 'Hamburg', 'Gisela'] as $word) check(!str_contains($memory, $word), "the memory never holds '$word'");
        $named += str_contains($memory, "presented a listener's song request") ? 1 : 0;
    }
    check($named > 0, 'the announcement is there, summarized');
    check(str_contains((string) json_encode(showOf(end($app->text()->calls)['user'])), '"requested":true'), 'her song is marked as a request, no more');

    eq(ShowLog::quotable('break', 'openai', ['prayers' => 2]), true, 'the prayer hour\'s outro counts its prayers: no names');
    foreach ([['break', 'openai', ['prayers' => [['name' => 'Ana']]]], ['break', 'openai', ['previous_id' => 4]], ['break', 'openai', ['community' => [['name' => 'Kim']]]],
        ['announce', 'openai', ['submission_id' => 4]], ['reading', 'listener', []], ['opening', 'moderator', []], ['break', 'moderator', []]] as [$kind, $source, $ctx]) {
        eq(ShowLog::quotable($kind, $source, $ctx), false, "not quoted: $kind " . json_encode($ctx));
    }
});

test('show: a long show is cut at a half hour, within its size', function () {
    $app = TestKit::app();
    $t0 = TestKit::T0;
    $items = [];
    // A break every eight minutes for ten hours, longer than a show's memory holds.
    for ($i = 0; $i < 76; $i++) {
        $at = $t0 + $i * 480_000;
        $items[] = ['type' => 'host', 'state' => 'committed', 'start_ms' => $at, 'est_start' => $at, 'dur_ms' => 20_000,
            'payload' => ['kind' => 'break', 'text' => ['en' => "Break $i. " . str_repeat('A word. ', 20), 'de' => "Pause $i. " . str_repeat('Ein Wort. ', 20)]],
            'break' => ['kind' => 'break', 'state' => 'ready', 'context' => [], 'texts' => [], 'source' => 'openai']];
    }
    $zone = new DateTimeZone('Europe/Berlin');
    $m = $app->showLog()->memory([], array_slice($items, 0, 75), $t0, $t0 + 75 * 480_000, $zone);
    check(strlen((string) json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) <= ShowLog::MAX_CHARS, 'within its size');
    check(preg_match('/:(00|30)$/', (string) ($m['left_out_before'] ?? '')) === 1, 'cut at a half hour, so the cut moves only every thirty minutes');
    eq(end($m['so_far'])['said']['en'] ?? '', 'Break 74. ' . trim(str_repeat('A word. ', 20)), 'keeping the newest');
    $next = $app->showLog()->memory([], $items, $t0, $t0 + 76 * 480_000, $zone);
    eq(array_slice($next['so_far'], 0, count($m['so_far'])), $m['so_far'], 'a moment later it begins the same');
});

test('erasure: a later script that copied the show so far names nobody once the account is gone', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    namingHost($app);
    // The worst case: a host that repeats everything it was given, the show so far word for word.
    $app->text()->respond('host_break', function (string $system, string $user): array {
        preg_match_all('/"name": "([^"]+)"/', $user, $m);
        $said = implode(' ', array_filter(array_map(fn($e) => $e['said']['en'] ?? null, showOf($user)['so_far'] ?? [])));
        $t = trim('With us: ' . implode(', ', $m[1]) . '. Earlier: ' . $said);
        return ['en' => ['text' => mb_substr($t, 0, 690)], 'de' => ['text' => mb_substr($t, 0, 690)]];
    });
    ticks($app, 10);
    [$h, $mia] = account($app, 'Mia');
    $id = requestAs($app, $mia, 'ShowEraseM1', 'Mia');
    for ($i = 0; $i < 40 && $app->submissions()->get($id)['status'] !== 'aired'; $i++) ticks($app, 1);
    ticks($app, 25);
    check(str_contains((string) json_encode($app->store()->all('SELECT texts FROM host_breaks')), 'Mia'), 'the moments around her request named her');
    eq(call($app, 'DELETE', '/api/me', [], $h)[0], 200, 'Mia deletes her account');
    check(!str_contains((string) json_encode($app->store()->all('SELECT context, texts FROM host_breaks')), 'Mia'), 'no script names her, not even one written from the memory later');
    check(!str_contains((string) json_encode($app->store()->all('SELECT payload FROM timeline_items')), 'Mia'), 'nor any item of the plan');
});

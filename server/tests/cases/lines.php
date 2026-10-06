<?php
declare(strict_types=1);

use Arche\Ai\VoiceError;
use Arche\Host\Lines;
use Arche\Schema;

/**
 * Recorded host lines (Host\Lines): written by the AI or a moderator,
 * recorded once in the host's voice, picked for the moments a program takes
 * from the library — without a voice call, even when no voice can speak —
 * and looked after in /mod.
 */

/** The program on air takes these kinds from its hosts' lines. @param list<string> $kinds */
function libraryMode(Arche\App $app, array $kinds, ?array $program = null): array
{
    $program ??= onAir($app)[1];
    $app->lines()->setProgramMode((int) $program['id'], ['mode' => 'library', 'kinds' => $kinds]);
    return $program;
}

/** Only the lines a test adds: no refill writing more of its own. */
function noRefill(Arche\App $app): void
{
    $app->lines()->setOptions(hope($app)['id'], ['refill' => false], 'test');
}

/** A line a moderator wrote, recorded right away (stub voice). @param array<string,string>|null $texts @return array<string,mixed> */
function recordedLine(Arche\App $app, string $kind, ?array $texts = null, ?int $programId = null, array $tags = []): array
{
    $line = $app->lines()->add([
        'host_id' => hope($app)['id'],
        'kind' => $kind,
        'program_id' => $programId,
        'texts' => $texts ?? ['en' => "A $kind line " . bin2hex(random_bytes(3)) . '.', 'de' => "Ein Satz für $kind " . bin2hex(random_bytes(3)) . '.'],
        'tags' => $tags + ['time' => 'any', 'mood' => 'warm'],
    ], 'test');
    runJobs($app, 4);
    $row = $app->store()->one('SELECT * FROM host_lines WHERE id = ?', [$line['id']]) ?? throw new LogicException('no line');
    $row['audio'] = json_decode((string) $row['audio'], true);
    return $row;
}

/** @return list<array<string,mixed>> the ready host breaks of a kind, decoded */
function readyBreaks(Arche\App $app, string $kind): array
{
    $ids = array_column($app->store()->all("SELECT id FROM host_breaks WHERE kind = ? AND state = 'ready' ORDER BY id", [$kind]), 'id');
    return array_map(fn($id) => $app->hostBreaks()->get((int) $id), $ids);
}

test('lines: the AI writes a host\'s lines where its programs use the library; each is recorded in its voice, under its own usage, never its daily cap', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $host = hope($app);
    $app->lines()->queue();
    eq($app->store()->value("SELECT COUNT(*) FROM jobs WHERE type = 'lines'"), 0, 'no program uses the library: nothing to write');

    libraryMode($app, ['break']);
    $app->lines()->queue();
    runJobs($app, 8);
    $rows = $app->store()->all('SELECT * FROM host_lines WHERE host_id = ? ORDER BY id', [$host['id']]);
    eq(count($rows), 6, 'the lines the model wrote: a refill writes six at a time');
    foreach ($rows as $r) {
        eq([$r['kind'], $r['state'], $r['source'], $r['program_id']], ['break', 'active', 'model', null], 'a generic break line, recorded and on air');
        $audio = json_decode((string) $r['audio'], true);
        eq(array_keys($audio), ['en', 'de'], 'a clip per language');
        foreach ($audio as $url) {
            check(str_starts_with($url, '/media/lines/'), 'in its own folder: ' . $url);
            check(is_file((string) $app->media()->path($url)), 'written');
        }
        eq($r['voice'], $app->lines()->signature($host), 'with the host\'s voice of now');
    }
    eq($app->hosts()->usedToday($host['id']), 0, 'the daily cap for moments on air is untouched');
    eq($app->lines()->monthChars($host['id']), array_sum(array_column($rows, 'chars')), 'counted as recording, characters per language');
    check(str_contains((string) $app->store()->value("SELECT detail FROM audit WHERE event = 'Lines written by AI'"), '6 break'), 'audited');
});

test('lines: a line that prays, one too long and one written twice are never kept', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    libraryMode($app, ['encourage']);
    $ok = ['en' => ['text' => 'Look at the prayer wall and pray for what moves you.'], 'de' => ['text' => 'Schau auf die Gebetswand und bete für das, was dich bewegt.'], 'time' => 'any', 'mood' => 'calm'];
    $app->text()->respond('lines_write', fn() => ['lines' => [
        ['en' => ['text' => 'We pray together now. Amen.'], 'de' => ['text' => 'Wir beten jetzt gemeinsam. Amen.'], 'time' => 'any', 'mood' => 'calm'],
        ['en' => ['text' => str_repeat('Long words. ', 80)], 'de' => ['text' => 'Kurz.'], 'time' => 'any', 'mood' => 'calm'],
        $ok,
        $ok,
    ]]);
    $app->lines()->queue();
    runJobs($app, 8);
    eq((int) $app->store()->value('SELECT COUNT(*) FROM host_lines'), 1, 'one kept: no prayer, no overlong line, no twin');
    $app->store()->query("UPDATE jobs SET status = 'done' WHERE type = 'lines'");
    $app->lines()->queue();
    runJobs($app, 8);
    eq((int) $app->store()->value('SELECT COUNT(*) FROM host_lines'), 1, 'written again, the same line is not added twice');
});

test('lines: a program taking its breaks from the library airs a recorded line — the AI\'s pick, ready without a voice, its next song not pinned, counted when committed', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $program = libraryMode($app, ['break']);
    noRefill($app);
    $a = recordedLine($app, 'break');
    $b = recordedLine($app, 'break');
    // The model picks the second it is offered — whichever that is, the moment takes it.
    $app->text()->respond('lines_pick', function ($system, $user) {
        preg_match_all('/"id":"(\d+)"/', $user, $m);
        return ['id' => $m[1][1] ?? $m[1][0]];
    });
    $calls = $app->openai()->ttsCalls;
    ticks($app, 30);
    $breaks = readyBreaks($app, 'break');
    check(count($breaks) >= 2, 'breaks were planned (' . count($breaks) . ')');
    foreach ($breaks as $hb) {
        eq($hb['source'], 'library', 'from the library');
        check(in_array((int) $hb['context']['line_id'], [(int) $a['id'], (int) $b['id']], true), 'one of the host\'s lines');
        check(!isset($hb['context']['next_uid']), 'no song pinned: a recorded line names none');
        check(str_starts_with((string) $hb['audio']['en'], '/media/lines/'), 'its clip is the line\'s');
    }
    $intros = count(readyBreaks($app, 'intro'));
    eq($app->openai()->ttsCalls - $calls, $intros * 2, 'voice calls only for the fresh intros, none for a recorded line');
    $picks = array_values(array_filter($app->text()->calls, fn($c) => $c['kind'] === 'lines_pick'));
    check(count($picks) >= 1, 'the AI picked');
    check(!str_contains($picks[0]['user'], 'next_uid'), 'from the moment as the model may see it');
    $items = array_values(array_filter(publishedHostItems($app), fn($it) => $it['kind'] === 'break'));
    check($items !== [] && str_starts_with((string) $items[0]['audio']['en'], '/media/lines/'), 'the minute files play the recorded clip');
    eq($items[0]['host']['name'], 'Hope', 'spoken by its host');
    $aired = $app->store()->all('SELECT uses, last_aired FROM host_lines WHERE id IN (?, ?)', [(int) $a['id'], (int) $b['id']]);
    check(array_sum(array_column($aired, 'uses')) >= 1 && array_filter(array_column($aired, 'last_aired')) !== [], 'counted and resting once committed');
    check($program['id'] > 0, 'program');
});

test('lines: rotation, the time of day, the voice and the program decide which line may air; nothing fitting means fresh words and a refill', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    [$ch, $program] = onAir($app);
    libraryMode($app, ['break', 'intro']);
    noRefill($app);
    $host = hope($app);
    $lines = $app->lines();
    $hb = $app->hostBreaks()->get(breakNow($app));
    $first = recordedLine($app, 'break');
    $second = recordedLine($app, 'break');
    $app->text()->respond('lines_pick', fn() => null); // the model refuses: the one aired longest ago
    eq($lines->pick($hb, $host, [], $ch, $program)['id'], (int) $first['id'], 'never aired, oldest first');
    $lines->aired((int) $first['id'], $app->clock->nowMs() - 3_600_000);
    eq($lines->pick($hb, $host, [], $ch, $program)['id'], (int) $second['id'], 'an hour ago is too soon while another rests');
    $lines->aired((int) $second['id'], $app->clock->nowMs() - 1_800_000);
    eq($lines->pick($hb, $host, [], $ch, $program)['id'], (int) $first['id'], 'all aired recently: the one aired longest ago');

    // 12:00 in Berlin is afternoon: an evening line ("heute Abend") does not fit.
    $evening = recordedLine($app, 'intro', null, (int) $program['id'], ['time' => 'evening']);
    $intro = $app->hostBreaks()->get(breakNow($app, 'intro'));
    eq($lines->pick($intro, $host, [], $ch, $program), null, 'no line for this time of day');
    $other = $app->catalog()->saveProgram(null, (int) $ch['id'], ['slug' => 'other', 'title_en' => 'Other', 'title_de' => 'Andere'], 'test');
    $mine = recordedLine($app, 'intro', null, (int) $program['id'], ['time' => 'afternoon']);
    recordedLine($app, 'intro', null, (int) $other['id']);
    eq($lines->pick($intro, $host, [], $ch, $program)['id'], (int) $mine['id'], 'its own welcome, never another program\'s');
    check($evening['id'] > 0, 'evening line kept for the evening');

    // A new voice: lines recorded with the old one sound like someone else.
    $app->hosts()->save($host['id'], ['voices' => ['en' => 'onyx', 'de' => 'onyx']], 'test');
    $host = $app->hosts()->get($host['id']);
    eq($lines->pick($hb, $host, [], $ch, $program), null, 'no line in its old voice');
    $app->lines()->setOptions($host['id'], ['old_voice' => true], 'test');
    check($lines->pick($hb, $host, [], $ch, $program) !== null, 'unless a moderator keeps the old recordings');
    $app->lines()->setOptions($host['id'], ['old_voice' => false], 'test');
    $id = breakNow($app);
    runJobs($app, 6);
    eq($app->hostBreaks()->get($id)['source'], 'stub', 'meanwhile its breaks are written and voiced fresh, in the new voice');
    $pool = array_values(array_filter($lines->overview($host, true)['pools'], fn($p) => $p['kind'] === 'break'))[0];
    eq([$pool['active'], $pool['old_voice']], [0, 2], 'the old recordings fill no pool: a refill records anew');
});

test('lines: a moment no line fits — none for this time of day — is written fresh, and the library asked to fill up', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $program = libraryMode($app, ['intro']);
    noRefill($app);
    recordedLine($app, 'intro', null, (int) $program['id'], ['time' => 'evening']);
    $app->store()->query("UPDATE jobs SET status = 'done' WHERE type = 'lines'");
    $id = breakNow($app, 'intro');
    $app->runner()->runUntilBudget(1);
    eq((int) $app->store()->value("SELECT COUNT(*) FROM jobs WHERE type = 'lines' AND status = 'queued'"), 1, 'the library asked to fill up');
    runJobs($app, 6);
    $done = $app->hostBreaks()->get($id);
    eq([$done['state'], $done['source']], ['ready', 'stub'], 'and the welcome written and voiced fresh');
});

test('lines: a host who cannot speak still airs its recorded moments; the daily cap and the spent budget stop fresh words only', function () {
    $app = TestKit::app(['HOST_MAX_BREAKS_PER_DAY' => '0', 'AI_DAILY_BUDGET_USD' => '1']);
    TestKit::songs($app, 12);
    [$ch, $program] = onAir($app);
    libraryMode($app, ['break']);
    recordedLine($app, 'break');
    $host = hope($app);
    $app->hosts()->failed($host, new VoiceError('openai', 404, 'model_not_found', 'gone'));
    check(!$app->hostBreaks()->available($ch, $program), 'nobody can speak now');
    check($app->hostBreaks()->available($ch, $program, 'break'), 'but a break can come from a recorded line');
    check(!$app->hostBreaks()->available($ch, $program, 'intro'), 'not an intro the program does not take from the library');
    ticks($app, 25);
    $breaks = readyBreaks($app, 'break');
    check($breaks !== [], 'breaks aired from the library (cap 0, no voice)');
    eq(array_unique(array_column($breaks, 'source')), ['library'], 'all of them recorded');
    eq(readyBreaks($app, 'intro'), [], 'no fresh intro without a voice');

    // The day's budget spent: the pick goes without the model, the line still airs.
    $budget = TestKit::app(['AI_DAILY_BUDGET_USD' => '0']);
    TestKit::songs($budget, 12);
    libraryMode($budget, ['break']);
    $budget->store()->query("UPDATE host_line_options SET data = '{}'");
    $line = recordedLine($budget, 'break');
    $id = breakNow($budget);
    runJobs($budget, 3);
    $hb = $budget->hostBreaks()->get($id);
    eq([$hb['state'], $hb['source'], $hb['context']['line_id'] ?? null], ['ready', 'library', (int) $line['id']], 'a recorded line past the budget');
    $fresh = breakNow($budget, 'outro');
    runJobs($budget, 3);
    eq($budget->hostBreaks()->get($fresh)['source'], 'skipped:budget', 'fresh words wait for tomorrow\'s budget');
});

test('lines: recorded files outlive every break that aired them; a deleted host or program takes its own along', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    [$ch, $program] = onAir($app);
    libraryMode($app, ['break']);
    $line = recordedLine($app, 'break');
    $file = (string) $app->media()->path((string) $line['audio']['en']);
    $id = breakNow($app);
    runJobs($app, 3);
    eq($app->hostBreaks()->get($id)['source'], 'library', 'aired from the library');
    $app->hostBreaks()->forget([$id], []);
    check(is_file($file), 'an account\'s break forgotten: the shared recording stays');
    touch($file, (int) (TestKit::T0 / 1000) - 5 * 86400);
    $app->tick()->run('test');
    check(is_file($file), 'host clips expire after 48 h, recorded lines do not');

    $grace = makeHost($app, ['name' => 'Grace', 'voices' => ['en' => 'marin']]);
    $app->hosts()->delete($grace['id'], 'test');
    check(is_file($file), 'another host deleted: still here');

    $other = $app->catalog()->saveProgram(null, (int) $ch['id'], ['slug' => 'other', 'title_en' => 'Other', 'title_de' => 'Andere'], 'test');
    $intro = recordedLine($app, 'intro', null, (int) $other['id']);
    $introFile = (string) $app->media()->path((string) $intro['audio']['en']);
    $app->catalog()->deleteProgram((int) $other['id'], 'test');
    check(!is_file($introFile), 'a deleted program\'s own welcome goes with it');
    eq($app->store()->value('SELECT COUNT(*) FROM host_lines WHERE id = ?', [(int) $intro['id']]), 0, 'its row too');

    $solo = makeHost($app, ['name' => 'Solo', 'voices' => ['en' => 'ash']]);
    $mine = $app->lines()->add(['host_id' => $solo['id'], 'kind' => 'encourage', 'texts' => ['en' => 'Pray for one another.', 'de' => 'Betet füreinander.'], 'tags' => ['time' => 'any', 'mood' => '']], 'test');
    runJobs($app, 4);
    $soloFile = (string) $app->media()->path((string) json_decode((string) $app->store()->value('SELECT audio FROM host_lines WHERE id = ?', [$mine['id']]), true)['en']);
    check(is_file($soloFile), 'recorded for Solo');
    $app->hosts()->delete($solo['id'], 'test');
    check(!is_file($soloFile), 'a deleted host\'s recordings go with it');
    check($program['id'] > 0, 'program');
});

test('lines: an ElevenLabs host records nothing until an admin gives it an allowance; the month\'s allowance bounds every host; stub recordings never air live', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    libraryMode($app, ['encourage']);
    $el = elevenHost($app, ['name' => 'Joy']);
    $line = $app->lines()->add(['host_id' => $el['id'], 'kind' => 'encourage', 'texts' => ['en' => 'Look at the prayer wall.', 'de' => 'Schau auf die Gebetswand.'], 'tags' => ['time' => 'any', 'mood' => '']], 'test');
    runJobs($app, 4);
    eq($app->store()->value('SELECT state FROM host_lines WHERE id = ?', [$line['id']]), 'recording', 'waits: no characters of a small account without an admin\'s yes');
    $app->lines()->setOptions($el['id'], ['month_chars' => 30], 'test');
    $app->store()->query("UPDATE jobs SET status = 'done' WHERE type = 'lines'");
    $app->jobs()->enqueue('lines', $el['id'], 70, $app->clock->nowMs());
    runJobs($app, 4);
    $row = $app->store()->one('SELECT state, audio FROM host_lines WHERE id = ?', [$line['id']]);
    eq([$row['state'], array_keys(json_decode((string) $row['audio'], true))], ['recording', ['en']], 'English fits the 30 characters, German waits for next month');

    $live = TestKit::app(['AI_MODE' => 'live', 'OPENAI_KEY' => 'sk-test']);
    check($app->lines()->signature(hope($app)) !== $live->lines()->signature(hope($live)), 'a stub recording\'s voice is never a live one');
});

test('lines: a prayer hour takes its encouragements from the library; its order stays', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $p = prayerHour($app, 735);
    libraryMode($app, ['encourage'], $p);
    recordedLine($app, 'encourage');
    recordedLine($app, 'encourage');
    ticks($app, 80);
    $run = runOf($app, (int) $p['id']);
    eq(array_slice(labelsOf($run), 0, 3), ['intro', 'bed', 'prayertime'], 'welcome, collection, prayer time as always');
    $encourage = readyBreaks($app, 'encourage');
    check(count($encourage) >= 2, 'encouragements in the quiet (' . count($encourage) . ')');
    eq(array_unique(array_column($encourage, 'source')), ['library'], 'recorded ones');
    foreach (readyBreaks($app, 'prayertime') as $hb) eq($hb['source'], 'stub', 'the announcement stays fresh: not taken from the library');
});

test('lines: moderators keep the library in order in /mod — list, add, edit, pause, remove, ask the AI — and admins set the options', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    [, $program] = onAir($app);
    $host = hope($app);
    $admin = modHeaders($app);
    $mod = moderatorHeaders($app);

    [$s, $d] = call($app, 'POST', '/api/mod/lines', ['host_id' => $host['id'], 'kind' => 'encourage', 'program_id' => null, 'texts' => ['en' => 'Pray for what moves you.', 'de' => 'Bete für das, was dich bewegt.'], 'tags' => ['time' => 'any', 'mood' => 'calm']], $mod);
    eq([$s, $d['line']['state'], $d['line']['source']], [200, 'recording', 'moderator'], 'a moderator adds a line; it is recorded next');
    $id = (int) $d['line']['id'];
    eq(call($app, 'POST', '/api/mod/lines', ['host_id' => $host['id'], 'kind' => 'encourage', 'texts' => ['en' => 'Let us pray together.'], 'tags' => []], $mod), [422, ['error' => 'line_prays']], 'the host never prays, not even in a line a moderator wrote');
    eq(call($app, 'POST', '/api/mod/lines', ['host_id' => $host['id'], 'kind' => 'intro', 'texts' => ['en' => 'Welcome.'], 'tags' => []], $mod)[1]['error'] ?? null, 'line_program', 'a welcome belongs to a program');
    runJobs($app, 4);

    [$s, $list] = modGet($app, '/api/mod/lines', ['host' => (string) $host['id']], $mod);
    eq([$s, $list['total'], $list['lines'][0]['state'], $list['lines'][0]['old_voice']], [200, 1, 'active', false], 'listed, recorded, in its voice');
    $file = (string) $app->media()->path((string) ((array) $list['lines'][0]['audio'])['en']);
    check(is_file($file), 'with its clip');

    eq(call($app, 'PATCH', "/api/mod/lines/$id", ['tags' => ['time' => 'evening', 'mood' => 'warm']], $mod)[1]['line']['state'], 'active', 'new tags: no new recording');
    [$s, $d] = call($app, 'PATCH', "/api/mod/lines/$id", ['texts' => ['en' => 'Look at the prayer wall.', 'de' => 'Schau auf die Gebetswand.']], $mod);
    eq([$s, $d['line']['state'], (array) $d['line']['audio']], [200, 'recording', []], 'new words: recorded again');
    check(!is_file($file), 'the old clip is gone');
    runJobs($app, 4);
    eq(call($app, 'PATCH', "/api/mod/lines/$id", ['state' => 'paused'], $mod)[1]['line']['state'], 'paused', 'paused');
    eq(call($app, 'PATCH', "/api/mod/lines/$id", ['state' => 'draft'], $mod), [422, ['error' => 'line_state']], 'not back to a draft');

    $second = recordedLine($app, 'encourage');
    eq(modGet($app, '/api/mod/lines', ['host' => (string) $host['id'], 'state' => 'paused'], $mod)[1]['total'], 1, 'filtered by state');
    [, $page] = modGet($app, '/api/mod/lines', ['host' => (string) $host['id'], 'limit' => '1', 'offset' => '1'], $mod);
    eq([$page['total'], count($page['lines']), $page['lines'][0]['id']], [2, 1, $id], 'paged: newest first');
    eq(modGet($app, '/api/mod/lines', ['host' => (string) $host['id'], 'q' => 'Gebetswand'], $mod)[1]['total'], 1, 'found by its words');

    eq(call($app, 'POST', '/api/mod/lines/bulk', ['ids' => [$id, (int) $second['id']], 'action' => 'resume'], $mod)[1], ['ok' => true, 'changed' => 1], 'resumed what was paused');
    $secondFile = (string) $app->media()->path((string) $second['audio']['en']);
    eq(call($app, 'POST', '/api/mod/lines/bulk', ['ids' => [(int) $second['id']], 'action' => 'remove'], $mod)[1]['changed'], 1, 'removed');
    check(!is_file($secondFile), 'its clips with it');
    eq(call($app, 'POST', '/api/mod/lines/bulk', ['ids' => ['x'], 'action' => 'remove'], $mod), [422, ['error' => 'line_ids']], 'ids are numbers');
    eq(call($app, 'POST', '/api/mod/lines/bulk', ['ids' => [$id], 'action' => 'shout'], $mod), [422, ['error' => 'line_action']], 'known actions only');
    eq(call($app, 'DELETE', "/api/mod/lines/$id", [], $mod)[0], 200, 'one removed');
    eq(modGet($app, '/api/mod/lines', ['host' => (string) $host['id']], $mod)[1]['total'], 0, 'removed lines are not listed');

    [$s, $d] = call($app, 'POST', '/api/mod/lines/write', ['host_id' => $host['id'], 'kind' => 'intro', 'program_id' => (int) $program['id'], 'count' => 3, 'hint' => 'Advent'], $mod);
    eq([$s, $d['queued']], [200, 3], 'three lines asked of the AI');
    eq(call($app, 'POST', '/api/mod/lines/write', ['host_id' => $host['id'], 'kind' => 'break', 'count' => 11, 'hint' => ''], $mod), [422, ['error' => 'line_count']], 'at most ten at a time');
    runJobs($app, 8);
    eq((int) $app->store()->value("SELECT COUNT(*) FROM host_lines WHERE kind = 'intro' AND program_id = ? AND state = 'active'", [(int) $program['id']]), 3, 'written for that program and recorded');
    check(str_contains((string) array_values(array_filter($app->text()->calls, fn($c) => $c['kind'] === 'lines_write'))[0]['user'], 'Advent'), 'with the moderator\'s hint');

    [$s, $ov] = modGet($app, '/api/mod/lines/overview', ['host' => (string) $host['id']], $mod);
    eq([$s, $ov['can_edit_options'], $ov['options']['month_chars'], $ov['kinds']], [200, false, 100_000, Lines::KINDS], 'the overview, options read-only for a moderator');
    $pool = array_values(array_filter($ov['pools'], fn($p) => $p['kind'] === 'intro' && $p['program_id'] === (int) $program['id']))[0];
    eq([$pool['active'], $pool['target']], [3, 8], 'its welcome pool against its target');
    eq(call($app, 'PATCH', "/api/mod/hosts/{$host['id']}/lines", ['live' => false], $mod)[0], 403, 'options are for admins');
    [$s, $d] = call($app, 'PATCH', "/api/mod/hosts/{$host['id']}/lines", ['live' => false, 'rest_hours' => 24, 'targets' => ['encourage' => 40]], $admin);
    eq([$s, $d['options']['live'], $d['options']['rest_hours'], $d['options']['targets']['encourage'], $d['options']['targets']['intro']], [200, false, 24, 40, 8], 'an admin sets them, the rest kept');
    eq(call($app, 'PATCH', "/api/mod/hosts/{$host['id']}/lines", ['rest_hours' => 'soon'], $admin), [422, ['error' => 'line_options']], 'checked');
    $draft = recordedLine($app, 'encourage');
    eq($draft['state'], 'draft', 'not live without approval now');
    eq(call($app, 'POST', '/api/mod/lines/bulk', ['ids' => [(int) $draft['id']], 'action' => 'approve'], $mod)[1]['changed'], 1, 'approved');
    eq(modGet($app, '/api/mod/hosts', [], $mod)[1]['hosts'][0]['lines_active'], 4, 'the host list counts its lines');
});

test('lines: a program\'s mode is written only when sent — an older /mod tab keeps it; a migration replay keeps the lines', function () {
    $app = TestKit::app();
    [, $program] = onAir($app);
    $h = modHeaders($app);
    [$s, $d] = call($app, 'PATCH', "/api/mod/programs/{$program['id']}", ['lines' => ['mode' => 'library', 'kinds' => ['encourage', 'break']]], $h);
    eq([$s, $d['program']['lines']], [200, ['mode' => 'library', 'kinds' => ['encourage', 'break']]], 'set from /mod');
    call($app, 'PATCH', "/api/mod/programs/{$program['id']}", ['title_en' => 'Live'], $h);
    eq($app->lines()->programMode((int) $program['id'])['mode'], 'library', 'an older tab, sending none, leaves it');
    eq(call($app, 'PATCH', "/api/mod/programs/{$program['id']}", ['lines' => ['mode' => 'loud', 'kinds' => []]], $h), [422, ['error' => 'lines_mode']], 'known modes only');
    eq(call($app, 'PATCH', "/api/mod/programs/{$program['id']}", ['lines' => ['mode' => 'library', 'kinds' => ['reading']]], $h), [422, ['error' => 'lines_mode']], 'never a listener\'s words');

    $line = recordedLine($app, 'encourage');
    $app->store()->set('schema', 13);
    Schema::migrate($app->store(), $app->clock->nowMs());
    eq($app->store()->value('SELECT state FROM host_lines WHERE id = ?', [(int) $line['id']]), 'active', 'migrated again: the lines stay');
    eq($app->lines()->programMode((int) $program['id'])['mode'], 'library', 'and the mode');
});

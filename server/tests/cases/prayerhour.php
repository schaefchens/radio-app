<?php
declare(strict_types=1);

use Arche\Host\HostWriter;
use Arche\Program\SubmissionWindow;
use Arche\Program\Timing;

/**
 * The prayer hour, walked minute by minute on the fixed clock: its running
 * order, when requests and prayers air, what the host says (it never prays),
 * the outro's time, intake, midnight, outages and plans changed at the last
 * moment.
 */

/**
 * A prayer hour on T0's day (Berlin) from $startMin for $minutes, the regular
 * program before and after; prayer music of $bedSeconds (0: none).
 *
 * @param array<string,mixed> $prayer settings over the defaults
 * @return array<string,mixed> the program
 */
function prayerHour(Arche\App $app, int $startMin, int $minutes = 60, array $prayer = [], int $bedSeconds = 300, int $month = 9, int $day = 23): array
{
    $cat = $app->catalog();
    $cid = (int) TestKit::main($app)['id'];
    $bed = $bedSeconds > 0 ? $app->library()->addBed(silentMp3($bedSeconds), 'Pad', 'test') : null;
    $settings = ['format' => 'prayer', 'prayer' => array_replace_recursive(['collect' => ['songs' => 0, 'minutes' => 10, 'bed_id' => (int) ($bed['id'] ?? 0)], 'opendoors' => false], $prayer)];
    $p = $cat->saveProgram(null, $cid, ['slug' => 'prayer', 'title_en' => 'Prayer Hour', 'title_de' => 'Gebetsstunde', 'settings' => $settings], 'test');
    $plan = $cat->saveDayPlan(null, $cid, 'Prayer', [['start_min' => $startMin, 'end_min' => $startMin + $minutes, 'program_id' => $p['id']]], 'test');
    $cat->addSpecialDay($cid, ['name' => 'Prayer', 'kind' => 'date', 'month' => $month, 'day' => $day, 'day_plan_id' => $plan], 'test');
    return $p;
}

/**
 * The program's committed items, each with a label: the host's kind (a
 * reading as `reading:read` or `reading:new`), else the type; a filler is
 * marked with "*".
 *
 * @return list<array{label:string,item:array<string,mixed>,context:array<string,mixed>}>
 */
function runOf(Arche\App $app, int $programId): array
{
    $out = [];
    foreach (TestKit::committed($app) as $it) {
        if ($it['program_id'] !== $programId) continue;
        $ctx = $it['host_break_id'] !== null ? hostContext($app, $it) : [];
        $label = $it['type'] === 'host' ? (string) $it['payload']['kind'] : $it['type'];
        if ($label === 'reading') $label .= ':' . ($ctx['phase'] ?? '');
        if (!empty($it['payload']['filler'])) $label .= '*';
        $out[] = ['label' => $label, 'item' => $it, 'context' => $ctx];
    }
    return $out;
}

/** The labels, repeats of silence and music squashed: intro, bed, present, reading:read, … @return list<string> */
function labelsOf(array $run): array
{
    $out = [];
    foreach ($run as $r) {
        if (in_array($r['label'], ['silence', 'bed', 'silence*', 'bed*'], true) && end($out) === $r['label']) continue;
        $out[] = $r['label'];
    }
    return $out;
}

/** A prayer request from a new listener (one identity may send three an hour). @return string public id */
function prayFor(Arche\App $app, string $name, bool $wall = true): string
{
    return $app->submissions()->submitPrayer(listener($app), TestKit::main($app),
        ['text' => "Please pray for $name's family.", 'name' => $name, 'place' => 'Bonn', 'consent_air' => $wall ? '1' : ''])['id'];
}

/** A listener's written prayer, sent in the prayer time. @return string public id */
function prayAs(Arche\App $app, string $name): string
{
    return $app->submissions()->submitIntercession(listener($app), TestKit::main($app),
        ['text' => "Lord, be with everyone who asked for prayer tonight. ($name)", 'name' => $name, 'place' => 'Bonn', 'consent_air' => '1'])['id'];
}

/** A listener's spoken prayer, sent in the prayer time. @return string public id */
function prayAloud(Arche\App $app, string $name): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'rec');
    copy(dirname(__DIR__, 2) . '/resources/stub-voice.mp3', $tmp);
    return $app->submissions()->submitAudio(listener($app), TestKit::main($app), ['type' => 'intercession', 'name' => $name, 'place' => 'Wien', 'consent_air' => '1'], $tmp)['id'];
}

/** Where in the run a submission aired: a reading naming it, or its recording. @param list<array{label:string,item:array<string,mixed>,context:array<string,mixed>}> $run @return list<int> */
function airedIn(array $run, int $id): array
{
    $out = [];
    foreach ($run as $i => $r) {
        if ($r['item']['submission_id'] === $id || in_array($id, array_map('intval', (array) ($r['context']['prayer_ids'] ?? [])), true)) $out[] = $i;
    }
    return $out;
}

/** The program's state for intake at $t: [requests, prayers]. @return array{0:?string,1:?string} */
function intakeAt(Arche\App $app, array $program, int $t): array
{
    $ch = TestKit::main($app);
    $states = SubmissionWindow::states($app, $ch, $app->catalog()->program((int) $program['id']) ?? [], $app->resolver()->blockAt($ch, $t), $t);
    return [$states['prayer'] ?? null, $states['intercession'] ?? null];
}

test('prayer hour: welcome, collection, the requests read word for word, the prayer time with listeners\' prayers, the outro — and the host never prays', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $p = prayerHour($app, 735); // 12:15–13:15 Berlin = T0 + 15 … + 75 min
    $cid = (int) TestKit::main($app)['id'];
    $end = TestKit::T0 + 75 * 60_000;
    $sent = [];
    $prayers = [];
    for ($m = 0; $m < 80; $m++) {
        // Two during the collection (before the presentation is planned), one just after, one in the prayer time.
        if (in_array($m, [16, 18, 21, 40], true)) $sent[$m] = prayFor($app, "Name$m");
        if ($m === 45) $prayers['written'] = prayAs($app, 'Lea');
        if ($m === 47) $prayers['spoken'] = prayAloud($app, 'Jonas');
        $app->tick()->run('test');
        check($app->committer()->frontier($cid) >= $app->clock->nowMs() + Timing::COMMIT, "the fixed timeline reaches COMMIT ahead at minute $m");
        TestKit::clock($app)->advance(60_000);
    }
    $run = runOf($app, (int) $p['id']);
    $labels = labelsOf($run);
    eq(array_slice($labels, 0, 6), ['intro', 'bed', 'present', 'reading:read', 'reading:read', 'prayertime'],
        'welcome, the prayer music while requests come in, then they are presented and read out, then the prayer time opens');
    $beds = array_values(array_filter($run, fn($r) => $r['label'] === 'bed'));
    check(abs(array_sum(array_map(fn($r) => $r['item']['dur_ms'], $beds)) - 600_000) < 3_000, 'ten minutes of prayer music');
    $bedFile = (int) $app->library()->get((int) $beds[0]['item']['library_id'])['duration_ms'];
    foreach ($beds as $i => $b) check($b['item']['payload']['offset'] + $b['item']['dur_ms'] <= $bedFile, "piece $i never runs past the end of the file");

    $idOf = fn(string $public) => (int) $app->submissions()->byPublicId($public)['id'];
    foreach ($sent as $m => $public) {
        $at = airedIn($run, $idOf($public));
        eq(count($at), 1, "the request of minute $m is read out once");
        $reading = $run[$at[0]];
        eq($reading['label'], $m <= 18 ? 'reading:read' : 'reading:new', "minute $m: " . ($m <= 18 ? 'in the presentation' : 'in the prayer time'));
        $text = (string) $reading['item']['payload']['text']['en'];
        check(str_ends_with($text, " Please pray for Name$m's family.") && str_contains($text, "Name$m from Bonn"), "word for word, with the first name and place given: $text");
        check($reading['item']['start_ms'] - (TestKit::T0 + $m * 60_000) <= 10 * 60_000, "minute $m airs within about seven to ten minutes");
        eq($app->submissions()->byPublicId($public)['status'], 'aired', "minute $m is marked aired");
    }
    $written = $run[airedIn($run, $idOf($prayers['written']))[0] ?? -1] ?? null;
    check($written !== null && $written['label'] === 'intercession', 'the written prayer is read out');
    $text = (string) $written['item']['payload']['text']['en'];
    check(str_contains($text, 'Lea from Bonn') && str_ends_with($text, ' Lord, be with everyone who asked for prayer tonight. (Lea)'), "after a short lead-in, word for word: $text");
    eq($app->hostBreaks()->get((int) $written['item']['host_break_id'])['source'], 'listener', 'without the model');
    $spokenId = $idOf($prayers['spoken']);
    $spoken = $run[airedIn($run, $spokenId)[0] ?? -1] ?? null;
    check($spoken !== null && $spoken['item']['type'] === 'contrib', 'the spoken prayer plays');
    eq([$spoken['item']['payload']['kind'], $spoken['item']['payload']['caption']['en'], $spoken['item']['payload']['name']], ['prayer', 'Prayer', 'Jonas'], 'as a prayer, with its name');
    eq($spoken['item']['dur_ms'], (int) $app->submissions()->get($spokenId)['audio_ms'] + Timing::PRAYER_GAP, 'with a few seconds of quiet after it');
    eq((int) $app->store()->value("SELECT COUNT(*) FROM host_breaks WHERE json_extract(context, '$.submission_id') = ?", [$spokenId]), 0, 'and no word from the host before it');
    $day = $app->publisher()->day(TestKit::main($app), '2026-09-23');
    $entry = array_values(array_filter($day['played'], fn($e) => $e['start'] === $spoken['item']['start_ms']))[0] ?? null;
    eq([$entry['type'] ?? null, $entry['title'] ?? null], ['contrib', ''], 'the day\'s list does not say who prayed (it is public for two months)');
    check(in_array('encourage', $labels, true), 'after a few quiet minutes the host encourages everyone');
    $silence = array_values(array_filter($run, fn($r) => $r['label'] === 'silence'));
    eq($silence[count($silence) - 1]['item']['payload']['label']['de'], 'Gebetszeit', 'the quiet is the prayer time');

    $hosts = array_values(array_filter($run, fn($r) => $r['item']['type'] === 'host'));
    $outro = end($hosts);
    eq($outro['label'], 'outro', 'the host closes the hour');
    check($outro['item']['start_ms'] >= $end - 3 * 60_000 && $outro['item']['start_ms'] + $outro['item']['dur_ms'] <= $end + Timing::SOFT_OVERRUN, 'at the end of the hour');
    eq([$outro['context']['requests'] ?? null, $outro['context']['prayers'] ?? null], [4, 2], 'and knows how many requests and prayers aired');
    $count = fn(string $l) => count(array_filter($run, fn($r) => $r['label'] === $l));
    eq([$count('intro'), $count('opening'), $count('present'), $count('prayertime'), $count('outro'), $count('prayer'), $count('song')], [1, 0, 1, 1, 1, 0, 0],
        'one of each moment, no opening prayer (none was prepared), no prayer moment of the host, no songs');
    foreach ($hosts as $h) {
        if (in_array($h['item']['payload']['kind'], HostWriter::READINGS, true)) continue;
        foreach ((array) $h['item']['payload']['text'] as $l => $t) check(!HostWriter::prays((string) $t), "the host's {$h['label']} ($l) does not pray");
    }
    assertContiguous(TestKit::committed($app), 'contiguous');
});

test('prayer hour: songs first, then prayer music; songs after the outro until the next program', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $p = prayerHour($app, 735, 60, ['collect' => ['songs' => 2, 'minutes' => 10], 'after_songs' => 2]);
    $end = TestKit::T0 + 75 * 60_000;
    ticks($app, 85);
    $run = runOf($app, (int) $p['id']);
    $labels = labelsOf($run);
    eq(array_slice($labels, 0, 5), ['intro', 'song', 'song', 'bed', 'prayertime'], 'two songs while requests come in, the prayer music up to ten minutes, then the time of prayer (nothing to present)');
    $close = $app->prayerHour()->closingAt(TestKit::main($app), $app->catalog()->program((int) $p['id']) ?? [], TestKit::T0 + 20 * 60_000);
    eq($close, $end - 2 * Timing::AFTER_SONG - Timing::OUTRO_ESTIMATE, 'the outro leaves room for two songs and itself');
    $outro = (int) array_search('outro', array_column($run, 'label'), true);
    check($outro > 0 && abs($run[$outro]['item']['start_ms'] - $close) < 3 * 60_000, 'and comes then');
    $after = array_slice($run, $outro + 1);
    check(count(array_filter($after, fn($r) => $r['label'] === 'song')) >= 1, 'songs after the outro');
    $prayerTime = (int) array_search('prayertime', array_column($run, 'label'), true);
    eq(count(array_filter(array_slice($run, $prayerTime, $outro - $prayerTime), fn($r) => $r['label'] === 'song')), 0, 'no song in the prayer time');
    $last = end($run)['item'];
    check($last['start_ms'] + $last['dur_ms'] <= $end + Timing::SOFT_OVERRUN, 'the music ends with the program');
    assertContiguous(TestKit::committed($app), 'contiguous');
});

test('prayer hour: with no request at all the prayer time still opens, and the host encourages now and then — it never prays', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $p = prayerHour($app, 735);
    ticks($app, 80);
    $run = runOf($app, (int) $p['id']);
    $labels = labelsOf($run);
    eq(array_slice($labels, 0, 3), ['intro', 'bed', 'prayertime'], 'nothing to present: the prayer time follows the collection');
    eq(hostContext($app, $run[(int) array_search('prayertime', array_column($run, 'label'), true)]['item'])['requests'] ?? null, 0, 'and the host knows nothing came in');
    $encourage = array_values(array_filter($run, fn($r) => $r['label'] === 'encourage'));
    check(count($encourage) >= 4, 'an encouragement after every few quiet minutes (' . count($encourage) . ')');
    for ($i = 1; $i < count($encourage); $i++) {
        check($encourage[$i]['item']['start_ms'] - $encourage[$i - 1]['item']['start_ms'] >= 4 * 60_000, 'not more often than the quiet minutes');
    }
    check(in_array('outro', $labels, true), 'and the outro');
});

test('prayer hour: every request appears on the hour\'s wall when it is read out, ticked or not; read and shown with the name given, or anonymously; after the hour only the ticked one stays', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $p = prayerHour($app, 735);
    $shown = $private = $anon = '';
    $seen = [];
    for ($m = 0; $m < 40; $m++) {
        if ($m === 16) {
            $shown = prayFor($app, 'Shown', true);
            $private = prayFor($app, 'Private', false);
            // Who wants to stay anonymous leaves the name empty.
            $anon = $app->submissions()->submitPrayer(listener($app), TestKit::main($app), ['text' => 'Please pray for my brother.', 'name' => '', 'place' => 'Bonn'])['id'];
        }
        $app->tick()->run('test');
        $wall = $app->submissions()->wall('main');
        $seen[$m] = [array_column($wall, 'id'), $app->submissions()->collected('main')];
        TestKit::clock($app)->advance(60_000);
    }
    $run = runOf($app, (int) $p['id']);
    $read = [];
    foreach (['shown' => $shown, 'private' => $private, 'anon' => $anon] as $k => $public) {
        $read[$k] = $run[airedIn($run, (int) $app->submissions()->byPublicId($public)['id'])[0]]['item'];
    }
    check(str_contains((string) $read['shown']['payload']['text']['en'], 'Shown from Bonn'), 'read with the first name and place given, though it is on the wall');
    check(str_contains((string) $read['private']['payload']['text']['en'], 'Private from Bonn'), 'and the one without the tick too');
    $anonText = (string) $read['anon']['payload']['text']['en'];
    check(str_ends_with($anonText, ' Please pray for my brother.') && !str_contains($anonText, 'Bonn'), "no name given: read anonymously, the place left out too: $anonText");
    foreach (['shown' => $shown, 'private' => $private, 'anon' => $anon] as $k => $public) {
        eq($read[$k]['payload']['prayers'], ['p' . $public], "the app marks it as the one on air ($k)");
    }
    eq($seen[17], [[], 3], 'during the collection the wall stays empty, and only the number of requests shows');
    $from = null;
    foreach ($seen as $m => [$ids]) {
        if ($ids !== [] && $from === null) $from = $m;
    }
    check($from !== null, 'once a reading is fixed, the wall shows it');
    $wall = array_column($app->submissions()->wall('main'), null, 'id');
    eq([isset($wall['p' . $shown]), isset($wall['p' . $private]), isset($wall['p' . $anon])], [true, true, true], 'every request of the hour, ticked or not');
    foreach (['shown' => $shown, 'private' => $private, 'anon' => $anon] as $k => $public) {
        eq($wall['p' . $public]['from'] ?? null, $read[$k]['start_ms'], "from the start of its reading: the app waits for it ($k)");
        eq(array_keys($wall['p' . $public]), $k === 'anon' ? ['id', 'text', 'at', 'from'] : ['id', 'text', 'at', 'from', 'name', 'place'],
            "text, day, that moment and the first name and place given — none for one who stayed anonymous ($k)");
    }
    eq([$wall['p' . $private]['name'] ?? null, $wall['p' . $private]['place'] ?? null], ['Private', 'Bonn'], 'whose it is, as its sender gave it');
    check($app->submissions()->prayAlong($private, 'd-' . str_repeat('c', 30)), 'one without the tick can be prayed along with while the hour shows it');

    // After the hour, the usual wall again: the newest 30 with their senders' yes, the hour's among them.
    ticks($app, 50);
    $after = array_column($app->submissions()->wall('main'), null, 'id');
    check(isset($after['p' . $shown]) && !isset($after['p' . $shown]['from']), 'after the hour the usual wall, with the ticked one');
    check(!isset($after['p' . $private]) && !isset($after['p' . $anon]), 'the others leave with the hour');
    eq($app->submissions()->collected('main'), null, 'and no number of the hour');
});

test('prayer hour: prayers are taken only in the prayer time, until 12 minutes before the outro; one approved too late has missed it', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $p = prayerHour($app, 735, 60, ['after_songs' => 2]);
    $ch = TestKit::main($app);
    $program = $app->catalog()->program((int) $p['id']) ?? [];
    eq($program['allowed'], ['prayer', 'intercession'], 'a prayer hour takes prayer requests and listeners\' prayers');
    $close = $app->prayerHour()->closingAt($ch, $program, TestKit::T0 + 20 * 60_000);
    ticks($app, 18);
    eq(intakeAt($app, $p, $app->clock->nowMs()), ['open', 'closed'], 'in the collection: requests, but no prayers yet');
    check(refuses(fn() => prayAs($app, 'Early'), 'closed'), 'a prayer sent now is refused, as the minute file says');
    ticks($app, 12);
    $from = $app->prayerHour()->prayerTimeFrom($ch, $program, $app->clock->nowMs());
    check($from !== null && $from > TestKit::T0 + 20 * 60_000, 'the prayer time began with the host\'s announcement');
    eq([intakeAt($app, $p, $from - 1_000)[1], intakeAt($app, $p, $from)[1]], ['closed', 'open'], 'prayers from that moment on');
    eq([intakeAt($app, $p, $close - 18 * 60_000)[1], intakeAt($app, $p, $close - 16 * 60_000)[1], intakeAt($app, $p, $close - 11 * 60_000)[1]], ['open', 'closing', 'closed'],
        'last chance from 17 minutes before the outro, closed 12 minutes before it');
    eq(intakeAt($app, $p, $close - 14 * 60_000)[0], 'closed', 'requests close by the program\'s own settings (15 minutes before the outro)');
    $slot = json_decode((string) file_get_contents($app->publicPath(Timing::slotPath('main', $app->clock->nowMs()))), true);
    eq($slot['submissions']['intercession'] ?? null, 'open', 'the minute file offers the Pray button');
    check(prayAs($app, 'OnTime') !== '', 'a prayer sent now is accepted');

    // Sent as late as allowed, approved after the outro was planned: it missed it — and a recording goes.
    $late = prayerRow($app, $ch, ['type' => 'intercession', 'program_id' => (int) $p['id'], 'status' => 'checking', 'window_end' => $close + 2 * Timing::AFTER_SONG + Timing::OUTRO_ESTIMATE]);
    $spoken = prayerRow($app, $ch, ['type' => 'intercession', 'mode' => 'audio', 'text' => '', 'audio_ms' => 20_000, 'upload' => 'x.mp3', 'program_id' => (int) $p['id'],
        'status' => 'checking', 'window_end' => $close + 2 * Timing::AFTER_SONG + Timing::OUTRO_ESTIMATE]);
    @mkdir($app->config->dataDir . '/uploads', 0700, true);
    file_put_contents($app->config->dataDir . '/uploads/x.mp3', 'audio');
    TestKit::clock($app)->set($close - 5 * 60_000);
    ticks($app, 1);
    $app->submissions()->approve((int) $app->submissions()->byPublicId($late)['id'], [], 'test');
    $app->submissions()->approve((int) $app->submissions()->byPublicId($spoken)['id'], [], 'test');
    eq([$app->submissions()->byPublicId($late)['status'], $app->submissions()->byPublicId($spoken)['status']], ['missed', 'missed'], 'approved five minutes before the outro, they can no longer air');
    eq([$app->submissions()->byPublicId($spoken)['audio'], is_file($app->config->dataDir . '/uploads/x.mp3')], [null, false], 'and the recording was never published, and is gone');
});

test('prayer hour: what is published for the prayer time never runs ahead of the committed timeline', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $p = prayerHour($app, 735);
    $ch = TestKit::main($app);
    $program = $app->catalog()->program((int) $p['id']) ?? [];
    ticks($app, 20);
    $draft = $app->store()->one("SELECT t.* FROM timeline_items t JOIN host_breaks h ON h.id = t.host_break_id WHERE h.kind = 'prayertime' AND t.state = 'draft'");
    check($draft !== null, 'the announcement is planned, not yet fixed');
    eq($app->prayerHour()->prayerTimeFrom($ch, $program, (int) $draft['est_start'] + 60_000), null, 'a draft does not open the prayer time');
    // The generator stalls: the draft's start passes, and nothing is committed.
    TestKit::clock($app)->set((int) $draft['est_start'] + 60_000);
    eq(intakeAt($app, $p, $app->clock->nowMs())[1], 'closed', 'no prayer is taken that no minute file offered');
});

test('prayer hour: a reading not voiced in time waits briefly behind silence, never a song, and its request is read later — tried twice at most', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $p = prayerHour($app, 735);
    ticks($app, 30);
    $stuck = function (int $id) use ($app): ?int {
        for ($m = 0; $m < 10; $m++) {
            TestKit::clock($app)->advance(60_000);
            $app->drafter()->draft(TestKit::main($app));
            foreach ($app->store()->all("SELECT id, context FROM host_breaks WHERE kind = 'reading' AND state = 'pending'") as $h) {
                if (in_array($id, json_decode((string) $h['context'], true)['prayer_ids'] ?? [], true)) {
                    // Its voice never comes: the job is stuck.
                    $app->store()->query("UPDATE jobs SET status = 'done' WHERE type = 'host' AND ref_id = ?", [(int) $h['id']]);
                    return (int) $h['id'];
                }
            }
            $app->tick()->run('test');
        }
        return null;
    };
    $once = (int) $app->submissions()->byPublicId(prayFor($app, 'Once'))['id'];
    $app->runner()->runUntilBudget();
    $held = $stuck($once);
    check($held !== null, 'a reading took the request');
    ticks($app, 15);
    $item = $app->store()->one('SELECT * FROM timeline_items WHERE host_break_id = ?', [$held]) ?? [];
    eq($item['state'], 'dropped', 'it went once it had waited');
    $fillers = array_values(array_filter(TestKit::committed($app), fn($i) => !empty($i['payload']['filler']) && $i['program_id'] === (int) $p['id']));
    check($fillers !== [], 'while it waited, fillers went first');
    eq(array_values(array_unique(array_map(fn($i) => $i['type'], $fillers))), ['silence'], 'silence of the prayer time, never a song or music');
    check(max(array_map(fn($i) => $i['dur_ms'], $fillers)) <= Timing::PRAYER_FILLER, 'in short pieces');
    check(array_sum(array_map(fn($i) => $i['dur_ms'], $fillers)) <= Timing::READING_WAIT + 2 * Timing::PRAYER_FILLER, 'for about two minutes, not ten');
    $run = runOf($app, (int) $p['id']);
    eq(count(airedIn($run, $once)), 1, 'a later reading read it');
    eq($app->submissions()->get($once)['status'], 'aired', 'and it is marked aired');

    // Tried twice in this hour, and both times its voice never came: not a
    // third time — a failing voice does not loop on it.
    $twice = (int) $app->submissions()->byPublicId(prayFor($app, 'Twice'))['id'];
    $app->runner()->runUntilBudget();
    $cid = (int) TestKit::main($app)['id'];
    for ($try = 0; $try < 2; $try++) {
        $hb = $app->store()->insert('host_breaks', ['channel_id' => $cid, 'program_id' => (int) $p['id'], 'kind' => 'reading', 'state' => 'cancelled', 'source' => 'skipped:late',
            'context' => json_encode(['prayer_ids' => [$twice], 'phase' => 'new']), 'created' => $app->clock->now(), 'updated' => $app->clock->now()]);
        $draft = $app->timeline()->addDraft($cid, ['type' => 'host', 'dur_ms' => 8_000, 'est_start' => $app->clock->nowMs(), 'program_id' => (int) $p['id'],
            'block_start' => TestKit::T0 + 15 * 60_000, 'block_end' => TestKit::T0 + 75 * 60_000, 'host_break_id' => $hb, 'payload' => ['kind' => 'reading']]);
        $app->store()->update('timeline_items', ['state' => 'dropped'], 'id = ?', [(int) $draft['id']]);
    }
    eq($app->prayerHour()->state($cid, (int) $p['id'])['tries'][$twice] ?? 0, 2, 'two tries counted');
    ticks($app, 10);
    eq((int) $app->store()->value("SELECT COUNT(*) FROM host_breaks WHERE kind = 'reading' AND EXISTS (SELECT 1 FROM json_each(context, '$.prayer_ids') j WHERE j.value = ?)", [$twice]), 2,
        'no third reading');
    eq($app->submissions()->get($twice)['status'], 'approved', 'it waits');
    assertContiguous(TestKit::committed($app), 'contiguous: silence went first while a reading waited');
});

test('prayer hour: across midnight it keeps one running order, closes at its real end, and takes requests until 15 minutes before that', function () {
    // 23:20 Berlin; the prayer hour runs 23:30–00:30.
    $t0 = TestKit::T0 + (11 * 60 + 20) * 60_000;
    $app = TestKit::app([], $t0);
    TestKit::songs($app, 12);
    $p = prayerHour($app, 1410, 30);
    $cat = $app->catalog();
    $ch = TestKit::main($app);
    $next = $cat->saveDayPlan(null, (int) $ch['id'], 'Prayer after midnight', [['start_min' => 0, 'end_min' => 30, 'program_id' => $p['id']]], 'test');
    $cat->addSpecialDay((int) $ch['id'], ['name' => 'Next day', 'kind' => 'date', 'month' => 9, 'day' => 24, 'day_plan_id' => $next], 'test');
    $end = $t0 + 70 * 60_000;
    eq([intakeAt($app, $p, $end - 40 * 60_000)[0], intakeAt($app, $p, $end - 25 * 60_000)[0], intakeAt($app, $p, $end - 15 * 60_000)[0]], ['open', 'closing', 'closed'],
        'after midnight intake is measured to the outro at 00:30, not to midnight');
    ticks($app, 80);
    $run = runOf($app, (int) $p['id']);
    $count = fn(string $l) => count(array_filter($run, fn($r) => $r['label'] === $l));
    eq([$count('intro'), $count('prayertime'), $count('outro')], [1, 1, 1], 'one welcome, one prayer time and one outro');
    $outro = array_values(array_filter($run, fn($r) => $r['label'] === 'outro'))[0]['item'];
    check($outro['start_ms'] >= $end - 3 * 60_000 && $outro['start_ms'] < $end, 'the outro at 00:30, not at midnight');
    $prayerTime = array_values(array_filter($run, fn($r) => $r['label'] === 'prayertime'))[0]['item'];
    check($prayerTime['start_ms'] < $t0 + 40 * 60_000 && count(array_filter($run, fn($r) => $r['label'] === 'silence' && $r['item']['start_ms'] > $t0 + 40 * 60_000)) >= 3,
        'the prayer time went on across midnight');
    assertContiguous(TestKit::committed($app), 'contiguous');
});

test('prayer hour: a plan change or an outage mid-hour does not start it over, and requests after it air soon', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $p = prayerHour($app, 735);
    ticks($app, 30);
    $before = prayFor($app, 'BeforeChange');
    $app->catalog()->saveProgram((int) $p['id'], (int) TestKit::main($app)['id'], ['subtitle_en' => 'Changed'], 'test');
    ticks($app, 10);
    TestKit::clock($app)->advance(8 * 60_000); // the generator stops for eight minutes
    $restart = $app->clock->nowMs();
    $after = prayFor($app, 'AfterOutage');
    ticks($app, 35);
    $run = runOf($app, (int) $p['id']);
    $labels = array_column($run, 'label');
    $count = fn(string $l) => count(array_keys($labels, $l, true));
    eq([$count('intro'), $count('prayertime'), $count('outro')], [1, 1, 1], 'one welcome, one prayer time and one outro');
    eq($count('gap'), 1, 'the outage left one gap');
    $idOf = fn(string $public) => (int) $app->submissions()->byPublicId($public)['id'];
    eq(count(airedIn($run, $idOf($before))), 1, 'the request drafted before the change was read once');
    $aired = airedIn($run, $idOf($after));
    check(count($aired) === 1 && $run[$aired[0]]['item']['start_ms'] - $restart <= 10 * 60_000,
        'a request approved after the restart airs within about seven to ten minutes, not after the outage planned again');
});

test('prayer hour: planned at the last minute it still opens with the welcome', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    ticks($app, 10);
    // 12:12, two minutes from now: inside the five committed ones.
    $p = prayerHour($app, 732);
    ticks($app, 25);
    $labels = labelsOf(runOf($app, (int) $p['id']));
    $first = array_values(array_filter($labels, fn($l) => !str_ends_with($l, '*')));
    eq(array_slice($first, 0, 2), ['intro', 'bed'], 'the welcome waits for its voice instead of going');
    $fillers = array_values(array_unique(array_filter($labels, fn($l) => str_ends_with($l, '*'))));
    check($fillers === [] || $fillers === ['bed*'], 'behind this hour\'s prayer music, never a song (' . implode(',', $fillers) . ')');
    $start = TestKit::T0 + 12 * 60_000;
    $songs = array_filter(TestKit::committed($app), fn($i) => $i['type'] === 'song' && $i['start_ms'] >= $start && $i['start_ms'] < $start + 10 * 60_000);
    eq(count($songs), 0, 'and no song of the program before it once the hour has begun');
});

test('prayer hour: saved twice around its start it keeps one welcome and the whole collection', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $p = prayerHour($app, 735);
    $cid = (int) TestKit::main($app)['id'];
    ticks($app, 13);
    $app->catalog()->saveProgram((int) $p['id'], $cid, ['subtitle_en' => 'Once'], 'test');
    ticks($app, 2);
    $app->catalog()->saveProgram((int) $p['id'], $cid, ['subtitle_en' => 'Twice'], 'test');
    ticks($app, 30);
    $run = runOf($app, (int) $p['id']);
    eq(count(array_filter($run, fn($r) => $r['label'] === 'intro')), 1, 'one welcome');
    $beds = array_filter($run, fn($r) => $r['label'] === 'bed');
    check(abs(array_sum(array_map(fn($r) => $r['item']['dur_ms'], $beds)) - 600_000) < 3_000, 'the ten minutes of prayer music, not fewer');
});

test('prayer hour: with nobody listening it still opens, presents and reads what listeners sent; the encouragements and the outro wait for an audience', function () {
    $app = TestKit::app(['HOST_MIN_LISTENERS' => '99']);
    TestKit::songs($app, 12);
    $p = prayerHour($app, 735);
    $sent = '';
    for ($m = 0; $m < 80; $m++) {
        if ($m === 16) $sent = prayFor($app, 'Alone');
        $app->tick()->run('test');
        TestKit::clock($app)->advance(60_000);
    }
    $count = fn(string $kind, ?string $state = null) => (int) $app->store()->value(
        'SELECT COUNT(*) FROM host_breaks WHERE program_id = ? AND kind = ?' . ($state !== null ? ' AND state = ?' : ''),
        array_merge([(int) $p['id'], $kind], $state !== null ? [$state] : []),
    );
    eq([$count('intro', 'ready'), $count('present', 'ready'), $count('prayertime', 'ready')], [1, 1, 1], 'the welcome, the presentation and the announcement are voiced for whoever comes');
    eq([$count('outro', 'ready'), $count('encourage', 'ready')], [0, 0], 'the outro and the encouragements wait for an audience');
    check($count('encourage') <= 14, 'an encouragement tried not more often than every few quiet minutes (' . $count('encourage') . ')');
    eq([$count('reading', 'ready'), $app->submissions()->byPublicId($sent)['status']], [1, 'aired'], 'the request a listener sent is read out all the same');
});

test('prayer hour: ten requests at once are read one by one, in the order they came', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $p = prayerHour($app, 735);
    $cid = (int) TestKit::main($app)['id'];
    ticks($app, 30);
    $sent = [];
    for ($i = 0; $i < 10; $i++) $sent[] = prayFor($app, "Many$i");
    for ($m = 0; $m < 20; $m++) {
        $app->tick()->run('test');
        $tail = $app->timeline()->draftTail($cid);
        if ($tail !== null) check($tail['est_start'] + $tail['dur_ms'] <= $app->clock->nowMs() + Timing::DRAFT, "the prayer time is planned no further than eight minutes ahead (minute $m)");
        TestKit::clock($app)->advance(60_000);
    }
    $run = runOf($app, (int) $p['id']);
    $starts = [];
    foreach ($sent as $public) {
        $id = (int) $app->submissions()->byPublicId($public)['id'];
        $at = airedIn($run, $id);
        eq([count($at), $app->submissions()->get($id)['status']], [1, 'aired'], "$public read once");
        $starts[] = $run[$at[0]]['item']['start_ms'];
    }
    $sorted = $starts;
    sort($sorted);
    eq($starts, $sorted, 'in the order they came');
    foreach ($run as $r) check(count((array) ($r['context']['prayer_ids'] ?? [])) <= 1, 'one request per reading');
});

test('prayer hour: a short hour keeps 25 minutes of prayer time, and opens it for prayers', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $p = prayerHour($app, 735, 30);
    ticks($app, 50);
    $run = runOf($app, (int) $p['id']);
    $prayerTime = array_values(array_filter($run, fn($r) => $r['label'] === 'prayertime'))[0]['item'];
    $outro = array_values(array_filter($run, fn($r) => $r['label'] === 'outro'))[0]['item'];
    check($outro['start_ms'] - $prayerTime['start_ms'] >= Timing::MIN_PRAYER - 2 * 60_000, 'about 25 minutes of prayer time before the outro');
    check($outro['start_ms'] - $prayerTime['start_ms'] - Timing::PRAYER_CLOSED >= 5 * 60_000, 'minutes in which prayers are taken');
});

test('prayer hour: never the fallback that fills a plan\'s gaps', function () {
    $app = TestKit::app();
    $ch = TestKit::main($app);
    $cat = $app->catalog();
    $p = $cat->saveProgram(null, (int) $ch['id'], ['slug' => 'pray', 'title_en' => 'P', 'title_de' => 'P', 'settings' => ['format' => 'prayer']], 'test');
    check(refuses(fn() => $cat->saveChannel((int) $ch['id'], ['fallback_program_id' => $p['id']], 'test'), 'prayer_fallback'), 'not as the channel\'s fallback');
    check(refuses(fn() => $cat->saveProgram((int) $ch['fallback_program_id'], (int) $ch['id'], ['settings' => ['format' => 'prayer']], 'test'), 'prayer_fallback'), 'and the fallback cannot become one');
    $night = $cat->saveChannel(null, ['slug' => 'night', 'name_en' => 'Night', 'name_de' => 'Nacht'], 'test');
    $cat->saveProgram(null, (int) $night['id'], ['slug' => 'pray', 'title_en' => 'P', 'title_de' => 'P', 'settings' => ['format' => 'prayer']], 'test');
    $music = $cat->saveProgram(null, (int) $night['id'], ['slug' => 'music', 'title_en' => 'M', 'title_de' => 'M'], 'test');
    eq($app->resolver()->fallbackProgramId($cat->channel((int) $night['id']) ?? []), (int) $music['id'], 'by default the first program that is not a prayer hour');
    $plain = $cat->saveProgram(null, (int) $ch['id'], ['slug' => 'plain', 'title_en' => 'Plain', 'title_de' => 'Plain', 'allowed' => ['song', 'prayer', 'intercession']], 'test');
    eq($plain['allowed'], ['song', 'prayer'], 'only a prayer hour has a prayer time to take prayers in');
});

test('prayer hour: the host\'s words explain, present and invite — never a prayer or a blessing', function () {
    $app = TestKit::app();
    $hour = ['format' => 'prayer hour', 'program' => ['title' => ['en' => 'Prayer Hour', 'de' => 'Gebetsstunde']], 'time_of_day_de' => 'Abend'];
    foreach (['intro', 'present', 'prayertime', 'encourage', 'outro'] as $kind) {
        foreach ([['prayers' => 'open'], ['prayers' => 'closed']] as $intake) {
            $t = Arche\Host\Templates::texts($kind, $hour + ['intake' => $intake]);
            foreach (['en', 'de'] as $l) {
                check(!HostWriter::prays($t[$l]), "$kind ($l) does not pray: " . $t[$l]);
                check(preg_match('/segne|segen|bless/i', $t[$l]) === 0, "and blesses nobody: $kind ($l)");
            }
        }
    }
    $open = Arche\Host\Templates::texts('prayertime', $hour + ['intake' => ['prayers' => 'open']]);
    $closed = Arche\Host\Templates::texts('prayertime', $hour + ['intake' => ['prayers' => 'closed']]);
    check(str_contains($open['de'], '„Beten“') && !str_contains($closed['de'], '„Beten“'), 'it invites prayers only while they are taken');

    TestKit::songs($app, 12);
    prayerHour($app, 735, 60, ['collect' => ['songs' => 1, 'minutes' => 12]]);
    $contexts = [];
    $app->text()->respond('host_intro', function (string $system, string $user) use (&$contexts) {
        $contexts['intro'] = momentOf($user);
        return ['en' => ['text' => 'Welcome to the prayer hour.'], 'de' => ['text' => 'Willkommen zur Gebetsstunde.']];
    });
    $app->text()->respond('host_prayertime', function (string $system, string $user) use (&$contexts) {
        $contexts['prayertime'] = momentOf($user);
        return ['en' => ['text' => 'The prayer time begins.'], 'de' => ['text' => 'Die Gebetszeit beginnt.']];
    });
    for ($m = 0; $m < 40; $m++) {
        if ($m === 16) prayFor($app, 'Context');
        $app->tick()->run('test');
        TestKit::clock($app)->advance(60_000);
    }
    eq([$contexts['intro']['collect']['songs'] ?? null, $contexts['intro']['collect']['minutes'] ?? null, $contexts['intro']['intake'] ?? null], [1, 12, 'open'],
        'the welcome knows how the collection goes, and that requests are taken');
    eq([$contexts['prayertime']['requests'] ?? null, $contexts['prayertime']['intake']['prayers'] ?? null], [1, 'open'],
        'the announcement knows what was read, and that prayers are taken once it airs');
});

test('prayer hour: a moderator\'s prepared opening prayer is prayed word for word, and the welcome names them', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $p = prayerHour($app, 735);
    $prepared = $app->openingPrayers();
    $version = $app->catalog()->version();
    $mine = $prepared->addText((int) $p['id'], 'Pastor Maria', 'Lord, open our hearts in this hour. Amen.', 'Herr, öffne unsere Herzen in dieser Stunde. Amen.', 'test');
    $later = $prepared->addText((int) $p['id'], 'Brother Tom', '', 'Herr, sei bei uns. Amen.', 'test');
    eq($app->catalog()->version(), $version, 'preparing one does not change the plan (no drafts are thrown away)');
    check(refuses(fn() => $prepared->addText((int) $p['id'], 'Nobody', ' ', '', 'test'), 'missing_text'), 'a text in at least one language');
    ticks($app, 25);
    $run = runOf($app, (int) $p['id']);
    $opening = array_values(array_filter($run, fn($r) => $r['label'] === 'opening'));
    eq(count($opening), 1, 'one opening prayer');
    $hb = $app->hostBreaks()->get((int) $opening[0]['item']['host_break_id']) ?? [];
    eq([$hb['source'], $hb['texts']['en'], $hb['texts']['de']], ['moderator', 'Lord, open our hearts in this hour. Amen.', 'Herr, öffne unsere Herzen in dieser Stunde. Amen.'],
        'the oldest one, read word for word');
    $text = $app->text();
    check($text instanceof Arche\Ai\StubText && !in_array('host_opening', array_column($text->calls, 'kind'), true), 'and no AI wrote it');
    eq($run[0]['label'] === 'intro' ? ($run[0]['context']['opening_by'] ?? null) : null, 'Pastor Maria', 'the welcome names who prays the opening prayer');
    $status = array_column($prepared->list((int) $p['id']), 'status', 'id');
    eq([$status[$mine['id']], $status[$later['id']]], ['aired', 'waiting'], 'used once; the next one waits for the next airing');
    check(refuses(fn() => $prepared->delete($mine['id'], 'test'), 'not_found'), 'what was prayed stays in the list');
    $prepared->delete($later['id'], 'test');
    eq(count($prepared->list((int) $p['id'])), 1, 'one still waiting can be deleted');
});

test('prayer hour: a typed opening prayer is voiced only in the languages filled in', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $p = prayerHour($app, 735);
    $app->openingPrayers()->addText((int) $p['id'], 'Anna', '', 'Herr, sei bei uns in dieser Stunde. Amen.', 'test');
    ticks($app, 25);
    $opening = array_values(array_filter(runOf($app, (int) $p['id']), fn($r) => $r['label'] === 'opening'))[0]['item'];
    eq([array_keys($opening['payload']['audio']), array_keys($opening['payload']['text'])], [['de'], ['de']], 'only German: an English listener hears that one');
});

test('prayer hour: a moderator\'s recorded opening prayer plays as it is; thrown away by a plan change before it aired, it waits for the next plan', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $p = prayerHour($app, 735);
    $prepared = $app->openingPrayers();
    $rec = $prepared->addAudio((int) $p['id'], 'Brother Tom', silentMp3(30), 'test');
    check(refuses(fn() => $prepared->addAudio((int) $p['id'], 'Too long', silentMp3(200), 'test'), 'invalid_audio'), 'three minutes at most');
    ticks($app, 8);
    eq($prepared->list((int) $p['id'])[0]['status'], 'waiting', 'planned, not yet aired');
    $app->catalog()->saveProgram((int) $p['id'], (int) TestKit::main($app)['id'], ['subtitle_en' => 'Changed'], 'test');
    ticks($app, 20);
    $run = runOf($app, (int) $p['id']);
    eq(array_slice(labelsOf($run), 0, 3), ['intro', 'contrib', 'bed'], 'the recording in the opening prayer\'s place, then the collection');
    $openings = array_values(array_filter($run, fn($r) => $r['label'] === 'contrib'));
    eq(count($openings), 1, 'played once');
    $contrib = $openings[0]['item'];
    eq([$contrib['payload']['name'], $contrib['payload']['caption']['de'], $contrib['payload']['audio']], ['Brother Tom', 'Eröffnungsgebet', $rec['audio']], 'with the moderator\'s name, as the opening prayer');
    eq($prepared->list((int) $p['id'])[0]['status'], 'aired', 'marked aired');
    eq($run[0]['context']['opening_by'] ?? null, 'Brother Tom', 'the welcome names him');

    $path = (string) $app->media()->path((string) $rec['audio']);
    $app->store()->query('UPDATE opening_prayers SET aired_at = ?', [$app->clock->nowMs() - 91 * 86_400_000]);
    eq($prepared->purge($app->clock->nowMs() - 90 * 86_400_000), 1, 'after 90 days it goes');
    check(!is_file($path) && $prepared->list((int) $p['id']) === [], 'with its recording');
});

test('prayer hour: moderators prepare opening prayers in /mod, listeners cannot', function () {
    $app = TestKit::app();
    $p = prayerHour($app, 735, 60, [], 0);
    $path = "/api/mod/programs/{$p['id']}/opening-prayers";
    eq(call($app, 'POST', $path, ['name' => 'X', 'text_en' => 'Amen.'], authHeaders())[0], 403, 'not for listeners');
    $h = modHeaders($app);
    [$st, $d] = call($app, 'POST', $path, ['name' => 'Pastor Maria', 'text_en' => 'Lord, be with us. Amen.'], $h);
    eq([$st, $d['prayer']['mode'] ?? null, $d['prayer']['status'] ?? null], [200, 'text', 'waiting'], 'a typed one');
    $req = new Arche\Http\Request('POST', $path, [], $h, '', ['name' => 'Brother Tom'], ['audio' => ['tmp_name' => silentMp3(20), 'error' => UPLOAD_ERR_OK]]);
    $res = (new Arche\Http\Kernel($app))->handle($req);
    eq([$res->status, $res->data['prayer']['mode'] ?? null], [200, 'audio'], 'a recording');
    [$st, $d] = modGet($app, $path, [], $h);
    eq([$st, array_column($d['prayers'], 'name')], [200, ['Pastor Maria', 'Brother Tom']], 'listed, the oldest first');
    eq(call($app, 'DELETE', '/api/mod/opening-prayers/' . $d['prayers'][0]['id'], [], $h)[0], 200, 'deleted while it waits');
});

test('prayer hour: the old settings are moved to songs-then-music once, and a prayer hour takes prayers', function () {
    $app = TestKit::app();
    $store = $app->store();
    $cid = (int) TestKit::main($app)['id'];
    $insert = fn(string $slug, array $settings) => $store->insert('programs', ['channel_id' => $cid, 'slug' => $slug, 'title_en' => $slug, 'title_de' => $slug,
        'allowed' => json_encode($settings['format'] === 'prayer' ? ['prayer'] : ['song', 'prayer']), 'settings' => json_encode($settings), 'created' => 1, 'updated' => 1]);
    $music = $insert('m', ['format' => 'music', 'prayer' => ['collect' => ['with' => 'music', 'minutes' => 8, 'songs' => 2, 'bed_id' => 0], 'quiet_min' => 4, 'after_songs' => 0]]);
    $songs = $insert('s', ['format' => 'prayer', 'prayer' => ['collect' => ['with' => 'songs', 'minutes' => 8, 'songs' => 5, 'bed_id' => 0], 'quiet_min' => 4, 'after_songs' => 0]]);
    $quiet = $insert('q', ['format' => 'prayer', 'prayer' => ['collect' => ['with' => 'music', 'minutes' => 12, 'songs' => 2, 'bed_id' => 7], 'quiet_min' => 4, 'after_songs' => 0]]);
    $version = $app->catalog()->version();
    $store->set('schema', 9);
    Arche\Schema::migrate($store, $app->clock->nowMs(), 10);
    $settings = fn(int $id) => json_decode((string) $store->value('SELECT settings FROM programs WHERE id = ?', [$id]), true)['prayer']['collect'];
    eq($settings($music), ['minutes' => 10, 'songs' => 0, 'bed_id' => 0], 'a music program carries no songs for a prayer hour it might become');
    eq($settings($songs), ['minutes' => 10, 'songs' => 3, 'bed_id' => 0], 'songs instead of music: up to three, then the prayer music');
    eq($settings($quiet), ['minutes' => 12, 'songs' => 0, 'bed_id' => 7], 'prayer music only: no songs; a length the station chose stays');
    eq(json_decode((string) $store->value('SELECT allowed FROM programs WHERE id = ?', [$songs]), true), ['prayer', 'intercession'], 'a prayer hour takes listeners\' prayers too');
    eq(json_decode((string) $store->value('SELECT allowed FROM programs WHERE id = ?', [$music]), true), ['song', 'prayer'], 'a music program does not');
    eq($app->catalog()->version(), $version + 1, 'and the drafts of the old order are planned again');
});

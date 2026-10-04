<?php
declare(strict_types=1);

use Arche\Program\SubmissionWindow;
use Arche\Program\Timing;

/**
 * The prayer hour, walked minute by minute on the fixed clock: its running
 * order, when requests air, the outro's time, midnight, outages and plans
 * changed at the last moment.
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
    $settings = ['format' => 'prayer', 'prayer' => array_replace_recursive(['collect' => ['with' => 'music', 'minutes' => 8, 'bed_id' => (int) ($bed['id'] ?? 0)]], $prayer)];
    $p = $cat->saveProgram(null, $cid, ['slug' => 'prayer', 'title_en' => 'Prayer Hour', 'title_de' => 'Gebetsstunde', 'settings' => $settings], 'test');
    $plan = $cat->saveDayPlan(null, $cid, 'Prayer', [['start_min' => $startMin, 'end_min' => $startMin + $minutes, 'program_id' => $p['id']]], 'test');
    $cat->addSpecialDay($cid, ['name' => 'Prayer', 'kind' => 'date', 'month' => $month, 'day' => $day, 'day_plan_id' => $plan], 'test');
    return $p;
}

/**
 * The program's committed items, each with a label: the host's kind, the
 * phase for a prayer moment (open, read, new, again, general), else the type;
 * a filler is marked with "*".
 *
 * @return list<array{label:string,item:array<string,mixed>,context:array<string,mixed>}>
 */
function runOf(Arche\App $app, int $programId): array
{
    $out = [];
    foreach (TestKit::committed($app) as $it) {
        if ($it['program_id'] !== $programId) continue;
        $ctx = $it['host_break_id'] !== null ? hostContext($app, $it) : [];
        $label = $it['type'] === 'host'
            ? (($it['payload']['kind'] ?? '') === 'prayer' ? (string) ($ctx['phase'] ?? 'prayer') : (string) $it['payload']['kind'])
            : $it['type'];
        if (!empty($it['payload']['filler'])) $label .= '*';
        $out[] = ['label' => $label, 'item' => $it, 'context' => $ctx];
    }
    return $out;
}

/** The labels, repeats of silence and music squashed: intro, opening, invite, bed, open, silence, read, … @return list<string> */
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

/** Between two prayer moments on air, at least PRAYER_PAUSE of silence. @param list<array<string,mixed>> $run */
function assertPrayerPauses(array $run): void
{
    $quiet = null;
    foreach ($run as $r) {
        $moment = $r['item']['type'] === 'contrib' || ($r['item']['type'] === 'host' && $r['item']['payload']['kind'] === 'prayer');
        if ($moment) {
            check($quiet === null || $quiet >= Timing::PRAYER_PAUSE, 'silence before the moment at ' . gmdate('H:i:s', intdiv($r['item']['start_ms'], 1000)) . ' (' . ($quiet ?? 0) . ' ms)');
            $quiet = 0;
        } elseif ($quiet !== null && $r['item']['type'] === 'silence') {
            $quiet += $r['item']['dur_ms'];
        }
    }
}

/** @param list<array{label:string,item:array<string,mixed>,context:array<string,mixed>}> $run @return list<int> */
function idsPrayedIn(array $run, int $id): array
{
    $out = [];
    foreach ($run as $i => $r) {
        if (in_array($id, array_map('intval', (array) ($r['context']['prayer_ids'] ?? [])), true)) $out[] = $i;
    }
    return $out;
}

test('prayer hour: welcome, invitation, prayer music while requests come in, the reading, silent prayer, the outro — no opening prayer unless one was prepared', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $p = prayerHour($app, 735); // 12:15–13:15 Berlin = T0 + 15 … + 75 min
    $cid = (int) TestKit::main($app)['id'];
    $end = TestKit::T0 + 75 * 60_000;
    $sent = [];
    for ($m = 0; $m < 80; $m++) {
        // Three during the collection (about 15–23), one in the prayer time.
        if (in_array($m, [16, 18, 20, 40], true)) $sent[$m] = prayFor($app, "Name$m");
        $app->tick()->run('test');
        check($app->committer()->frontier($cid) >= $app->clock->nowMs() + Timing::COMMIT, "the fixed timeline reaches COMMIT ahead at minute $m");
        TestKit::clock($app)->advance(60_000);
    }
    $run = runOf($app, (int) $p['id']);
    $labels = labelsOf($run);
    eq(array_slice($labels, 0, 4), ['intro', 'invite', 'bed', 'open'], 'welcome, invitation, the prayer music, then the time of prayer opens: no moderator prepared an opening prayer, and the AI never prays one');
    $beds = array_values(array_filter($run, fn($r) => $r['label'] === 'bed'));
    $bedFile = (int) $app->library()->get((int) $beds[0]['item']['library_id'])['duration_ms'];
    check(abs(array_sum(array_map(fn($r) => $r['item']['dur_ms'], $beds)) - 480_000) < 3_000, 'eight minutes of prayer music');
    foreach ($beds as $i => $b) {
        check($b['item']['payload']['offset'] + $b['item']['dur_ms'] <= $bedFile, "piece $i never runs past the end of the file");
        if ($i > 0) {
            $prev = $beds[$i - 1]['item'];
            $continues = $prev['payload']['offset'] + $prev['dur_ms'];
            eq($b['item']['payload']['offset'], $continues >= $bedFile - Timing::MIN_CHUNK ? 0 : $continues, "piece $i goes on where the last one ended");
        }
    }
    $idOf = fn(string $public) => (int) $app->submissions()->byPublicId($public)['id'];
    foreach ($sent as $m => $public) {
        $at = idsPrayedIn($run, $idOf($public));
        eq(count($at), 1, "the request of minute $m is prayed for once");
        $moment = $run[$at[0]];
        eq($moment['label'], $m === 40 ? 'new' : 'read', "minute $m: " . ($m === 40 ? 'announced as new' : 'read from the wall'));
        check($moment['item']['start_ms'] - (TestKit::T0 + $m * 60_000) <= 9 * 60_000, "minute $m airs within about seven to nine minutes");
        eq($app->submissions()->byPublicId($public)['status'], 'aired', "minute $m is marked aired");
    }
    check(in_array('again', $labels, true), 'after a few quiet minutes the host prays one request from the wall again');
    assertPrayerPauses($run);
    $hosts = array_values(array_filter($run, fn($r) => $r['item']['type'] === 'host'));
    $outro = end($hosts);
    eq($outro['label'], 'outro', 'the host closes the hour');
    check($outro['item']['start_ms'] >= $end - 3 * 60_000 && $outro['item']['start_ms'] + $outro['item']['dur_ms'] <= $end + Timing::SOFT_OVERRUN, 'at the end of the hour');
    eq($outro['context']['prayed'] ?? null, 4, 'and it knows how many requests the hour prayed for');
    eq(count(array_filter($run, fn($r) => $r['label'] === 'song')), 0, 'no songs in this hour');
    eq(count(array_filter($run, fn($r) => in_array($r['label'], ['intro', 'invite', 'outro'], true))), 3, 'one welcome, invitation and outro each');
    eq(count(array_filter($run, fn($r) => $r['label'] === 'opening')), 0, 'no opening prayer');
    $silence = array_values(array_filter($run, fn($r) => $r['label'] === 'silence'))[0]['item'];
    eq($silence['payload']['label']['de'], 'Stilles Gebet', 'the silence is labelled as silent prayer');
    assertContiguous(TestKit::committed($app), 'contiguous');
});

test('prayer hour: songs instead of prayer music, and songs after the outro until the next program', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $p = prayerHour($app, 735, 60, ['collect' => ['with' => 'songs', 'songs' => 2], 'after_songs' => 2], 0);
    $end = TestKit::T0 + 75 * 60_000;
    ticks($app, 85);
    $run = runOf($app, (int) $p['id']);
    $labels = labelsOf($run);
    eq(array_slice($labels, 0, 5), ['intro', 'invite', 'song', 'song', 'open'], 'two songs while requests come in, then the time of prayer');
    $close = $app->prayerHour()->closingAt(TestKit::main($app), $app->catalog()->program((int) $p['id']) ?? [], TestKit::T0 + 20 * 60_000);
    eq($close, $end - 2 * Timing::AFTER_SONG - Timing::OUTRO_ESTIMATE, 'the outro leaves room for two songs and itself');
    $outro = (int) array_search('outro', array_column($run, 'label'), true);
    check($outro > 0 && abs($run[$outro]['item']['start_ms'] - $close) < 3 * 60_000, 'and comes then');
    $after = array_slice($run, $outro + 1);
    check(count(array_filter($after, fn($r) => $r['label'] === 'song')) >= 1, 'songs after the outro');
    eq(count(array_filter(array_slice($run, 4, $outro - 4), fn($r) => $r['label'] === 'song')), 0, 'no song in the prayer time');
    $last = end($run)['item'];
    check($last['start_ms'] + $last['dur_ms'] <= $end + Timing::SOFT_OVERRUN, 'the music ends with the program');
    assertContiguous(TestKit::committed($app), 'contiguous');
});

test('prayer hour: with no request at all the host still prays, for everyone listening', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $p = prayerHour($app, 735);
    ticks($app, 80);
    $run = runOf($app, (int) $p['id']);
    $prayers = array_values(array_filter($run, fn($r) => $r['item']['type'] === 'host' && $r['item']['payload']['kind'] === 'prayer'));
    eq($prayers[0]['label'], 'open', 'the time of prayer is opened');
    check(!empty($prayers[0]['context']['first']) && empty($prayers[0]['context']['prayer_ids']), 'without requests to read');
    check(count(array_filter($prayers, fn($r) => $r['label'] === 'general')) >= 5, 'a prayer for everyone after every few quiet minutes');
    eq(count(array_filter($prayers, fn($r) => $r['label'] === 'again')), 0, 'nothing on the wall to pray for again');
    check(in_array('outro', array_column($run, 'label'), true), 'and the outro');
    assertPrayerPauses($run);
});

test('prayer hour: a request on the wall is prayed for without its sender, and taken up again; one that is not, by name and once', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $p = prayerHour($app, 735);
    $shown = $private = '';
    for ($m = 0; $m < 60; $m++) {
        if ($m === 16) {
            $shown = prayFor($app, 'Shown', true);
            $private = prayFor($app, 'Private', false);
        }
        $app->tick()->run('test');
        TestKit::clock($app)->advance(60_000);
    }
    $run = runOf($app, (int) $p['id']);
    $idShown = (int) $app->submissions()->byPublicId($shown)['id'];
    $idPrivate = (int) $app->submissions()->byPublicId($private)['id'];
    $moment = $run[idsPrayedIn($run, $idShown)[0]];
    $requests = array_column($moment['context']['prayers'], null, 'text');
    eq($requests["Please pray for Shown's family."], ['on_wall' => true, 'text' => "Please pray for Shown's family."], 'the host is given the wall\'s request without its sender');
    eq([$requests["Please pray for Private's family."]['name'], $requests["Please pray for Private's family."]['place']], ['Private', 'Bonn'], 'the other one with first name and place');
    eq($moment['item']['payload']['prayers'], ['p' . $shown], 'the app marks only the wall\'s request "Praying now"');
    $again = array_values(array_filter($run, fn($r) => $r['label'] === 'again'));
    check($again !== [], 'the host prays a request again');
    eq(array_values(array_unique(array_map(fn($r) => (int) $r['context']['again_id'], $again))), [$idShown], 'only the one on the wall');
    eq([$again[0]['item']['payload']['prayers'], array_column($again[0]['context']['prayers'], 'on_wall')], [['p' . $shown], [true]], 'marked on the wall too, and without its sender');
    eq([count(idsPrayedIn($run, $idPrivate)), $app->submissions()->get($idPrivate)['status']], [1, 'aired'], 'the other one was prayed for on air, once');

    // Taken off the wall: never taken up again from it — not even in a
    // repeat already written; only the five committed minutes stay as they are.
    $app->submissions()->setHidden($shown, true);
    $fixed = $app->committer()->frontier((int) TestKit::main($app)['id']);
    ticks($app, 20);
    $later = array_filter(runOf($app, (int) $p['id']), fn($r) => $r['label'] === 'again' && $r['item']['start_ms'] >= $fixed);
    eq(count($later), 0, 'no repeat of a request taken down');
});

test('prayer hour: requests are taken until 15 minutes before the outro, prayer requests only; one approved too late has missed it', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $p = prayerHour($app, 735, 60, ['after_songs' => 2]);
    $ch = TestKit::main($app);
    $program = $app->catalog()->program((int) $p['id']) ?? [];
    eq($program['allowed'], ['prayer'], 'a prayer hour takes prayer requests (typed or recorded) only');
    $block = $app->resolver()->blockAt($ch, TestKit::T0 + 20 * 60_000);
    $close = $app->prayerHour()->closingAt($ch, $program, TestKit::T0 + 20 * 60_000);
    eq($close, $block['end'] - 2 * Timing::AFTER_SONG - Timing::OUTRO_ESTIMATE, 'the outro: two songs and itself before the end');
    $state = fn(int $t) => SubmissionWindow::states($app, $ch, $program, $block, $t);
    eq($state($close - 30 * 60_000), ['prayer' => 'open'], 'open half an hour before the outro');
    eq($state($close - 20 * 60_000), ['prayer' => 'closing'], 'last chance from 25 minutes before it');
    eq($state($close - 14 * 60_000), ['prayer' => 'closed'], 'closed 15 minutes before it');

    $late = prayerRow($app, $ch, ['program_id' => (int) $p['id'], 'status' => 'checking', 'window_end' => $block['end']]);
    TestKit::clock($app)->set($close - 5 * 60_000);
    ticks($app, 1);
    $app->submissions()->approve((int) $app->submissions()->byPublicId($late)['id'], [], 'test');
    eq($app->submissions()->byPublicId($late)['status'], 'missed', 'approved five minutes before the outro, it can no longer be prayed for');
});

test('prayer hour: a moment not voiced in time waits behind silence, not a song, then gives its request back', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $p = prayerHour($app, 735);
    ticks($app, 30);
    $public = prayFor($app, 'Waiting');
    $app->runner()->runUntilBudget();
    $id = (int) $app->submissions()->byPublicId($public)['id'];
    $held = null;
    for ($m = 0; $m < 10 && $held === null; $m++) {
        TestKit::clock($app)->advance(60_000);
        $app->drafter()->draft(TestKit::main($app));
        foreach ($app->store()->all("SELECT id, context FROM host_breaks WHERE kind = 'prayer' AND state = 'pending'") as $h) {
            if (in_array($id, json_decode((string) $h['context'], true)['prayer_ids'] ?? [], true)) $held = (int) $h['id'];
        }
        // Its voice never comes: the job is stuck.
        if ($held !== null) $app->store()->query("UPDATE jobs SET status = 'done' WHERE type = 'host' AND ref_id = ?", [$held]);
        $app->tick()->run('test');
    }
    check($held !== null, 'a moment took the request');
    ticks($app, 20);
    $item = $app->store()->one('SELECT * FROM timeline_items WHERE host_break_id = ?', [$held]) ?? [];
    eq($item['state'], 'dropped', 'it went once it had waited too long');
    $before = array_values(array_filter(TestKit::committed($app), fn($i) => (float) $i['seq'] > floor((float) $item['seq']) - 1 && (float) $i['seq'] < (float) $item['seq']));
    $fillers = array_filter($before, fn($i) => !empty($i['payload']['filler']));
    check(count($fillers) >= 3, 'while it waited, fillers went first (' . count($fillers) . ')');
    eq(array_values(array_unique(array_map(fn($i) => $i['type'] . ':' . $i['program_id'], $fillers))), ['silence:' . $p['id']], 'silence of the prayer hour, never a song');
    $run = runOf($app, (int) $p['id']);
    $at = idsPrayedIn($run, $id);
    check(count($at) === 1 && $run[$at[0]]['item']['host_break_id'] !== $held, 'a later moment prayed for it');
    eq($app->submissions()->get($id)['status'], 'aired', 'and it is marked aired');
    assertContiguous(TestKit::committed($app), 'contiguous: silence went first while it waited');
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
    $program = $cat->program((int) $p['id']) ?? [];
    $at = fn(int $t) => SubmissionWindow::states($app, $ch, $program, $app->resolver()->blockAt($ch, $t), $t)['prayer'] ?? null;
    eq([$at($end - 40 * 60_000), $at($end - 25 * 60_000), $at($end - 15 * 60_000)], ['open', 'closing', 'closed'],
        'after midnight intake is measured to the outro at 00:30, not to midnight');
    ticks($app, 80);
    $run = runOf($app, (int) $p['id']);
    $count = fn(string $l) => count(array_filter($run, fn($r) => $r['label'] === $l));
    eq([$count('intro'), $count('opening'), $count('invite'), $count('outro')], [1, 0, 1, 1], 'one welcome, invitation and outro (no opening prayer was prepared)');
    $outro = array_values(array_filter($run, fn($r) => $r['label'] === 'outro'))[0]['item'];
    check($outro['start_ms'] >= $end - 3 * 60_000 && $outro['start_ms'] < $end, 'the outro at 00:30, not at midnight');
    check(count(array_filter($run, fn($r) => in_array($r['label'], ['open', 'general'], true))) >= 3, 'the prayer time went on across midnight');
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
    eq([$count('intro'), $count('opening'), $count('invite'), $count('outro')], [1, 0, 1, 1], 'one welcome, invitation and outro');
    eq($count('gap'), 1, 'the outage left one gap');
    $idOf = fn(string $public) => (int) $app->submissions()->byPublicId($public)['id'];
    eq(count(idsPrayedIn($run, $idOf($before))), 1, 'the request drafted before the change was prayed for once');
    $moment = idsPrayedIn($run, $idOf($after));
    check(count($moment) === 1 && $run[$moment[0]]['item']['start_ms'] - $restart <= 9 * 60_000,
        'a request approved after the restart airs within about seven to nine minutes, not after the outage planned again');
});

test('prayer hour: planned at the last minute it still opens with the welcome and the invitation', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    ticks($app, 10);
    // 12:12, two minutes from now: inside the five committed ones.
    $p = prayerHour($app, 732);
    ticks($app, 25);
    $labels = labelsOf(runOf($app, (int) $p['id']));
    $first = array_values(array_filter($labels, fn($l) => !str_ends_with($l, '*')));
    eq(array_slice($first, 0, 3), ['intro', 'invite', 'bed'], 'the moments wait for their voice instead of going');
    $fillers = array_values(array_unique(array_filter($labels, fn($l) => str_ends_with($l, '*'))));
    check($fillers === [] || $fillers === ['bed*'], 'behind this hour\'s prayer music, never a song (' . implode(',', $fillers) . ')');
    $start = TestKit::T0 + 12 * 60_000;
    $songs = array_filter(TestKit::committed($app), fn($i) => $i['type'] === 'song' && $i['start_ms'] >= $start && $i['start_ms'] < $start + 10 * 60_000);
    eq(count($songs), 0, 'and no song of the program before it once the hour has begun');
});

test('prayer hour: saved twice around its start it keeps one opening and the whole collection', function () {
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
    $count = fn(string $l) => count(array_filter($run, fn($r) => $r['label'] === $l));
    eq([$count('intro'), $count('opening'), $count('invite')], [1, 0, 1], 'one welcome and invitation');
    $beds = array_filter($run, fn($r) => $r['label'] === 'bed');
    check(abs(array_sum(array_map(fn($r) => $r['item']['dur_ms'], $beds)) - 480_000) < 3_000, 'the eight minutes of prayer music, not fewer');
});

test('prayer hour: with nobody listening it voices only its opening and what listeners sent', function () {
    $app = TestKit::app(['HOST_MIN_LISTENERS' => '99']);
    TestKit::songs($app, 12);
    $p = prayerHour($app, 735);
    $sent = '';
    for ($m = 0; $m < 80; $m++) {
        if ($m === 30) $sent = prayFor($app, 'Alone');
        $app->tick()->run('test');
        TestKit::clock($app)->advance(60_000);
    }
    $count = fn(string $kind, ?string $state = null, ?string $phase = null) => (int) $app->store()->value(
        'SELECT COUNT(*) FROM host_breaks WHERE program_id = ? AND kind = ?'
            . ($state !== null ? ' AND state = ?' : '') . ($phase !== null ? " AND json_extract(context, '$.phase') = ?" : ''),
        array_merge([(int) $p['id'], $kind], $state !== null ? [$state] : [], $phase !== null ? [$phase] : []),
    );
    eq([$count('intro'), $count('opening'), $count('invite'), $count('outro')], [1, 0, 1, 1], 'each opening moment tried once, not again and again');
    // Written about eight minutes before the hour, before its listeners tune in.
    eq([$count('intro', 'ready'), $count('invite', 'ready')], [1, 1], 'the welcome and invitation voiced for whoever comes on time');
    eq([$count('outro', 'ready'), $count('prayer', 'ready', 'general'), $count('prayer', 'ready', 'again')], [0, 0, 0], 'the outro and the prayers for everyone wait for an audience');
    check($count('prayer', null, 'general') <= 14, 'a prayer for everyone not more often than every few quiet minutes (' . $count('prayer', null, 'general') . ')');
    eq($app->submissions()->byPublicId($sent)['status'], 'aired', 'the request a listener sent is prayed for all the same');
    $music = fn(string $where) => (int) $app->store()->value("SELECT COUNT(*) FROM host_breaks WHERE program_id != ? AND $where", [(int) $p['id']]);
    eq([$music("source = 'skipped:no_listeners'") > 0, $music("state = 'ready'")], [true, 0], 'the music program around the hour still waits for an audience');
});

test('prayer hour: ten requests at once are prayed for three at a time, with silence after each moment', function () {
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
    foreach ($sent as $public) {
        $id = (int) $app->submissions()->byPublicId($public)['id'];
        eq([count(idsPrayedIn($run, $id)), $app->submissions()->get($id)['status']], [1, 'aired'], "$public prayed for once");
    }
    foreach ($run as $r) check(count((array) ($r['context']['prayer_ids'] ?? [])) <= 3, 'three at most per moment');
    assertPrayerPauses($run);
});

test('prayer hour: a short hour keeps ten minutes of prayer, and invites no requests it could not take', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    // 20 minutes, one song after the outro: intake shuts 15 minutes before the outro, before it could open.
    $p = prayerHour($app, 735, 20, ['after_songs' => 1]);
    ticks($app, 40);
    $run = runOf($app, (int) $p['id']);
    $labels = labelsOf($run);
    check(!in_array('invite', $labels, true), 'no invitation');
    eq($labels[0], 'intro', 'the welcome still');
    $first = array_values(array_filter($run, fn($r) => $r['label'] === 'open'))[0]['item'];
    $outro = array_values(array_filter($run, fn($r) => $r['label'] === 'outro'))[0]['item'];
    check($outro['start_ms'] - $first['start_ms'] >= Timing::MIN_PRAYER - 2 * 60_000, 'about ten minutes of prayer before the outro');
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
});

test('prayer hour: the host\'s words present and invite — by name whom it may, never a prayer or a blessing', function () {
    $app = TestKit::app();
    $c = ['format' => 'prayer hour', 'phase' => 'read', 'program' => ['title' => ['en' => 'Prayer Hour', 'de' => 'Gebetsstunde']],
        'prayers' => [['on_wall' => false, 'name' => 'Ana', 'place' => 'Porto', 'text' => 'x'], ['on_wall' => true, 'text' => 'y']]];
    $t = Arche\Host\Templates::texts('prayer', $c);
    check(str_contains($t['en'], 'Ana') && str_contains($t['en'], 'prayer wall') && str_contains($t['de'], 'Ana') && str_contains($t['de'], 'Gebetswand'),
        'without the model the host still presents the requests: by name, and the wall\'s without their senders');
    $outro = Arche\Host\Templates::texts('outro', ['format' => 'prayer hour', 'time_of_day_de' => 'Abend', 'program' => $c['program']]);
    foreach (['en', 'de'] as $l) {
        check(!Arche\Host\HostWriter::prays($t[$l]) && !Arche\Host\HostWriter::prays($outro[$l]), "no prayer in the $l templates");
        check(preg_match('/segne|segen|bless/i', $outro[$l]) === 0, "and no blessing in the $l outro");
    }
    check(preg_match('/evening|morning|afternoon|night|today|tonight/i', $outro['en']) === 0, 'the English one is heard worldwide: no time of day');

    // Presenting three requests runs long: the model's text is kept, not swapped for the template —
    // unless it prays.
    TestKit::songs($app, 12);
    $p = prayerHour($app, 735);
    $long = str_repeat('These are the requests our listeners sent tonight, and you can pray for each of them. ', 10);
    $app->text()->respond('host_prayer', fn() => ['en' => ['text' => $long], 'de' => ['text' => 'Herr, wir bringen dir diese Anliegen. Amen.']]);
    for ($m = 0; $m < 30; $m++) {
        if ($m === 16) prayFor($app, 'Long');
        $app->tick()->run('test');
        TestKit::clock($app)->advance(60_000);
    }
    $read = array_values(array_filter(runOf($app, (int) $p['id']), fn($r) => $r['label'] === 'read'))[0]['item'];
    eq($read['payload']['text']['en'], trim($long), 'an 860-character presentation is spoken as written');
    check(!Arche\Host\HostWriter::prays($read['payload']['text']['de']) && str_contains($read['payload']['text']['de'], 'Gebetswand'), 'a version that prays is replaced by the template');
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
    eq(array_slice(labelsOf($run), 0, 4), ['intro', 'contrib', 'invite', 'bed'], 'the recording in the opening prayer\'s place, then the order goes on');
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

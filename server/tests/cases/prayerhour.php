<?php
declare(strict_types=1);

use Arche\Program\SubmissionWindow;
use Arche\Program\Timing;

/**
 * An MP3 of silence, $seconds long: MPEG-1 Layer III frames (128 kbit/s,
 * 44.1 kHz, 1152 samples each) are all getID3 needs for the duration.
 */
function silentMp3(float $seconds): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'bed');
    file_put_contents($tmp, str_repeat("\xFF\xFB\x90\x64" . str_repeat("\0", 413), (int) ceil($seconds * 44100 / 1152)));
    return $tmp;
}

test('prayer music: an MP3 of 20 s to 10 min becomes background music; anything else is refused', function () {
    $app = TestKit::app();
    $lib = $app->library();
    $bed = $lib->addBed(silentMp3(300), 'Worship pad', 'test');
    eq($bed['kind'], 'bed', 'stored as background music');
    check(abs($bed['duration_ms'] - 300_000) < 3000, 'with its length');
    check(str_starts_with((string) $bed['audio'], '/media/beds/') && is_file((string) $app->media()->path((string) $bed['audio'])), 'published under /media/beds');
    eq(array_column($lib->beds(), 'id'), [$bed['id']], 'offered to the program settings');
    eq($lib->fingerprint(), '0:0', 'not a song: the music selection does not change');
    check(refuses(fn() => $lib->addBed(silentMp3(10), 'Too short', 'test'), 'invalid_bed'), 'shorter than 20 s is refused');
    check(refuses(fn() => $lib->addBed(silentMp3(620), 'Too long', 'test'), 'invalid_bed'), 'longer than 10 min is refused');
    $notMp3 = tempnam(sys_get_temp_dir(), 'bed');
    file_put_contents($notMp3, str_repeat('not audio ', 100));
    check(refuses(fn() => $lib->addBed($notMp3, 'Text', 'test'), 'invalid_bed'), 'a file that is not an MP3 is refused');
});

/**
 * A prayer hour on T0's day (Berlin) from $startMin for $minutes, the
 * regular program before and after. Background music of $bedSeconds (0: none).
 *
 * @param array<string,mixed> $prayer settings over the defaults
 * @return array<string,mixed> the program
 */
function prayerHour(Arche\App $app, int $startMin, int $minutes = 60, array $prayer = [], int $bedSeconds = 300, int $month = 9, int $day = 23): array
{
    $cat = $app->catalog();
    $cid = (int) TestKit::main($app)['id'];
    $bed = $bedSeconds > 0 ? $app->library()->addBed(silentMp3($bedSeconds), 'Pad', 'test') : null;
    $settings = ['format' => 'prayer', 'prayer' => array_replace_recursive(['collect' => ['with' => 'music', 'minutes' => 8, 'bed_id' => (int) ($bed['id'] ?? 0)]], $prayer),
        'wall' => ['enabled' => true, 'keep_min' => 30]];
    $p = $cat->saveProgram(null, $cid, ['slug' => 'prayer', 'title_en' => 'Prayer Hour', 'title_de' => 'Gebetsstunde', 'allowed' => ['song', 'prayer'], 'settings' => $settings], 'test');
    $plan = $cat->saveDayPlan(null, $cid, 'Prayer', [['start_min' => $startMin, 'end_min' => $startMin + $minutes, 'program_id' => $p['id']]], 'test');
    $cat->addSpecialDay($cid, ['name' => 'Prayer', 'kind' => 'date', 'month' => $month, 'day' => $day, 'day_plan_id' => $plan], 'test');
    return $p;
}

/**
 * The program's committed items, each with a label: the host's kind, the
 * phase for a prayer moment (read, new, again, general), else the type.
 *
 * @return list<array{label:string,item:array<string,mixed>,context:array<string,mixed>}>
 */
function runOf(Arche\App $app, int $programId): array
{
    $out = [];
    foreach (TestKit::committed($app) as $it) {
        if ($it['program_id'] !== $programId) continue;
        $ctx = $it['host_break_id'] !== null ? ($app->hostBreaks()->get($it['host_break_id'])['context'] ?? []) : [];
        $label = $it['type'] === 'host' ? (($it['payload']['kind'] ?? '') === 'prayer' ? (string) ($ctx['phase'] ?? 'prayer') : (string) $it['payload']['kind']) : $it['type'];
        $out[] = ['label' => $label, 'item' => $it, 'context' => $ctx];
    }
    return $out;
}

/** The labels with repeats of silence and music squashed: intro, opening, invite, bed, read, silence, new, … @return list<string> */
function labelsOf(array $run): array
{
    $out = [];
    foreach ($run as $r) {
        if (in_array($r['label'], ['silence', 'bed'], true) && end($out) === $r['label']) continue;
        $out[] = $r['label'];
    }
    return $out;
}

/** Sends a prayer request from a new listener (one identity may send three an hour). @return string public id */
function prayFor(Arche\App $app, string $name, bool $wall = true): string
{
    return $app->submissions()->submitPrayer(listener($app), TestKit::main($app),
        ['text' => "Please pray for $name's family.", 'name' => $name, 'place' => 'Bonn', 'consent_air' => $wall ? '1' : ''])['id'];
}

test('prayer hour: welcome, opening prayer, invitation, prayer music while requests arrive, the reading, silent prayer, the outro', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $p = prayerHour($app, 735); // 12:15–13:15 Berlin = T0 + 15 … + 75 min
    $cid = (int) TestKit::main($app)['id'];
    $end = TestKit::T0 + 75 * 60_000;
    $sent = [];
    for ($m = 0; $m < 80; $m++) {
        // Three during the collection time (it runs about 15–23), one in silent prayer.
        if (in_array($m, [15, 18, 20, 40], true)) $sent[$m] = prayFor($app, "Name$m");
        $app->tick()->run('test');
        check($app->committer()->frontier($cid) >= $app->clock->nowMs() + Timing::COMMIT, "the fixed timeline reaches COMMIT ahead at minute $m");
        TestKit::clock($app)->advance(60_000);
    }
    $run = runOf($app, (int) $p['id']);
    $labels = labelsOf($run);
    eq(array_slice($labels, 0, 4), ['intro', 'opening', 'invite', 'bed'], 'welcome, opening prayer, invitation, then the prayer music');
    $beds = array_filter($run, fn($r) => $r['label'] === 'bed');
    check(abs(array_sum(array_map(fn($r) => $r['item']['dur_ms'], $beds)) - 480_000) < 3_000, 'the music plays for the eight minutes of the collection time');
    check(max(array_map(fn($r) => $r['item']['dur_ms'], $beds)) <= 300_000, 'no piece of music is longer than its file');
    eq($labels[4], 'read', 'then the host reads the wall');
    $ids = fn(array $r) => array_map('intval', (array) ($r['context']['prayer_ids'] ?? []));
    $idOf = fn(string $public) => (int) $app->submissions()->byPublicId($public)['id'];
    $firstRead = array_values(array_filter($run, fn($r) => $r['label'] === 'read'))[0];
    check(!empty($firstRead['context']['first']), 'the first moment opens the time of prayer');
    eq($ids($firstRead), [$idOf($sent[15])], 'with what was approved when it was planned, seven minutes before');
    foreach ([18, 20] as $m) {
        $moment = array_values(array_filter($run, fn($r) => in_array($idOf($sent[$m]), $ids($r), true)))[0];
        eq($moment['label'], 'read', "the request of minute $m, sent during the collection time, is read from the wall");
        check($moment['item']['start_ms'] <= TestKit::T0 + ($m + 9) * 60_000, "soon after (minute $m)");
    }
    check(in_array('silence', $labels, true) && in_array('new', $labels, true), 'silent prayer, and a request that came in later is announced as new');
    $read = [];
    foreach ($run as $r) foreach ($ids($r) as $id) $read[] = $id;
    foreach ($sent as $m => $public) {
        eq(count(array_keys($read, $idOf($public), true)), 1, "request of minute $m prayed for once");
        eq($app->submissions()->byPublicId($public)['status'], 'aired', "request of minute $m is marked aired");
    }
    $late = array_values(array_filter($run, fn($r) => in_array($idOf($sent[40]), $ids($r), true)))[0];
    eq($late['label'], 'new', 'a request sent during silent prayer is announced as new');
    check($late['item']['start_ms'] - (TestKit::T0 + 40 * 60_000) <= 9 * 60_000, 'about seven to eight minutes after it was sent');
    check(in_array('again', $labels, true), 'after a few quiet minutes the host prays one request from the wall again');
    $hosts = array_values(array_filter($run, fn($r) => $r['item']['type'] === 'host'));
    $outro = end($hosts);
    eq($outro['label'], 'outro', 'the host closes the hour');
    check($outro['item']['start_ms'] >= $end - 3 * 60_000 && $outro['item']['start_ms'] + $outro['item']['dur_ms'] <= $end + Timing::SOFT_OVERRUN, 'at the end of the hour');
    $after = array_slice($run, (int) array_search($outro, $run, true) + 1);
    check(array_sum(array_map(fn($r) => $r['item']['dur_ms'], $after)) < Timing::MIN_SONG && !array_filter($after, fn($r) => $r['label'] !== 'stage'), 'then only a moment until the next program');
    eq(count(array_filter($run, fn($r) => $r['label'] === 'song')), 0, 'no songs in this prayer hour');
    eq(count(array_filter($run, fn($r) => in_array($r['label'], ['opening', 'invite', 'outro'], true))), 3, 'one opening prayer, one invitation, one outro');
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
    eq(array_slice($labels, 0, 6), ['intro', 'opening', 'invite', 'song', 'song', 'read'], 'two songs while requests come in, then the reading');
    $outro = (int) array_search('outro', array_column($run, 'label'), true);
    check($outro > 0, 'an outro');
    $after = array_slice($run, $outro + 1);
    check(count(array_filter($after, fn($r) => $r['label'] === 'song')) >= 1, 'songs after the outro');
    eq(count(array_filter(array_slice($run, 5, $outro - 5), fn($r) => $r['label'] === 'song')), 0, 'no song between the reading and the outro');
    $last = end($run)['item'];
    check($last['start_ms'] + $last['dur_ms'] <= $end + Timing::SOFT_OVERRUN, 'the music ends with the program');
    check($run[$outro]['item']['start_ms'] <= $end - 2 * 240_000 + 60_000, 'the outro leaves room for two songs');
    assertContiguous(TestKit::committed($app), 'contiguous');
});

test('prayer hour: with no request at all the host still prays, for everyone listening', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $p = prayerHour($app, 735);
    ticks($app, 80);
    $run = runOf($app, (int) $p['id']);
    $prayers = array_values(array_filter($run, fn($r) => $r['item']['type'] === 'host' && $r['item']['payload']['kind'] === 'prayer'));
    eq($prayers[0]['label'], 'read', 'the time of prayer is opened');
    check(!empty($prayers[0]['context']['first']) && empty($prayers[0]['context']['prayer_ids']), 'without requests to read');
    $general = array_filter($prayers, fn($r) => $r['label'] === 'general');
    check(count($general) >= 5, 'a prayer for the world after every few quiet minutes');
    eq(count(array_filter($prayers, fn($r) => $r['label'] === 'again')), 0, 'nothing on the wall to pray for again');
    eq(end($run)['label'] === 'outro' || in_array('outro', array_column($run, 'label'), true), true, 'and the outro');
});

test('prayer hour: a request is prayed for again only when its sender agreed to show it on the wall', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $p = prayerHour($app, 735);
    $shown = $hidden = '';
    for ($m = 0; $m < 60; $m++) {
        if ($m === 15) {
            $shown = prayFor($app, 'Shown', true);
            $hidden = prayFor($app, 'Hidden', false);
        }
        $app->tick()->run('test');
        TestKit::clock($app)->advance(60_000);
    }
    $again = array_values(array_filter(runOf($app, (int) $p['id']), fn($r) => $r['label'] === 'again'));
    check($again !== [], 'the host prays a request again');
    $ids = array_unique(array_map(fn($r) => (int) $r['context']['again_id'], $again));
    eq(array_values($ids), [(int) $app->submissions()->byPublicId($shown)['id']], 'only the one shown on the wall');
    eq($app->submissions()->byPublicId($hidden)['status'], 'aired', 'the other one was still prayed for on air');
});

test('prayer hour: requests are taken until 15 minutes before the outro, which comes early enough for the songs after it', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $p = prayerHour($app, 735, 60, ['after_songs' => 2]);
    $ch = TestKit::main($app);
    $block = $app->resolver()->blockAt($ch, TestKit::T0 + 20 * 60_000);
    $closeAt = $app->prayerHour()->closingAt($ch, $app->catalog()->program((int) $p['id']), $block);
    eq($closeAt, $block['end'] - Timing::OUTRO_ESTIMATE - 2 * 240_000, 'the outro: two songs and itself before the end');
    $state = fn(int $t) => SubmissionWindow::states($app, $ch, $app->catalog()->program((int) $p['id']), $block, $t)['prayer'] ?? null;
    eq($state($closeAt - 30 * 60_000), 'open', 'open half an hour before the outro');
    eq($state($closeAt - 20 * 60_000), 'closing', 'last chance from 25 minutes before it');
    eq($state($closeAt - 14 * 60_000), 'closed', 'closed 15 minutes before it');
    eq(array_keys(SubmissionWindow::states($app, $ch, $app->catalog()->program((int) $p['id']), $block, $closeAt - 30 * 60_000)), ['prayer'], 'a prayer hour takes prayer requests only');
});

test('prayer hour: a prayer moment not voiced in time waits behind silence, then gives its requests back', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $p = prayerHour($app, 735);
    ticks($app, 15);
    $public = prayFor($app, 'Waiting');
    $id = 0;
    $held = null;
    for ($m = 15; $m < 40 && $held === null; $m++) {
        // Draft first, so the moment can be caught before the tick voices it.
        $app->drafter()->draft(TestKit::main($app));
        $id = (int) $app->submissions()->byPublicId($public)['id'];
        // The moment that holds the request: its voice never comes (a stuck job).
        foreach ($app->store()->all("SELECT h.id, h.context FROM host_breaks h WHERE h.kind = 'prayer' AND h.state = 'pending'") as $h) {
            if (in_array($id, (array) (json_decode((string) $h['context'], true)['prayer_ids'] ?? []), true)) $held = (int) $h['id'];
        }
        if ($held !== null) $app->store()->query("UPDATE jobs SET status = 'done' WHERE type = 'host' AND ref_id = ?", [$held]);
        $app->tick()->run('test');
        TestKit::clock($app)->advance(60_000);
    }
    check($held !== null, 'the request was taken into a prayer moment');
    ticks($app, 20);
    $item = $app->store()->one('SELECT * FROM timeline_items WHERE host_break_id = ?', [$held]);
    eq($item['state'], 'dropped', 'dropped once it waited too long');
    $waited = (int) $app->store()->value(
        "SELECT COUNT(*) FROM timeline_items WHERE state = 'committed' AND type = 'silence' AND seq > ? AND seq < ?",
        [floor((float) $item['seq']) - 1, (float) $item['seq']],
    );
    check($waited >= 3, "while it waited, silence was put before it ($waited minutes), not a song");
    $moments = array_values(array_filter(runOf($app, (int) $p['id']), fn($r) => in_array($id, array_map('intval', (array) ($r['context']['prayer_ids'] ?? [])), true)));
    check(count($moments) === 1 && $moments[0]['item']['host_break_id'] !== $held, 'prayed for in a later moment instead');
    eq($app->submissions()->byPublicId($public)['status'], 'aired', 'and marked aired');
    assertContiguous(TestKit::committed($app), 'contiguous: silence went first while it waited');
});

test('prayer hour: across midnight it keeps one running order and closes at its real end', function () {
    // 23:20 Berlin; the prayer hour runs 23:30–00:30.
    $t0 = TestKit::T0 + (11 * 60 + 20) * 60_000;
    $app = TestKit::app([], $t0);
    TestKit::songs($app, 12);
    $p = prayerHour($app, 1410, 30);
    $cat = $app->catalog();
    $cid = (int) TestKit::main($app)['id'];
    $next = $cat->saveDayPlan(null, $cid, 'Prayer after midnight', [['start_min' => 0, 'end_min' => 30, 'program_id' => $p['id']]], 'test');
    $cat->addSpecialDay($cid, ['name' => 'Next day', 'kind' => 'date', 'month' => 9, 'day' => 24, 'day_plan_id' => $next], 'test');
    ticks($app, 80);
    $run = runOf($app, (int) $p['id']);
    $count = fn(string $l) => count(array_filter($run, fn($r) => $r['label'] === $l));
    eq([$count('intro'), $count('opening'), $count('invite'), $count('outro')], [1, 1, 1, 1], 'one welcome, opening prayer, invitation and outro');
    $outro = array_values(array_filter($run, fn($r) => $r['label'] === 'outro'))[0]['item'];
    $end = $t0 + 70 * 60_000;
    check($outro['start_ms'] >= $end - 3 * 60_000 && $outro['start_ms'] < $end, 'the outro at 00:30, not at midnight');
});

test('prayer hour: a plan change or an outage mid-hour does not start it over', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $p = prayerHour($app, 735);
    ticks($app, 30);
    $app->catalog()->saveProgram((int) $p['id'], (int) TestKit::main($app)['id'], ['subtitle_en' => 'Changed'], 'test');
    ticks($app, 10);
    TestKit::clock($app)->advance(10 * 60_000); // the generator stops for ten minutes
    ticks($app, 40);
    $labels = array_column(runOf($app, (int) $p['id']), 'label');
    $count = fn(string $l) => count(array_keys($labels, $l, true));
    eq([$count('opening'), $count('invite'), $count('outro')], [1, 1, 1], 'one opening prayer, one invitation, one outro');
    check($count('gap') === 1, 'the outage left one gap');
    check($count('silence') > 10, 'silent prayer went on around it');
});

/** live.json as the app reads it. @return array<string,mixed> */
function liveJson(Arche\App $app): array
{
    return json_decode((string) file_get_contents($app->publicPath('program/main/live.json')), true);
}

test('prayer wall: the hour\'s requests whose senders agreed to show them, while it is on air and for the minutes it keeps them', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    prayerHour($app, 735);
    ticks($app, 16);
    eq(liveJson($app)['wall']['entries'] ?? null, [], 'an empty wall when the hour begins');
    $shown = prayFor($app, 'Shown', true);
    prayFor($app, 'Private', false);
    ticks($app, 2);
    $wall = liveJson($app)['wall'];
    eq([$wall['p'], $wall['open'], $wall['title']['de']], ['prayer', true, 'Gebetsstunde'], 'the wall of the prayer hour on air');
    eq(array_column($wall['entries'], 'id'), ['p' . $shown], 'only the request its sender agreed to show');
    eq([$wall['entries'][0]['name'], $wall['entries'][0]['place'], $wall['entries'][0]['prayed']], ['Shown', 'Bonn', false], 'with first name and place, not yet prayed for');
    ticks($app, 20);
    check(liveJson($app)['wall']['entries'][0]['prayed'], 'marked once the host prayed for it');
    ticks($app, 45); // the hour ended at +75; it keeps its wall for 30 minutes
    $wall = liveJson($app)['wall'];
    eq([$wall['open'], count($wall['entries'])], [false, 1], 'still shown after the hour, for the minutes it keeps it');
    ticks($app, 30);
    eq(liveJson($app)['wall'], null, 'and then gone');
    $program = $app->catalog()->program((int) TestKit::main($app)['fallback_program_id']);
    check(!$program['settings']['wall']['enabled'], 'a music program has no wall unless it asks for one');
});

test('prayer wall: a moderator takes a request down at once — off the wall and the community voices — or puts it back', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    prayerHour($app, 735);
    ticks($app, 16);
    $public = prayFor($app, 'Taken');
    ticks($app, 2);
    check(in_array('p' . $public, array_column($app->presence()->voices('main'), 'id'), true), 'a community voice');
    $h = modHeaders($app);
    eq(call($app, 'POST', "/api/mod/review/$public/wall", ['hidden' => true], $h)[0], 200, 'taken down');
    eq(liveJson($app)['wall']['entries'], [], 'off the wall in live.json right away');
    check(!in_array('p' . $public, array_column($app->presence()->voices('main'), 'id'), true), 'and no longer a voice');
    check(!$app->submissions()->prayAlong($public, 'dev1'), 'nobody can pray along with it any more');
    [$st, $data] = modGet($app, '/api/mod/review', ['status' => 'all'], $h);
    eq([$st, $data['items'][0]['shown']], [200, false], 'the moderators see it is taken down');
    call($app, 'POST', "/api/mod/review/$public/wall", ['hidden' => false], $h);
    eq(array_column(liveJson($app)['wall']['entries'], 'id'), ['p' . $public], 'and put it back');
    check(call($app, 'POST', "/api/mod/review/$public/wall", ['hidden' => true], authHeaders())[0] === 403, 'listeners cannot');
});

test('praying along: 🙏 on a request counts once per listener; who prayed for what is forgotten when it is no longer shown', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    prayerHour($app, 735);
    ticks($app, 16);
    $public = prayFor($app, 'Counted');
    ticks($app, 2);
    $a = authHeaders();
    $b = authHeaders();
    foreach ([$a, $a, $b] as $h) call($app, 'POST', '/api/pulse', ['channel' => 'main', 'voices' => [['voice' => 'p' . $public, 'kind' => 'pray']]], $h);
    call($app, 'POST', '/api/pulse', ['channel' => 'main', 'voices' => [['voice' => 'p' . $public, 'kind' => 'heart']]], authHeaders());
    eq((int) $app->submissions()->byPublicId($public)['prayed_with'], 2, 'two listeners prayed along; the same one twice counts once, a heart not at all');
    ticks($app, 1);
    eq(liveJson($app)['wall']['entries'][0]['n'], 2, 'the wall shows it');
    eq($app->submissions()->publicView($app->submissions()->byPublicId($public))['prayedWith'], 2, 'and so does the sender\'s own list');
    $who = (string) $app->store()->value('SELECT who FROM prayer_along LIMIT 1');
    check(!in_array($who, array_column($app->store()->all('SELECT device FROM presence'), 'device'), true), 'the rows cannot be joined to presence');
    ticks($app, 140); // the hour and the 30 minutes it keeps its wall, plus the two hours as a voice
    eq((int) $app->store()->value('SELECT COUNT(*) FROM prayer_along'), 0, 'who prayed for it is forgotten');
    eq((int) $app->submissions()->byPublicId($public)['prayed_with'], 2, 'the number stays');
});

test('prayer hour: a moderator\'s prepared opening prayer is prayed instead of the AI\'s, read word for word', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $p = prayerHour($app, 735);
    $prepared = $app->preparedPrayers();
    $mine = $prepared->addText((int) $p['id'], 'Pastor Maria', 'Lord, open our hearts in this hour. Amen.', 'Herr, öffne unsere Herzen in dieser Stunde. Amen.', 'test');
    $later = $prepared->addText((int) $p['id'], 'Brother Tom', '', 'Herr, sei bei uns. Amen.', 'test');
    ticks($app, 25);
    $run = runOf($app, (int) $p['id']);
    $opening = array_values(array_filter($run, fn($r) => $r['label'] === 'opening'));
    eq(count($opening), 1, 'one opening prayer');
    $hb = $app->hostBreaks()->get((int) $opening[0]['item']['host_break_id']);
    eq([$hb['source'], $hb['texts']['en'], $hb['texts']['de']], ['moderator', 'Lord, open our hearts in this hour. Amen.', 'Herr, öffne unsere Herzen in dieser Stunde. Amen.'], 'the oldest one, read word for word — not written by the AI');
    $intro = array_values(array_filter($run, fn($r) => $r['label'] === 'intro'))[0]['item'];
    eq(hostContext($app, $intro)['opening_by'] ?? null, 'Pastor Maria', 'the welcome names who prays the opening prayer');
    $status = array_column($prepared->list((int) $p['id']), 'status', 'id');
    eq([$status[$mine['id']], $status[$later['id']]], ['aired', 'waiting'], 'used once; the next one waits for the next airing');
    check(refuses(fn() => $prepared->delete($mine['id'], 'test'), 'not_found'), 'what was prayed stays in the list');
    $prepared->delete($later['id'], 'test');
    eq(count($prepared->list((int) $p['id'])), 1, 'one still waiting can be deleted');
});

test('prayer hour: a moderator\'s recorded opening prayer plays as it is', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $p = prayerHour($app, 735);
    $rec = $app->preparedPrayers()->addAudio((int) $p['id'], 'Brother Tom', silentMp3(30), 'test');
    check(refuses(fn() => $app->preparedPrayers()->addAudio((int) $p['id'], 'Too long', silentMp3(200), 'test'), 'invalid_audio'), 'three minutes at most');
    ticks($app, 25);
    $run = runOf($app, (int) $p['id']);
    $labels = labelsOf($run);
    eq(array_slice($labels, 0, 4), ['intro', 'contrib', 'invite', 'bed'], 'the recording in the opening prayer\'s place, then the running order goes on');
    $contrib = array_values(array_filter($run, fn($r) => $r['label'] === 'contrib'))[0]['item'];
    eq([$contrib['payload']['name'], $contrib['payload']['caption']['de'], $contrib['payload']['audio']], ['Brother Tom', 'Eröffnungsgebet', $rec['audio']], 'with the moderator\'s name, as the opening prayer');
    eq($app->preparedPrayers()->list((int) $p['id'])[0]['status'], 'aired', 'marked aired');
    $intro = array_values(array_filter($run, fn($r) => $r['label'] === 'intro'))[0]['item'];
    eq(hostContext($app, $intro)['opening_by'] ?? null, 'Brother Tom', 'the welcome names him');
});

test('prayer hour: planned at the last minute it still opens with the welcome, the opening prayer and the invitation', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    ticks($app, 10);
    // Starts in two minutes, well inside the five committed ones.
    $p = prayerHour($app, 722);
    ticks($app, 25);
    $labels = labelsOf(runOf($app, (int) $p['id']));
    $first = array_values(array_filter($labels, fn($l) => $l !== 'silence'));
    eq(array_slice($first, 0, 4), ['intro', 'opening', 'invite', 'bed'], 'the moments wait for their voice — a minute of silence at most — instead of going');
    check(array_search('intro', $labels, true) <= 1, 'at most one pause before the welcome');
});

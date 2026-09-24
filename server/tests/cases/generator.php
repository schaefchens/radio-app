<?php
declare(strict_types=1);

use Arche\Program\Timing;

/** Assert the committed timeline is contiguous from its first to its last item. */
function assertContiguous(array $items, string $label): void
{
    for ($i = 1; $i < count($items); $i++) {
        $prevEnd = $items[$i - 1]['start_ms'] + $items[$i - 1]['dur_ms'];
        if ($items[$i]['start_ms'] !== $prevEnd) {
            throw new RuntimeException("FAIL: $label — gap/overlap at #$i: prev ends $prevEnd, next starts {$items[$i]['start_ms']}");
        }
    }
}

test('generator: first tick anchors, commits COMMIT ahead and publishes minute files', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $app->tick()->run('test');
    $now = TestKit::T0;
    $items = TestKit::committed($app);
    check(count($items) >= 2, 'items committed');
    eq($items[0]['type'], 'gap', 'a fresh timeline starts with a gap to the anchor');
    eq($items[0]['start_ms'], Timing::floorMinute($now), 'gap starts at the current minute');
    eq($items[1]['start_ms'], Timing::floorMinute($now) + 120_000, 'anchor = next minute + 60 s');
    assertContiguous($items, 'contiguous');
    $frontier = $app->committer()->frontier((int) TestKit::main($app)['id']);
    check($frontier >= $now + Timing::COMMIT, 'frontier ≥ now + COMMIT');

    for ($m = Timing::floorMinute($now); $m <= Timing::floorMinute($now + Timing::LEAD); $m += Timing::MINUTE) {
        $path = $app->publicPath(Timing::slotPath('main', $m));
        check(is_file($path), 'minute file ' . gmdate('H:i', intdiv($m, 1000)));
        $slot = json_decode((string) file_get_contents($path), true);
        eq($slot['t'], $m, 'file t');
        $covered = 0;
        foreach ($slot['items'] as $it) {
            check($it['start'] < $m + Timing::WINDOW && $it['start'] + $it['dur'] > $m, 'item overlaps the window');
            $covered = max($covered, $it['start'] + $it['dur']);
        }
        check($covered >= $m + Timing::WINDOW, 'window fully covered');
    }
    check(!is_file($app->publicPath(Timing::slotPath('main', Timing::floorMinute($now + Timing::LEAD) + Timing::MINUTE))), 'nothing beyond the lead');
    check(is_file($app->publicPath('program/main/live.json')), 'live.json');
    check(is_file($app->publicPath('program/channels.json')), 'channels.json');
});

test('generator: public folders are readable by the web server whatever the umask', function () {
    // The host runs PHP with umask 0027 and serves files as another user: a
    // 0750 folder under /program turned every minute file into a 403.
    $old = umask(0027);
    try {
        $app = TestKit::app();
        TestKit::songs($app, 12);
        $app->tick()->run('test');
        $mode = fn(string $rel): int => fileperms($app->publicPath($rel)) & 0777;
        $day = dirname(Timing::slotPath('main', TestKit::T0));
        foreach (['program/main', 'program/main/slots', $day, 'program/main/days'] as $rel) eq($mode($rel), 0755, "$rel is 0755");
        eq($mode(Timing::slotPath('main', TestKit::T0)), 0644, 'a minute file is 0644');

        // Folders made before this fix are repaired by the next tick.
        chmod($app->publicPath('program/main/slots'), 0750);
        chmod($app->publicPath('program/main'), 0750);
        TestKit::clock($app)->advance(61_000);
        $app->tick()->run('test');
        eq($mode('program/main/slots'), 0755, 'slots/ repaired');
        eq($mode('program/main'), 0755, 'the channel folder repaired');
    } finally {
        umask($old);
    }
});

test('generator: published minute files are never rewritten; new minutes follow', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $app->tick()->run('test');
    $path = $app->publicPath(Timing::slotPath('main', TestKit::T0));
    $before = file_get_contents($path);
    TestKit::clock($app)->advance(61_000);
    $app->tick()->run('test');
    eq(file_get_contents($path), $before, 'unchanged');
    check(is_file($app->publicPath(Timing::slotPath('main', TestKit::T0 + Timing::LEAD + Timing::MINUTE))), 'the next minute exists');
    assertContiguous(TestKit::committed($app), 'still contiguous');
});

test('generator: an empty library still yields a contiguous, published timeline', function () {
    $app = TestKit::app();
    $app->tick()->run('test');
    $items = TestKit::committed($app);
    assertContiguous($items, 'contiguous');
    $types = array_unique(array_column($items, 'type'));
    check(!in_array('song', $types, true), 'no songs');
    check(in_array('stage', $types, true), 'stage placeholders');
    check(is_file($app->publicPath(Timing::slotPath('main', TestKit::T0))), 'published');
});

test('generator: the first songs of a new station air within a tick, not after the placeholders', function () {
    $app = TestKit::app(['HOST_MIN_LISTENERS' => '99']);
    $app->tick()->run('test');
    $cid = (int) TestKit::main($app)['id'];
    check($app->store()->value("SELECT COUNT(*) FROM timeline_items WHERE channel_id = ? AND state = 'draft'", [$cid]) > 0, 'placeholders drafted ahead');
    check(!is_file($app->publicPath('program/main/evergreen-x.json')) && ($app->store()->get('evergreen:' . $cid) === null), 'no fallback loop without songs');

    TestKit::clock($app)->advance(61_000);
    TestKit::songs($app, 6);
    $app->tick()->run('test');

    // The committed placeholders are skipped by clients from now on…
    $live = json_decode((string) file_get_contents($app->publicPath('program/main/live.json')), true);
    $placeholders = $app->store()->all(
        "SELECT uid FROM timeline_items WHERE channel_id = ? AND state = 'committed' AND type = 'stage' AND start_ms + dur_ms > ?",
        [$cid, $app->clock->nowMs()],
    );
    check(count($placeholders) > 0, 'placeholders were already committed');
    foreach ($placeholders as $p) check(in_array($p['uid'], $live['blocked'], true), 'placeholder ' . $p['uid'] . ' is blocked');
    // …they hear the fallback loop of the new songs instead…
    $channels = json_decode((string) file_get_contents($app->publicPath('program/channels.json')), true);
    $loop = $channels['channels'][0]['evergreen'];
    check(is_string($loop) && is_file($app->publicPath(ltrim($loop, '/'))), 'channels.json points at a fallback loop in the same tick');
    eq(count(json_decode((string) file_get_contents($app->publicPath(ltrim($loop, '/'))), true)['items']), 6, 'the loop has the new songs');
    // …and the plan after the committed part is songs, not more placeholders.
    $drafts = $app->store()->all("SELECT type FROM timeline_items WHERE channel_id = ? AND state = 'draft'", [$cid]);
    check(in_array('song', array_column($drafts, 'type'), true), 'songs drafted');
    check(!in_array('stage', array_column($drafts, 'type'), true), 'no placeholder drafts left');
    check($app->store()->get("waiting:$cid") === null, 'waiting flag cleared');
    assertContiguous(TestKit::committed($app), 'still contiguous');
});

test('generator: a plan that repeats songs because the library was small is redone when songs are added', function () {
    $app = TestKit::app(['HOST_MIN_LISTENERS' => '99']);
    $cid = (int) TestKit::main($app)['id'];
    $songs = fn(string $state): array => array_map('intval', array_column($app->store()->all(
        "SELECT library_id FROM timeline_items WHERE channel_id = ? AND state = ? AND type = 'song'", [$cid, $state],
    ), 'library_id'));
    [$only] = TestKit::songs($app, 1);
    $app->tick()->run('test');
    $all = [...$songs('committed'), ...$songs('draft')];
    check($songs('draft') !== [] && count($all) > 1 && array_unique($all) === [$only], 'one song, planned over and over');
    $replanned = (int) $app->store()->value("SELECT MIN(est_start) FROM timeline_items WHERE channel_id = ? AND state = 'draft'", [$cid]);

    TestKit::songs($app, 11);
    TestKit::clock($app)->advance(61_000);
    $app->tick()->run('test');
    // Committed or still drafted: from where the drafts began, new songs.
    $after = array_map('intval', array_column($app->store()->all(
        "SELECT library_id FROM timeline_items WHERE channel_id = ? AND state != 'dropped' AND type = 'song' AND COALESCE(start_ms, est_start) >= ?",
        [$cid, $replanned],
    ), 'library_id'));
    check($after !== [] && !in_array($only, $after, true), 'the repeat was planned again, with the new songs');
    assertContiguous(TestKit::committed($app), 'still contiguous');

    // A plan without repeats is left alone when the library changes again.
    $before = $app->store()->all("SELECT id FROM timeline_items WHERE channel_id = ? AND state = 'draft'", [$cid]);
    TestKit::songs($app, 1);
    TestKit::clock($app)->advance(61_000);
    $app->tick()->run('test');
    $still = array_column($app->store()->all("SELECT id FROM timeline_items WHERE channel_id = ? AND state = 'draft'", [$cid]), 'id');
    foreach (array_column($before, 'id') as $id) {
        $row = $app->store()->one('SELECT state FROM timeline_items WHERE id = ?', [$id]);
        check(in_array($id, $still, true) || ($row['state'] ?? '') === 'committed', "draft $id kept (or committed)");
    }
});

test('generator: two songs are enough — repeat protection loosens instead of leaving holes', function () {
    $app = TestKit::app(['HOST_MIN_LISTENERS' => '99']);
    TestKit::songs($app, 2);
    for ($i = 0; $i < 30; $i++) {
        $app->tick()->run('test');
        TestKit::clock($app)->advance(60_000);
    }
    $items = TestKit::committed($app);
    assertContiguous($items, 'contiguous over 30 minutes');
    $songs = array_filter($items, fn($i) => $i['type'] === 'song');
    check(count($songs) >= 8, 'songs keep coming (' . count($songs) . ')');
    $stageAfterStart = array_filter(array_slice($items, 1), fn($i) => $i['type'] === 'stage');
    eq(count($stageAfterStart), 0, 'no stage filler with a non-empty library');
});

test('generator: after an outage the timeline re-anchors with a gap, past minutes stay unwritten', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $app->tick()->run('test');
    TestKit::clock($app)->advance(40 * 60_000); // cron dead for 40 minutes
    $app->tick()->run('test');
    $now = TestKit::T0 + 40 * 60_000;
    $gaps = array_values(array_filter(TestKit::committed($app), fn($i) => $i['type'] === 'gap' && $i['start_ms'] > TestKit::T0));
    eq(count($gaps), 1, 'one new gap');
    eq($gaps[0]['start_ms'], Timing::floorMinute($now), 'gap starts at the current minute');
    check(!is_file($app->publicPath(Timing::slotPath('main', $now - 10 * 60_000))), 'no file for a minute that passed during the outage');
    check(is_file($app->publicPath(Timing::slotPath('main', $now))), 'the current minute is published');
    $after = array_values(array_filter(TestKit::committed($app), fn($i) => $i['start_ms'] >= $gaps[0]['start_ms']));
    assertContiguous($after, 'contiguous after the gap');
});

test('generator: host breaks are voiced in both languages and committed with audio', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    // A cold start cannot voice the breaks inside its first commit horizon
    // (they are dropped, music plays); everything drafted later is ready in
    // time because its jobs run in the minutes between draft and commit.
    for ($i = 0; $i < 35; $i++) {
        $app->tick()->run('test');
        TestKit::clock($app)->advance(60_000);
    }
    $hosts = array_values(array_filter(TestKit::committed($app), fn($i) => $i['type'] === 'host'));
    check(count($hosts) >= 2, 'host items committed (' . count($hosts) . ')');
    $h = $hosts[count($hosts) - 1];
    eq($h['payload']['kind'], 'break', 'a between-songs break');
    check(isset($h['payload']['audio']['en'], $h['payload']['audio']['de']), 'audio in both languages');
    check(is_file($app->publicPath(ltrim($h['payload']['audio']['en'], '/'))), 'the mp3 exists');
    check($h['dur_ms'] >= 3000 && $h['dur_ms'] < 5000, 'duration from the mp3 (stub ≈ 3 s + pad): ' . $h['dur_ms']);
    check(($h['payload']['text']['de'] ?? '') !== '', 'German text');
    $songs = array_values(array_filter(TestKit::committed($app), fn($i) => $i['type'] === 'song'));
    $firstCommittedHost = array_search($hosts[0], TestKit::committed($app), true);
    check($firstCommittedHost !== false, 'host item is part of the timeline');
    check(count($songs) >= 6, 'songs around the breaks');
    assertContiguous(TestKit::committed($app), 'contiguous');
});

test('generator: a plain host break that is not ready in time is dropped, music continues', function () {
    // HOST_MIN_LISTENERS=99: every plain break fails its gate and never becomes ready.
    $app = TestKit::app(['HOST_MIN_LISTENERS' => '99']);
    TestKit::songs($app, 12);
    for ($i = 0; $i < 25; $i++) {
        $app->tick()->run('test');
        TestKit::clock($app)->advance(60_000);
    }
    $items = TestKit::committed($app);
    eq(count(array_filter($items, fn($i) => $i['type'] === 'host')), 0, 'no host item aired');
    check((int) $app->store()->value("SELECT COUNT(*) FROM timeline_items WHERE type = 'host' AND state = 'dropped'") > 0, 'breaks were dropped');
    assertContiguous($items, 'contiguous');
});

test('generator: a plan change discards drafts but keeps the committed timeline', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $app->tick()->run('test');
    $committed = count(TestKit::committed($app));
    $ch = TestKit::main($app);
    $app->catalog()->saveProgram((int) $ch['fallback_program_id'], (int) $ch['id'], ['subtitle_en' => 'Changed'], 'test');
    TestKit::clock($app)->advance(60_000);
    $app->tick()->run('test');
    check(count(TestKit::committed($app)) >= $committed, 'committed items kept');
    check((int) $app->store()->value("SELECT COUNT(*) FROM timeline_items WHERE state = 'dropped'") > 0, 'old drafts dropped');
});

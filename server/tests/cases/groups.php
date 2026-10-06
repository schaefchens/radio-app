<?php
declare(strict_types=1);

use Arche\Library\Groups;
use Arche\Program\Timing;
use Arche\Support\HttpResponse;

/**
 * Library groups: a preacher's, a church's, a ministry's or an artist's items
 * with a few words and links — the stage shows them after one of their items
 * while the host points to more from them — and the creators who asked not
 * to be on our platform: nothing of theirs is accepted, nothing plays.
 */

/** A YouTube channel id (UC + 22 characters) for tests. */
function channelId(string $name): string
{
    return 'UC' . str_pad($name, 22, 'x');
}

/** A Data API answer for one video of $channel ($channelId), as FakeHttp hands it out. */
function ytVideo(string $id, string $title, string $channel, string $channelId, string $duration = 'PT4M'): HttpResponse
{
    return new HttpResponse(200, (string) json_encode(['items' => [[
        'id' => $id,
        'snippet' => ['title' => $title, 'channelTitle' => $channel, 'channelId' => $channelId, 'liveBroadcastContent' => 'none', 'tags' => [], 'description' => ''],
        'contentDetails' => ['duration' => $duration],
        'status' => ['embeddable' => true, 'privacyStatus' => 'public', 'uploadStatus' => 'processed'],
    ]]]));
}

/** A station with the YouTube Data API answered by FakeHttp. @return array{0:Arche\App,1:FakeHttp} */
function groupStation(array $env = []): array
{
    $app = TestKit::app(['YOUTUBE_API_KEY' => 'test-key'] + $env);
    $http = new FakeHttp();
    $app->set('http', $http);
    return [$app, $http];
}

test('groups: after a preaching of a group that wants it, the host presents them and where there is more from them while the stage shows them — the model gets the group, not its id', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    [$preaching] = libraryVideos($app, 'preaching', [20], 'PreGrp');
    $g = $app->groups()->save(null, ['name' => 'Grace Chapel', 'about_en' => 'A church in Accra.', 'about_de' => 'Eine Gemeinde in Accra.', 'notice' => true,
        'links' => [['kind' => 'youtube', 'url' => 'https://www.youtube.com/@gracechapel'], ['kind' => 'website', 'url' => 'https://gracechapel.example']]], 'test');
    $app->library()->update($preaching, ['group_id' => $g['id']], 'test');
    $seen = [];
    $told = '';
    $app->text()->respond('host_break', function (string $system, string $user) use (&$seen, &$told): array {
        $seen[] = $user;
        $told = (string) preg_replace('/\s+/', ' ', $system);
        return ['en' => ['text' => 'Grace Chapel is a church in Accra.'], 'de' => ['text' => 'Grace Chapel ist eine Gemeinde in Accra.']];
    });
    $p = videoProgram($app, 'preaching', 12 * 60 + 5, 60);
    ticks($app, 40);

    $run = array_values(array_filter(TestKit::committed($app), fn($i) => $i['program_id'] === (int) $p['id']));
    $at = (int) array_key_first(array_filter($run, fn($i) => $i['library_id'] === $preaching));
    $break = $run[$at + 1];
    eq([$break['type'], $break['payload']['kind'] ?? ''], ['host', 'break'], 'the host speaks after the preaching');
    eq(hostContext($app, $break)['previous_group'] ?? null, ['name' => 'Grace Chapel', 'about' => ['en' => 'A church in Accra.', 'de' => 'Eine Gemeinde in Accra.'], 'find' => ['youtube', 'website']],
        'its words know whose it was, and where there is more from them — by kind, never an address');
    check(str_contains($told, 'Make them the heart of this moment') && str_contains($told, 'do not mention the app'), 'the host presents them and never sends listeners to the app');
    $prompt = (string) (array_values(array_filter($seen, fn(string $u) => str_contains($u, 'previous_group')))[0] ?? '');
    check($prompt !== '' && !str_contains($prompt, '"group_id"'), 'the model gets the group, never its id');
    $slot = json_decode((string) file_get_contents($app->publicPath(Timing::slotPath('main', $break['start_ms'] + 1000))), true);
    $published = array_values(array_filter($slot['items'], fn($i) => $i['id'] === $break['uid']))[0] ?? [];
    eq($published['notice'] ?? null, ['name' => 'Grace Chapel', 'text' => ['en' => 'A church in Accra.', 'de' => 'Eine Gemeinde in Accra.'],
        'links' => [['kind' => 'youtube', 'url' => 'https://www.youtube.com/@gracechapel'], ['kind' => 'website', 'url' => 'https://gracechapel.example']]],
        'the minute file carries the notice for the stage');
    $others = array_filter($slot['items'], fn($i) => $i['type'] === 'host' && $i['id'] !== $break['uid'] && isset($i['notice']));
    check(!$others, 'no other moment carries it');

    // Switched off, or after an item of no group: no notice.
    $app->groups()->save((int) $g['id'], ['notice' => false], 'test');
    check($app->groups()->notice((int) $g['id']) === null, 'a group that does not want it has none');
    $hb = $app->hostBreaks()->get((int) $break['host_break_id']) ?? [];
    $app->groups()->save((int) $g['id'], ['notice' => true], 'test');
    $song = TestKit::committed($app)[0];
    check(!isset($app->hostBreaks()->payload($hb, $song)['notice']), 'committed after another item than its own, the break shows none');
    check(isset($app->hostBreaks()->payload($hb, $run[$at])['notice']), 'after its own, it does');
});

test('groups: a script the model did not answer is asked again while the moment is far off; without the model the template presents the group — never "in the app"', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    [$preaching] = libraryVideos($app, 'preaching', [20], 'PreTry');
    $g = $app->groups()->save(null, ['name' => 'Grace Chapel', 'about_en' => 'A church in Accra', 'about_de' => 'Eine Gemeinde in Accra', 'notice' => true,
        'links' => [['kind' => 'youtube', 'url' => 'https://www.youtube.com/@gracechapel']]], 'test');
    $app->library()->update($preaching, ['group_id' => $g['id']], 'test');
    $answers = 0;
    $failures = 1;
    $app->text()->respond('host_break', function (string $system, string $user) use (&$answers, &$failures): array|Arche\Ai\TextResult {
        if (!str_contains($user, 'previous_group')) return ['en' => ['text' => 'Stay with us.'], 'de' => ['text' => 'Bleibt dran.']];
        $answers++;
        // A timeout, as the HTTP transport reports it: no data, 'error'.
        if ($failures-- > 0) return new Arche\Ai\TextResult(null, 'error', 'stub');
        return ['en' => ['text' => 'Grace Chapel is a church in Accra.'], 'de' => ['text' => 'Grace Chapel ist eine Gemeinde in Accra.']];
    });
    $p = videoProgram($app, 'preaching', 12 * 60 + 5, 60);
    ticks($app, 40);
    $run = array_values(array_filter(TestKit::committed($app), fn($i) => $i['program_id'] === (int) $p['id']));
    $at = (int) array_key_first(array_filter($run, fn($i) => $i['library_id'] === $preaching));
    $break = $run[$at + 1];
    eq([$break['type'], $answers], ['host', 2], 'the model was asked a second time');
    eq($break['payload']['text']['de'] ?? '', 'Grace Chapel ist eine Gemeinde in Accra.', 'and its words air, not the template');
    eq(hostContext($app, $break)['script_retries'] ?? null, 1, 'once');

    // Without the model at all: the template presents them — their own few words and where to find more.
    $c = ['program' => ['title' => ['en' => 'Sermons', 'de' => 'Predigten']], 'previous' => ['title' => 'Hope', 'kind' => 'preaching'],
        'previous_group' => ['name' => 'Grace Chapel', 'about' => ['en' => 'A church in Accra', 'de' => 'Eine Gemeinde in Accra'], 'find' => ['youtube', 'website']]];
    $t = Arche\Host\Templates::texts('break', $c);
    foreach (['en' => ['A church in Accra.', 'their YouTube channel and their website'], 'de' => ['Eine Gemeinde in Accra.', 'auf ihrem YouTube-Kanal und auf ihrer Website']] as $l => [$about, $where]) {
        check(str_starts_with($t[$l], 'That was Grace Chapel.') || str_starts_with($t[$l], 'You just heard Grace Chapel.') || str_starts_with($t[$l], 'Das war Grace Chapel.') || str_starts_with($t[$l], 'Gerade habt ihr Grace Chapel gehört.'), "$l opens with them: {$t[$l]}");
        check(str_contains($t[$l], $about) && str_contains($t[$l], $where), "$l: who they are and where there is more: {$t[$l]}");
        check(!preg_match('/\bApp\b|\bapp\b|Link/u', $t[$l]), "$l: never the app or links: {$t[$l]}");
    }
    $none = Arche\Host\Templates::texts('outro', ['previous_group' => ['name' => 'Grace Chapel', 'about' => ['en' => '', 'de' => ''], 'find' => []]] + $c);
    check(str_contains($none['de'], 'Von ihnen gibt es noch mehr zu entdecken.') && str_contains($none['de'], 'Danke'), 'no words, no links: still them first, then the outro');
    $praying = Arche\Host\Templates::texts('break', ['previous_group' => ['name' => 'Grace Chapel', 'about' => ['en' => 'We pray for you. Amen.', 'de' => 'Wir beten für dich. Amen.'], 'find' => []]] + $c);
    check(!Arche\Host\HostWriter::prays($praying['en']) && !Arche\Host\HostWriter::prays($praying['de']), 'about words that pray are left out: the host never prays');

    // Too close to its minutes: no time to ask again — the template airs at once.
    $hb = $app->hostBreaks()->get((int) $break['host_break_id']) ?? [];
    $app->store()->query("UPDATE host_breaks SET state = 'pending', context = json_remove(context, '$.script_retries') WHERE id = ?", [$hb['id']]);
    $app->store()->query('UPDATE timeline_items SET est_start = ? WHERE host_break_id = ?', [$app->clock->nowMs() + Timing::COMMIT + 60_000, $hb['id']]);
    $failures = 5;
    $next = $app->hostBreaks()->runPhase(['id' => 0, 'ref_id' => $hb['id'], 'phase' => 'script', 'attempts' => 0]);
    check($next !== 'wait:script', 'not asked again');
    eq((string) ($app->hostBreaks()->get($hb['id'])['source'] ?? ''), 'template:error', 'its template instead');
});

test('groups: a channel joins its items to a group — added, suggested, or already in the library — but never moves what a moderator put elsewhere; one channel belongs to one group', function () {
    [$app, $http] = groupStation();
    $h = modHeaders($app);
    $mine = channelId('mine');
    [$early, $elsewhere] = TestKit::songs($app, 2);
    $app->store()->update('library_items', ['yt_channel' => $mine], 'id IN (?, ?)', [$early, $elsewhere]);
    $other = $app->groups()->save(null, ['name' => 'Other'], 'test');
    $app->library()->update($elsewhere, ['group_id' => $other['id']], 'test');
    $g = $app->groups()->save(null, ['name' => 'Hope Church', 'channels' => [['id' => $mine, 'title' => 'Hope Church']]], 'test');
    eq([$app->library()->get($early)['group_id'] ?? null, $app->library()->get($elsewhere)['group_id'] ?? null], [$g['id'], $other['id']],
        'saved, the group takes the items of its channel without one');

    $http->answers[] = ytVideo('HopeMod0001', 'Hope Church - Sunday Message', 'Hope Church', $mine, 'PT35M');
    [$st, $d] = call($app, 'POST', '/api/mod/library', ['url' => 'https://youtu.be/HopeMod0001', 'kind' => 'preaching'], $h);
    eq([$st, $d['item']['group_id'] ?? null], [200, $g['id']], 'a video added from the channel joins');

    eq(refuses(fn() => $app->groups()->save(null, ['name' => 'Copy', 'channels' => [$mine]], 'test'), 'channel_in_group'), true, 'a channel in two groups is refused');
    $http->answers[] = ytVideo('HopeRes0001', 'Hope Church - Psalm 23', 'Hope Church', $mine);
    eq($app->groups()->resolveChannel('https://youtu.be/HopeRes0001'), ['id' => $mine, 'title' => 'Hope Church'], 'a channel is found from any of its videos');
    eq($app->groups()->resolveChannel("https://www.youtube.com/channel/$mine"), ['id' => $mine, 'title' => ''], 'or from its /channel/ address');
    check(refuses(fn() => $app->groups()->resolveChannel('https://www.youtube.com/@hopechurch'), 'channel_handle'), 'a handle cannot be looked up');
});

test('groups: what a blocked creator made is refused — from their channel, a fan upload naming them as the artist, a library video in their group — and cannot be overruled', function () {
    [$app, $http] = groupStation();
    TestKit::songs($app, 12);
    $banned = channelId('banned');
    [$theirs] = TestKit::songs($app, 1, 240_000, ['yt_id' => 'BanLib00001', 'title' => 'Old Song', 'artist' => 'Somebody']);
    $g = $app->groups()->save(null, ['name' => 'Banned Band', 'channels' => [$banned], 'blocked' => true, 'note' => 'Asked us by email.'], 'test');
    $app->library()->update($theirs, ['group_id' => $g['id']], 'test');
    $app->tick()->run('test');

    $refused = function (string $ytId, ?HttpResponse $answer) use ($app, $http): array {
        if ($answer !== null) $http->answers[] = $answer;
        $sub = $app->submissions()->submitSong(listener($app), TestKit::main($app), ['url' => "https://youtu.be/$ytId", 'name' => 'Ann']);
        runJobs($app);
        $row = $app->submissions()->byPublicId($sub['id']) ?? [];
        return [$row['status'], $row['reason'], json_decode((string) $row['verdict'], true)['group_blocked'] ?? null, $app->submissions()->overruleBlocker($row)];
    };
    $expected = ['rejected', 'not_accepted', $g['id'], 'group_blocked'];
    eq($refused('BanOwn00001', ytVideo('BanOwn00001', 'Banned Band - Glory', 'Banned Band', $banned)), $expected, 'from their channel');
    eq($refused('BanFan00001', ytVideo('BanFan00001', 'Banned Band - Glory (Lyrics)', 'Lyric Fan 77', channelId('fan'))), $expected, 'a fan upload naming them as the artist');
    eq($refused('BanLib00001', ytVideo('BanLib00001', 'Old Song', 'Someone Else', channelId('else'))), $expected, 'a library video a moderator put in their group');
    $http->answers[] = ytVideo('BanFine0001', 'Banned Bandits Choir - Hope', 'Bandits', channelId('bandits'));
    $sub = $app->submissions()->submitSong(listener($app), TestKit::main($app), ['url' => 'https://youtu.be/BanFine0001']);
    runJobs($app);
    eq($app->submissions()->byPublicId($sub['id'])['status'] ?? '', 'approved', 'a name that only resembles theirs is not');

    $http->answers[] = ytVideo('BanMod00001', 'Banned Band - Glory', 'Banned Band', $banned);
    eq(call($app, 'POST', '/api/mod/library', ['url' => 'https://youtu.be/BanMod00001'], modHeaders($app))[1]['error'] ?? null, 'group_blocked', 'a moderator cannot add it either');
    $ids = array_column($app->library()->candidates((int) TestKit::main($app)['id'], (int) TestKit::main($app)['fallback_program_id'], 30 * 60_000), 'id');
    check(!in_array($theirs, $ids, true) && !in_array($theirs, array_column($app->library()->evergreen((int) TestKit::main($app)['id']), 'id'), true),
        'their library song is neither selected nor in the fallback loop');
});

test('groups: blocking takes what is planned or on air of theirs off at once — committed airings through live.json, drafts with their announcement — and unblocking brings them back', function () {
    $app = TestKit::app();
    [$theirs] = TestKit::songs($app, 1, 300_000, ['title' => 'Their Song', 'artist' => 'Their Band']);
    TestKit::songs($app, 3);
    $ch = TestKit::main($app);
    $req = $app->submissions()->submitSong(listener($app), $ch, ['url' => 'https://youtu.be/' . $app->library()->get($theirs)['yt_id'], 'name' => 'Eve']);
    runJobs($app);
    ticks($app, 3); // the request is planned, not yet over
    $planned = (int) $app->store()->value("SELECT COUNT(*) FROM timeline_items WHERE library_id = ? AND state != 'dropped' AND COALESCE(start_ms, est_start) + dur_ms > ?",
        [$theirs, $app->clock->nowMs()]);
    check($planned > 0, 'their song is planned or on air');

    $g = $app->groups()->save(null, ['name' => 'Their Band', 'blocked' => true], 'test');
    eq($app->library()->get($theirs)['group_id'] ?? null, $g['id'], 'their song joins by the artist the group names');
    eq((int) $app->store()->value("SELECT COUNT(*) FROM timeline_items WHERE library_id = ? AND state = 'draft'", [$theirs]), 0, 'no draft of it is left');
    $committed = $app->store()->all("SELECT uid FROM timeline_items WHERE library_id = ? AND state = 'committed' AND start_ms + dur_ms > ?", [$theirs, $app->clock->nowMs()]);
    $live = json_decode((string) file_get_contents($app->publicPath('program/main/live.json')), true);
    foreach ($committed as $c) check(in_array($c['uid'], $live['blocked'] ?? [], true), 'committed, it is blocked through live.json');
    check(!in_array($theirs, array_column($app->library()->candidates((int) $ch['id'], (int) $ch['fallback_program_id'], 30 * 60_000), 'id'), true), 'never selected');

    $app->groups()->save((int) $g['id'], ['blocked' => false], 'test');
    check(in_array($theirs, array_column($app->library()->candidates((int) $ch['id'], (int) $ch['fallback_program_id'], 30 * 60_000), 'id'), true), 'unblocked, it plays again');
    eq((int) $app->library()->get($theirs)['active'], 1, 'it was never switched off');
});

test('groups: the channels of the library\'s older videos are filled in by a job, fifty per call, and their items join their groups', function () {
    [$app, $http] = groupStation();
    $mine = channelId('mine');
    [$a, $b, $gone] = TestKit::songs($app, 3);
    $g = $app->groups()->save(null, ['name' => 'Hope Church', 'channels' => [$mine]], 'test');
    $ytIds = array_map(fn(int $id) => (string) $app->library()->get($id)['yt_id'], [$a, $b, $gone]);
    $http->answers[] = new HttpResponse(200, (string) json_encode(['items' => [
        ['id' => $ytIds[0], 'snippet' => ['channelId' => $mine]],
        ['id' => $ytIds[1], 'snippet' => ['channelId' => channelId('other')]],
    ]]));
    $app->library()->queueChannels();
    runJobs($app);
    eq(count($http->sent), 1, 'one call for all of them');
    check(str_contains((string) $http->sent[0]['url'], implode('%2C', $ytIds)), 'by their ids');
    eq(array_map(fn(int $id) => $app->library()->get($id)['yt_channel'] ?? null, [$a, $b, $gone]), [$mine, channelId('other'), ''],
        'each channel known; a video YouTube no longer has is not asked again');
    eq($app->library()->get($a)['group_id'] ?? null, $g['id'], 'and the item of the group\'s channel joins it');
    $app->library()->queueChannels();
    runJobs($app);
    eq(count($http->sent), 1, 'nothing left to ask');
});

test('groups: links are https only and at most four, the words short, channels real ids; names compare by their letters', function () {
    $app = TestKit::app();
    $groups = $app->groups();
    foreach ([
        [['links' => [['kind' => 'website', 'url' => 'http://example.org']]], 'invalid_link'],
        [['links' => [['kind' => 'website', 'url' => 'javascript:alert(1)']]], 'invalid_link'],
        [['links' => array_fill(0, 5, ['kind' => 'website', 'url' => 'https://example.org'])], 'too_many_links'],
        [['about_en' => str_repeat('a', 201)], 'about_too_long'],
        [['channels' => ['UCshort']], 'invalid_channel'],
        [['name' => ''], 'invalid_name'],
    ] as [$data, $error]) {
        check(refuses(fn() => $groups->save(null, $data + ['name' => 'X'], 'test'), $error), $error);
    }
    $g = $groups->save(null, ['name' => 'Y', 'links' => [['kind' => 'nonsense', 'url' => 'https://example.org/y']]], 'test');
    eq($g['links'], [['kind' => 'other', 'url' => 'https://example.org/y']], 'an unknown kind of link is "other"');
    eq([Groups::norm('Olaf Latzel'), Groups::norm('olaf-latzel'), Groups::norm('Jürgen  Müller!')], ['olaflatzel', 'olaflatzel', 'jürgenmüller'], 'names by their letters');
});

test('groups: a database from before groups gets them, its library kept', function () {
    $app = TestKit::app();
    $store = $app->store();
    [$song] = TestKit::songs($app, 1);
    $sql = (string) $store->value("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'library_items'");
    $store->db->exec('CREATE TABLE library_old AS SELECT * FROM library_items');
    $store->db->exec('DROP TABLE library_items');
    $store->db->exec((string) preg_replace('/,\s*group_id[^,]*,\s*yt_channel TEXT/', '', $sql));
    $store->db->exec('INSERT INTO library_items SELECT id, kind, yt_id, audio, title, artist, thumb, duration_ms, languages, themes, moods, program_ids, channel_ids, source, submission_id, meta, active, plays, last_played, trend_score, created, updated FROM library_old');
    $store->db->exec('DROP TABLE library_old');
    $store->db->exec('DROP TABLE library_groups');
    $store->set('schema', 11);
    Arche\Schema::migrate($store, $app->clock->nowMs());
    eq($app->library()->get($song)['title'] ?? '', 'Song 1', 'its library is kept');
    $g = $app->groups()->save(null, ['name' => 'After'], 'test');
    $app->library()->update($song, ['group_id' => $g['id']], 'test');
    eq($app->library()->get($song)['group_id'] ?? null, $g['id'], 'and it takes groups');
});

<?php
declare(strict_types=1);

use Arche\Ai\Usage;
use Arche\Host\Templates;
use Arche\Library\Library;
use Arche\Moderation\Moderator;
use Arche\Moderation\Policy;
use Arche\Plan\Catalog;
use Arche\Program\Drafter;
use Arche\Program\SubmissionWindow;
use Arche\Program\Timing;
use Arche\Submission\Submissions;

/**
 * The video programs beside preaching: testimonies, mission videos and films
 * — each its own kind from the library with songs between them — and the one
 * sheet through which listeners suggest any kind a program takes. A film runs
 * two hours: it must end inside its program, also across midnight.
 */

/**
 * A video program on T0's day (Berlin) from $startMin for $minutes, the
 * regular program around it.
 *
 * @param list<string>|null $allowed default: the format's own suggestion type
 * @param array<string,mixed> $settings over the defaults
 * @return array<string,mixed> the program
 */
function videoProgram(Arche\App $app, string $format, int $startMin, int $minutes, ?array $allowed = null, array $settings = []): array
{
    $cat = $app->catalog();
    $cid = (int) TestKit::main($app)['id'];
    $p = $cat->saveProgram(null, $cid, ['slug' => "v-$format", 'title_en' => ucfirst($format), 'title_de' => ucfirst($format),
        'allowed' => $allowed ?? [Catalog::VIDEO_FORMATS[$format]], 'settings' => array_replace_recursive(['format' => $format], $settings)], 'test');
    $plan = $cat->saveDayPlan(null, $cid, ucfirst($format), [['start_min' => $startMin, 'end_min' => $startMin + $minutes, 'program_id' => $p['id']]], 'test');
    $cat->addSpecialDay($cid, ['name' => ucfirst($format), 'kind' => 'date', 'month' => 9, 'day' => 23, 'day_plan_id' => $plan], 'test');
    return $p;
}

/**
 * Videos of $kind straight into the library, each $minutes long; ids
 * $prefix + a number, 11 characters like YouTube's.
 *
 * @param list<int> $minutes
 * @return list<int>
 */
function libraryVideos(Arche\App $app, string $kind, array $minutes, string $prefix): array
{
    $ids = [];
    foreach ($minutes as $i => $m) {
        $ids[] = $app->store()->insert('library_items', [
            'kind' => $kind, 'yt_id' => substr($prefix . sprintf('%05d', $i + 1), 0, 11), 'title' => ucfirst($kind) . ' ' . ($i + 1),
            'artist' => 'From ' . ($i + 1), 'duration_ms' => $m * 60_000, 'themes' => '["hope"]', 'created' => 1, 'updated' => 1,
        ]);
    }
    return $ids;
}

/**
 * The program's committed items in air order: the host by its kind (its own
 * moment before a video as "introduce"), a video by its kind ("suggested"
 * when a listener's), else the type.
 *
 * @return list<string>
 */
function videoLabels(Arche\App $app, int $programId): array
{
    $out = [];
    foreach (TestKit::committed($app) as $it) {
        if ($it['program_id'] !== $programId) continue;
        $out[] = match (true) {
            $it['type'] === 'host' => Catalog::isVideoFormat($it['payload']['kind'] ?? '') ? 'introduce' : (string) $it['payload']['kind'],
            Drafter::isVideo($it) => $it['submission_id'] !== null ? 'suggested' : (string) $it['payload']['kind'],
            default => (string) $it['type'],
        };
    }
    return $out;
}

/** A suggestion sent through the one endpoint of the video sheet. @return array{0:int,1:array<string,mixed>} */
function suggest(Arche\App $app, string $type, string $ytId, array $more = [], ?array $headers = null): array
{
    return call($app, 'POST', '/api/submissions/video', ['channel' => 'main', 'type' => $type, 'url' => "https://youtu.be/$ytId"] + $more, $headers ?? authHeaders());
}

test('videos: a testimony program plays its own testimonies with songs between — never a preaching, a mission video or a film — introduced and published as testimonies', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    [$first, $second, $long] = libraryVideos($app, 'testimony', [12, 15, 50], 'WitLib');
    $others = [...libraryVideos($app, 'preaching', [20], 'PreLib'), ...libraryVideos($app, 'mission', [20], 'MisLib'), ...libraryVideos($app, 'film', [20], 'FilmLb')];
    $p = videoProgram($app, 'testimony', 12 * 60 + 10, 90); // 12:10–13:40 in Berlin
    ticks($app, 110);

    $labels = videoLabels($app, (int) $p['id']);
    eq(array_slice($labels, 0, 10), ['intro', 'testimony', 'break', 'song', 'song', 'introduce', 'testimony', 'break', 'song', 'song'],
        'intro, a testimony, the host, two songs, then the host introduces the next one');
    eq([count(airingsOf($app, $first)), count(airingsOf($app, $second)), count(airingsOf($app, $long))], [1, 1, 0], 'each once; the 50-minute one no longer fits');
    eq(array_sum(array_map(fn(int $id) => count(airingsOf($app, $id)), $others)), 0, 'never another kind of video');
    assertContiguous(TestKit::committed($app), 'the timeline is contiguous');

    $run = array_values(array_filter(TestKit::committed($app), fn($i) => $i['program_id'] === (int) $p['id']));
    [$intro, $video] = $run;
    $ctx = hostContext($app, $intro);
    eq([$ctx['next']['kind'] ?? '', $ctx['next']['title'] ?? '', $ctx['next']['by'] ?? '', $ctx['next_uid'] ?? ''],
        ['testimony', 'Testimony 1', 'From 1', $video['uid']], 'the intro knows the testimony and who it is from, and is pinned to it');
    $introduce = $run[array_search('introduce', $labels, true)];
    eq([$introduce['payload']['kind'], hostContext($app, $introduce)['next']['title'] ?? ''], ['testimony', 'Testimony 2'], 'the second has the host\'s own moment, named after its kind');

    $slot = json_decode((string) file_get_contents($app->publicPath(Timing::slotPath('main', $video['start_ms'] + 60_000))), true);
    $published = array_values(array_filter($slot['items'], fn($i) => $i['id'] === $video['uid']))[0] ?? [];
    eq([$published['type'] ?? '', $published['kind'] ?? ''], ['song', 'testimony'], 'published as a song item of the kind testimony');
    eq($slot['programs']['v-testimony']['format'] ?? '', 'testimony', 'in a testimony program');
});

test('videos: a two-hour film fits a block of two and a quarter hours — its intro takes it, it ends inside the program — and one of two and a half never starts', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    [$film, $epic] = libraryVideos($app, 'film', [120, 150], 'FilmLb');
    $p = videoProgram($app, 'film', 12 * 60 + 5, 135); // 12:05–14:20
    ticks($app, 150);

    $labels = videoLabels($app, (int) $p['id']);
    eq(array_slice($labels, 0, 3), ['intro', 'film', 'break'], 'the intro introduces the film, the host speaks after it');
    eq([count(airingsOf($app, $film)), count(airingsOf($app, $epic))], [1, 0], 'the film airs once; the longer one never fits');
    $end = strtotime('2026-09-23T12:20:00Z') * 1000; // 14:20 in Berlin
    foreach (TestKit::committed($app) as $it) {
        if (Drafter::isVideo($it)) check($it['start_ms'] + $it['dur_ms'] <= $end + Timing::SOFT_OVERRUN, 'the film ends inside its program');
    }
    eq(hostContext($app, array_values(array_filter(TestKit::committed($app), fn($i) => $i['program_id'] === (int) $p['id']))[0])['next']['kind'] ?? '',
        'film', 'the intro knows it is a film');
    assertContiguous(TestKit::committed($app), 'the timeline is contiguous');
});

test('videos: across midnight a film ends with its program — the room is the program\'s whole run, not "the same program goes on"', function () {
    // 22:20 Berlin; the film program runs 22:00–00:30, a block on each date.
    $t0 = TestKit::T0 + (10 * 60 + 20) * 60_000;
    $app = TestKit::app([], $t0);
    TestKit::songs($app, 30);
    // The longer one first: the selection would take it, and it ran until 00:51.
    [$epic, $film] = libraryVideos($app, 'film', [150, 110], 'FilmMn');
    $p = videoProgram($app, 'film', 22 * 60, 120);
    $cat = $app->catalog();
    $ch = TestKit::main($app);
    $after = $cat->saveDayPlan(null, (int) $ch['id'], 'Film after midnight', [['start_min' => 0, 'end_min' => 30, 'program_id' => $p['id']]], 'test');
    $cat->addSpecialDay((int) $ch['id'], ['name' => 'Next day', 'kind' => 'date', 'month' => 9, 'day' => 24, 'day_plan_id' => $after], 'test');
    ticks($app, 140);

    $end = $t0 + 130 * 60_000; // 00:30
    eq([count(airingsOf($app, $epic)), count(airingsOf($app, $film))], [0, 1], 'the 150-minute film does not start at 22:20; the 110-minute one does');
    foreach (TestKit::committed($app) as $it) {
        if ($it['program_id'] === (int) $p['id']) check($it['start_ms'] + $it['dur_ms'] <= $end + Timing::SOFT_OVERRUN, 'nothing of the program runs past 00:30');
    }
    assertContiguous(TestKit::committed($app), 'the timeline is contiguous');
});

test('videos: a mission program that also takes testimonies — one sheet, one endpoint: the testimony is checked as one, joins the library as one and airs as the next video, announced by name', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    [$own] = libraryVideos($app, 'mission', [20], 'MisLib');
    $p = videoProgram($app, 'mission', 12 * 60 + 5, 120, ['mission', 'testimony_video']);
    eq($p['allowed'], ['testimony_video', 'mission'], 'it takes both kinds');
    ticks($app, 8); // the program's own mission video is on air

    $h = authHeaders();
    eq(suggest($app, 'preaching', 'PreSug00001', [], $h)[1]['error'] ?? null, 'not_accepted_now', 'a preaching it does not take');
    eq([suggest($app, 'song', 'WitSug00009', [], $h)[0], suggest($app, 'testimony', 'WitSug00009', [], $h)[1]['error'] ?? null], [422, 'bad_type'],
        'a song request or a recorded testimony is not a suggestion');
    [$st, $d] = suggest($app, 'testimony_video', 'WitSug00001', ['name' => 'Grace', 'place' => 'Lagos', 'message' => 'It moved me.'], $h);
    eq([$st, $d['submission']['type'] ?? ''], [200, 'testimony_video'], 'a testimony suggested');
    runJobs($app);
    $row = $app->submissions()->byPublicId((string) $d['submission']['id']) ?? [];
    eq($row['status'], 'approved', 'checked and approved');
    $lib = $app->library()->get((int) $row['library_id']) ?? [];
    eq([$lib['kind'], $lib['source'], $lib['title'], $lib['artist'], $lib['duration_ms']], ['testimony', 'submission', 'Stub Witness - My Story of Faith', 'Stub Stories', 600_000],
        'in the library as a testimony, by its whole title, from its channel');
    ticks($app, 60);

    eq(array_slice(videoLabels($app, (int) $p['id']), 0, 8), ['intro', 'mission', 'break', 'song', 'song', 'announce', 'suggested', 'break'],
        'after the program\'s own video and two songs, the suggestion takes the video slot');
    $items = TestKit::committed($app);
    $at = array_key_first(array_filter($items, fn($i) => $i['submission_id'] === (int) $row['id']));
    [$announce, $video, $after] = [$items[$at - 1], $items[$at], $items[$at + 1]];
    $ctx = hostContext($app, $announce);
    eq([$ctx['request']['type'] ?? '', $ctx['request']['name'] ?? '', $ctx['request']['message'] ?? '', $ctx['next']['kind'] ?? ''],
        ['testimony', 'Grace', 'It moved me.', 'testimony'], 'announced as Grace\'s testimony, with her word on why');
    eq([$video['payload']['kind'], $video['payload']['request']['name'] ?? ''], ['testimony', 'Grace'], 'on air as a testimony she suggested');
    $before = hostContext($app, $after)['previous_request'] ?? [];
    eq([$before['kind'] ?? '', $before['testimony']['title'] ?? ''], ['testimony suggestion', $lib['title']], 'the host thanks her after it');
    eq([count(airingsOf($app, (int) $lib['id'])), count(airingsOf($app, $own))], [1, 1], 'each once');
    eq($app->submissions()->publicView($app->submissions()->get((int) $row['id']) ?? [])['status'], 'aired', 'marked aired');
});

test('videos: a suggested video already in the library as a song airs as what it was suggested as, and songs come before the next video', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    [$song] = TestKit::songs($app, 1, 300_000, ['yt_id' => 'WitSong0001', 'title' => 'Carried']);
    libraryVideos($app, 'mission', [20, 20], 'MisLib');
    $p = videoProgram($app, 'mission', 12 * 60 + 5, 120, ['mission', 'testimony_video']);
    ticks($app, 8); // the program's own mission video is on air
    [, $d] = suggest($app, 'testimony_video', 'WitSong0001', ['name' => 'Ola']);
    runJobs($app);
    $row = $app->submissions()->byPublicId((string) $d['submission']['id']) ?? [];
    eq([(int) $row['library_id'], $app->library()->get($song)['kind'] ?? ''], [$song, 'song'], 'the library keeps it as a song');
    ticks($app, 60);

    $labels = videoLabels($app, (int) $p['id']);
    $at = (int) array_search('suggested', $labels, true);
    eq(array_slice($labels, $at - 1, 6), ['announce', 'suggested', 'break', 'song', 'song', 'introduce'],
        'it counts as the program\'s video: two songs come before the next one');
    $video = array_values(array_filter(TestKit::committed($app), fn($i) => $i['submission_id'] === (int) $row['id']))[0];
    eq($video['payload']['kind'], 'testimony', 'named a testimony on the stage');
});

test('videos: a format change — to music the suggestion types go and a waiting one goes to the library, opening intake again; to testimonies it keeps waiting', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $ch = TestKit::main($app);
    $cat = $app->catalog();
    $p = videoProgram($app, 'mission', 12 * 60, 120, ['song', 'mission', 'testimony_video'], ['max_queue_min' => 5]);
    [, $d] = suggest($app, 'testimony_video', 'WitSug00002');
    runJobs($app);
    $id = (int) ($app->submissions()->byPublicId((string) $d['submission']['id'])['id'] ?? 0);
    $states = fn(): array => (array) SubmissionWindow::states($app, $ch, $cat->program((int) $p['id']) ?? [], $app->resolver()->blockAt($ch, TestKit::T0), TestKit::T0);
    eq($states()['song'], 'closed', 'a waiting ten-minute testimony fills the five-minute queue');

    eq($cat->saveProgram((int) $p['id'], (int) $ch['id'], ['settings' => ['format' => 'testimony']], 'test')['allowed'], ['song', 'testimony_video', 'mission'],
        'to testimonies: every video type stays');
    eq([$app->submissions()->expireUnreachable(), $app->submissions()->get($id)['status'] ?? ''], [0, 'approved'], 'and the suggestion keeps waiting');

    eq($cat->saveProgram((int) $p['id'], (int) $ch['id'], ['settings' => ['format' => 'music']], 'test')['allowed'], ['song'], 'to music: the video types go');
    eq([$app->submissions()->expireUnreachable(), $app->submissions()->get($id)['status'] ?? ''], [1, 'library'], 'and the waiting suggestion goes to the library');
    eq($states()['song'], 'open', 'song requests open again');
});

test('videos: two listeners suggest the same video — it airs once; the second suggestion goes to the library', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $p = videoProgram($app, 'mission', 12 * 60, 120, null, ['preaching' => ['songs_between' => 1]]);
    $subs = [];
    foreach (['Ana', 'Ben'] as $name) {
        [, $d] = suggest($app, 'mission', 'MisSug00001', ['name' => $name]);
        $subs[] = (string) $d['submission']['id'];
    }
    runJobs($app);
    ticks($app, 70);
    $lib = (int) ($app->submissions()->byPublicId($subs[0])['library_id'] ?? 0);
    eq(count(airingsOf($app, $lib)), 1, 'the video airs once');
    eq(array_map(fn(string $s) => $app->submissions()->publicView($app->submissions()->byPublicId($s) ?? [])['status'], $subs), ['aired', 'library'],
        'the first suggestion aired; the second may play in a later program');
    check(in_array('mission', videoLabels($app, (int) $p['id']), true) === false, 'the program had no video of its own (none in the library): songs filled');
});

test('videos: each kind of link has its own length limits — from the env, never zero when it sets none — and all of them share one limit a listener can send', function () {
    $app = TestKit::app();
    $fine = ['ok' => true, 'error' => '', 'embeddable' => true, 'public' => true, 'live' => false, 'age_restricted' => false,
        'duration_ms' => 600_000, 'blocked' => [], 'allowed' => null];
    $at = fn(int $minutes, string $type, ?Arche\App $a = null): array => Moderator::videoProblems(['duration_ms' => $minutes * 60_000] + $fine, ($a ?? $app)->config, $type);
    eq([$at(10, 'testimony_video'), $at(1, 'testimony_video'), $at(61, 'testimony_video')], [[], ['too_short'], ['too_long']], 'a testimony: 2 to 60 minutes');
    eq([$at(20, 'mission'), $at(2, 'mission'), $at(95, 'mission')], [[], ['too_short'], ['too_long']], 'a mission video: 3 to 90 minutes');
    eq([$at(120, 'film'), $at(4, 'film'), $at(210, 'film')], [[], ['too_short'], ['too_long']], 'a film: two hours are fine, three and a half too long');
    eq($at(20, 'mission', TestKit::app(['MISSION_MAX_SECONDS' => '600'])), ['too_long'], 'MISSION_MAX_SECONDS from the env');

    // Without YouTube a link could only be refused: the minute files do not offer it.
    $live = TestKit::app(['AI_MODE' => 'live', 'OPENAI_KEY' => 'sk-test']);
    eq(array_map(fn(string $t) => SubmissionWindow::featureOn($live, $t), ['song', 'preaching', 'testimony_video', 'mission', 'film', 'prayer']),
        [false, false, false, false, false, true], 'no video type without the YouTube Data API');

    // One limit for the whole sheet — the old preaching route included.
    videoProgram($app, 'mission', 12 * 60, 120, ['preaching', 'testimony_video', 'mission', 'film']);
    $h = authHeaders();
    eq([suggest($app, 'mission', 'MisLim00001', [], $h)[0], suggest($app, 'film', 'MisLim00002', [], $h)[0]], [200, 200], 'two an hour');
    eq(call($app, 'POST', '/api/submissions/preaching', ['channel' => 'main', 'url' => 'https://youtu.be/MisLim00003'], $h)[0], 429, 'and no third, by any route');

    // The day's cap on checks counts every kind of video check.
    $capped = TestKit::app(['MODERATION_MAX_PER_DAY' => '1']);
    videoProgram($capped, 'mission', 12 * 60, 120);
    $capped->usage()->record('text:moderate_mission', 0, 0, 0);
    [, $d] = suggest($capped, 'mission', 'MisCap00001');
    runJobs($capped);
    $row = $capped->submissions()->byPublicId((string) $d['submission']['id']) ?? [];
    eq([$row['status'], json_decode((string) $row['verdict'], true)['error'] ?? ''], ['rejected', 'daily_cap'], 'a mission check counts towards MODERATION_MAX_PER_DAY');
});

test('videos: moderators add testimonies, mission videos and films by link — a film up to four hours — and find them by kind', function () {
    $app = TestKit::app(['YOUTUBE_API_KEY' => 'test-key']);
    $http = new FakeHttp();
    $app->set('http', $http);
    $video = fn(string $id, string $duration, string $title) => new Arche\Support\HttpResponse(200, (string) json_encode(['items' => [[
        'id' => $id,
        'snippet' => ['title' => $title, 'channelTitle' => 'Studio', 'liveBroadcastContent' => 'none', 'tags' => [], 'description' => ''],
        'contentDetails' => ['duration' => $duration],
        'status' => ['embeddable' => true, 'privacyStatus' => 'public', 'uploadStatus' => 'processed'],
    ]]]));
    $h = modHeaders($app);
    $add = function (string $id, string $duration, ?string $kind) use ($app, $http, $video, $h): array {
        $http->answers[] = $video($id, $duration, "Studio - $id");
        return call($app, 'POST', '/api/mod/library', ['url' => "https://youtu.be/$id"] + ($kind !== null ? ['kind' => $kind] : []), $h);
    };
    eq([$add('WitMod00001', 'PT14M', 'testimony')[1]['item']['kind'] ?? null, $add('MisMod00001', 'PT35M', 'mission')[1]['item']['kind'] ?? null],
        ['testimony', 'mission'], 'a testimony and a mission video');
    eq($add('FilmMod0001', 'PT3H30M', 'film')[1]['item']['kind'] ?? null, 'film', 'a film of three and a half hours');
    eq($add('FilmMod0002', 'PT3H30M', 'preaching')[1]['error'] ?? null, 'video_duration', 'too long for a preaching');
    eq($add('FilmMod0003', 'PT4H30M', 'film')[1]['error'] ?? null, 'video_duration', 'and four and a half hours too long for a film');
    eq($add('FilmMod0004', 'PT40M', 'concert'), [422, ['error' => 'bad_kind']], 'an unknown kind is refused, not added as a song');
    eq(array_column(modGet($app, '/api/mod/library', ['kind' => 'film'], $h)[1]['items'], 'yt_id'), ['FilmMod0001'], 'listed by its kind');
    $count = modGet($app, '/api/mod/overview', [], $h)[1]['library'] ?? [];
    eq([$count['testimonies'] ?? null, $count['missions'] ?? null, $count['films'] ?? null], [1, 1, 1], 'counted in the overview');
});

test('videos: a database from before the new kinds keeps its library and takes them after its migration — without a new plan', function () {
    $app = TestKit::app();
    $store = $app->store();
    // The library as version 10 left it.
    $sql = (string) $store->value("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'library_items'");
    $store->db->exec('DROP TABLE library_items');
    $store->db->exec(str_replace([", 'testimony'", ", 'mission'", ", 'film'"], '', $sql));
    check(refuses(fn() => libraryVideos($app, 'film', [100], 'FilmMig')), 'version 10 knows no films');
    [$preaching] = libraryVideos($app, 'preaching', [30], 'PreMig');
    $store->query('UPDATE library_items SET plays = 3 WHERE id = ?', [$preaching]);
    $plan = $app->catalog()->version();
    $store->set('schema', 10);
    Arche\Schema::migrate($store, $app->clock->nowMs());
    eq([(string) $store->value('SELECT kind FROM library_items WHERE id = ?', [$preaching]), (int) $store->value('SELECT plays FROM library_items WHERE id = ?', [$preaching])],
        ['preaching', 3], 'its preaching is kept as it was');
    foreach (['testimony' => 'WitMig', 'mission' => 'MisMig', 'film' => 'FilmMg'] as $kind => $prefix) {
        eq($app->library()->get(libraryVideos($app, $kind, [10], $prefix)[0])['kind'] ?? '', $kind, "it takes a $kind");
    }
    check(refuses(fn() => libraryVideos($app, 'film', [10], 'PreMig')), 'one video is still in the library once');
    eq($app->catalog()->version(), $plan, 'and nothing planned is thrown away');
});

test('videos: without the text model the host still names each kind — "a testimony", "einen Bericht aus der Mission", a film to watch — and a suggestion as one, also an anonymous listener\'s', function () {
    $t = Templates::texts('testimony', ['next' => ['kind' => 'testimony', 'title' => 'My Story', 'by' => 'I Am Second']]);
    eq([$t['en'], $t['de']], ['Now let us listen to a testimony: “My Story” from I Am Second.', 'Jetzt hören wir ein Glaubenszeugnis: „My Story“ von I Am Second.'], 'a testimony');
    $m = Templates::texts('break', ['next' => ['kind' => 'mission', 'title' => 'Hope in Nepal', 'by' => 'OM']]);
    eq($m['de'], 'Ihr hört ARCHE. Jetzt hören wir einen Bericht aus der Mission: „Hope in Nepal“ von OM.', 'a break before a mission video');
    $f = Templates::texts('film', ['next' => ['kind' => 'film', 'title' => 'The Jesus Film', 'by' => 'Jesus Film Project']]);
    eq($f['en'], 'Now let us watch a film together: “The Jesus Film” from Jesus Film Project.', 'a film is watched');
    $anon = Templates::texts('announce', ['next' => ['kind' => 'film', 'title' => 'The Chosen', 'by' => 'Angel Studios'], 'request' => ['type' => 'film', 'name' => '', 'place' => 'Berlin', 'message' => '']]);
    eq([$anon['en'], $anon['de']], ['A listener suggested this film for us: “The Chosen” from Angel Studios. Let us watch together.',
        'Diesen Film hat uns jemand aus unserer Hörerschaft empfohlen: „The Chosen“ von Angel Studios. Schauen wir gemeinsam zu.'],
        'an anonymous suggestion is still a suggestion — and names nobody, not even a place');
    $named = Templates::texts('announce', ['next' => ['kind' => 'testimony', 'title' => 'My Story', 'by' => 'X'], 'request' => ['type' => 'testimony', 'name' => 'Grace', 'place' => 'Lagos', 'message' => '']]);
    check(str_contains($named['de'], 'Grace (Lagos) hat uns dieses Glaubenszeugnis empfohlen') && !str_contains($named['en'], 'song'), 'de: ' . $named['de']);
});

test('videos: every video format is in every list — a type, a library kind, length limits, a paragraph of the moderation rules and a stub that approves it', function () {
    $app = TestKit::app();
    $fine = ['ok' => true, 'error' => '', 'embeddable' => true, 'public' => true, 'live' => false, 'age_restricted' => false, 'blocked' => [], 'allowed' => null];
    eq(Submissions::SUGGESTION_TYPES, array_values(Catalog::VIDEO_FORMATS), 'the suggestion types are the registry\'s');
    eq(Submissions::VIDEO_TYPES, ['song', ...array_values(Catalog::VIDEO_FORMATS)], 'and with songs, the video types');
    foreach (Catalog::VIDEO_FORMATS as $format => $type) {
        check(in_array($format, Catalog::FORMATS, true) && in_array($type, Catalog::SUBMISSION_TYPES, true), "$format: a format with its type");
        eq(Submissions::libraryKind($type), $format, "$type joins the library as $format");
        check(isset(Library::VIDEO_KINDS[$format]), "$format: moderators can add one");
        eq([Moderator::videoProblems(['duration_ms' => 1000] + $fine, $app->config, $type), Moderator::videoProblems(['duration_ms' => 86_400_000] + $fine, $app->config, $type)],
            [['too_short'], ['too_long']], "$type: limits both ways");
        check(str_contains(Policy::system(), "type \"$type\""), "$type: the moderation rules describe it");
        eq($app->text()->json("moderate_$type", 'moderation', '', '', [], 100, 'low')->data['verdict'] ?? null, 'approve', "$type: the stub approves it");
    }
});

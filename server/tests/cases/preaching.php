<?php
declare(strict_types=1);

use Arche\Host\Templates;
use Arche\Moderation\Moderator;
use Arche\Program\Drafter;
use Arche\Program\SubmissionWindow;

/**
 * Preaching programs: preachings from the library with songs between them,
 * listeners' suggestions (a YouTube link, checked like a song request) first,
 * and the library and intake rules around them.
 */

/**
 * A preaching program on T0's day (Berlin) from $startMin for $minutes, the
 * regular program around it.
 *
 * @param array<string,mixed> $settings over the defaults
 * @param list<string> $allowed
 * @return array<string,mixed> the program
 */
function preachingProgram(Arche\App $app, int $startMin, int $minutes = 90, array $settings = [], array $allowed = ['preaching']): array
{
    $cat = $app->catalog();
    $cid = (int) TestKit::main($app)['id'];
    $p = $cat->saveProgram(null, $cid, ['slug' => 'sermon', 'title_en' => 'Sunday Sermon', 'title_de' => 'Sonntagspredigt', 'allowed' => $allowed,
        'settings' => array_replace_recursive(['format' => 'preaching'], $settings)], 'test');
    $plan = $cat->saveDayPlan(null, $cid, 'Sermon', [['start_min' => $startMin, 'end_min' => $startMin + $minutes, 'program_id' => $p['id']]], 'test');
    $cat->addSpecialDay($cid, ['name' => 'Sermon', 'kind' => 'date', 'month' => 9, 'day' => 23, 'day_plan_id' => $plan], 'test');
    return $p;
}

/** Preachings straight into the library, each $minutes long. @param list<int> $minutes @return list<int> */
function preachings(Arche\App $app, array $minutes): array
{
    $ids = [];
    foreach ($minutes as $i => $m) {
        $ids[] = $app->store()->insert('library_items', [
            'kind' => 'preaching', 'yt_id' => sprintf('Preach%05d', $i + 1), 'title' => 'Sermon ' . ($i + 1), 'artist' => 'Pastor ' . ($i + 1),
            'duration_ms' => $m * 60_000, 'themes' => '["grace"]', 'created' => 1, 'updated' => 1,
        ]);
    }
    return $ids;
}

/**
 * The program's committed items in air order: the host by its kind (its
 * introduction of a preaching as "introduce"), a preaching ("suggested" when
 * a listener's), else the type.
 *
 * @return list<string>
 */
function sermonLabels(Arche\App $app, int $programId): array
{
    $out = [];
    foreach (TestKit::committed($app) as $it) {
        if ($it['program_id'] !== $programId) continue;
        $out[] = match (true) {
            $it['type'] === 'host' => $it['payload']['kind'] === 'preaching' ? 'introduce' : (string) $it['payload']['kind'],
            Drafter::isVideo($it, 'preaching') => $it['submission_id'] !== null ? 'suggested' : 'preaching',
            default => (string) $it['type'],
        };
    }
    return $out;
}

/** @return list<array<string,mixed>> committed airings of a library item */
function airingsOf(Arche\App $app, int $libraryId): array
{
    return array_values(array_filter(TestKit::committed($app), fn($i) => $i['library_id'] === $libraryId));
}

test('preaching: the program opens with a preaching its intro introduces, puts songs between, takes the next that fits and none twice', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    [$first, $second, $long] = preachings($app, [20, 25, 50]);
    $p = preachingProgram($app, 12 * 60 + 10, 90); // T0 is 12:00 in Berlin: 12:10–13:40
    ticks($app, 110);

    $labels = sermonLabels($app, (int) $p['id']);
    eq(array_slice($labels, 0, 10), ['intro', 'preaching', 'break', 'song', 'song', 'introduce', 'preaching', 'break', 'song', 'song'],
        'intro, a preaching, the host, two songs, then the host introduces the next one');
    eq(array_count_values($labels)['preaching'], 2, 'two preachings: the third, 50 minutes long, no longer fits');
    eq([count(airingsOf($app, $first)), count(airingsOf($app, $second)), count(airingsOf($app, $long))], [1, 1, 0], 'each once, in turn');
    $rest = array_slice($labels, 8);
    eq(array_values(array_unique($rest)), ['song', 'break', 'outro', 'stage'], 'then songs with the host between them, the outro and an "Up next" card');
    assertContiguous(TestKit::committed($app), 'the timeline is contiguous');

    $run = array_values(array_filter(TestKit::committed($app), fn($i) => $i['program_id'] === (int) $p['id']));
    [$intro, $preaching, $after] = $run;
    $ctx = hostContext($app, $intro);
    eq([$ctx['next']['kind'] ?? '', $ctx['next']['title'] ?? '', $ctx['next']['by'] ?? '', $ctx['next_uid'] ?? ''],
        ['preaching', 'Sermon 1', 'Pastor 1', $preaching['uid']], 'the intro knows the preaching, and is pinned to it');
    eq(hostContext($app, $after)['previous']['kind'] ?? '', 'preaching', 'the host after it knows it was a preaching');
    $introduce = $run[array_search('introduce', sermonLabels($app, (int) $p['id']), true)];
    eq(hostContext($app, $introduce)['next']['title'] ?? '', 'Sermon 2', 'the second is introduced by the host');
    $end = strtotime('2026-09-23T11:40:00Z') * 1000; // 13:40 in Berlin
    foreach ($run as $it) {
        if (Drafter::isVideo($it, 'preaching')) check($it['start_ms'] + $it['dur_ms'] <= $end + Arche\Program\Timing::SOFT_OVERRUN, 'every preaching ends within its program');
    }

    $slot = json_decode((string) file_get_contents($app->publicPath(Arche\Program\Timing::slotPath('main', $preaching['start_ms'] + 60_000))), true);
    $published = array_values(array_filter($slot['items'], fn($i) => $i['id'] === $preaching['uid']))[0] ?? [];
    eq([$published['type'] ?? '', $published['kind'] ?? '', $published['title'] ?? ''], ['song', 'preaching', 'Sermon 1'], 'published as a song item of the kind preaching');
    eq($slot['programs']['sermon']['format'] ?? '', 'preaching', 'in a preaching program');
});

test('preaching: a listener suggests one — checked as a preaching, it joins the library and airs next, announced by name; never twice', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    [$own] = preachings($app, [20]);
    $p = preachingProgram($app, 12 * 60 + 5, 120);
    ticks($app, 8); // the program's own preaching is on air
    $sub = $app->submissions()->submitPreaching(listener($app), TestKit::main($app),
        ['url' => 'https://youtu.be/PreachSugg1', 'name' => 'Samuel', 'place' => 'Accra', 'message' => 'It gave me hope.']);
    eq($sub['type'], 'preaching', 'a preaching suggestion');
    runJobs($app);
    $row = $app->submissions()->byPublicId($sub['id']) ?? [];
    eq($row['status'], 'approved', 'approved');
    $lib = $app->library()->get((int) $row['library_id']) ?? [];
    eq([$lib['kind'], $lib['source'], $lib['duration_ms']], ['preaching', 'submission', 1_800_000], 'in the library as a preaching');
    check(!str_contains((string) json_encode($lib), 'hope'), 'without the listener\'s words');
    ticks($app, 80);

    eq(array_slice(sermonLabels($app, (int) $p['id']), 0, 8), ['intro', 'preaching', 'break', 'song', 'song', 'announce', 'suggested', 'break'],
        'the program\'s own preaching was on air already; the suggestion is the next');
    $items = TestKit::committed($app);
    $at = array_key_first(array_filter($items, fn($i) => $i['submission_id'] === (int) $row['id']));
    [$announce, $preaching, $after] = [$items[$at - 1], $items[$at], $items[$at + 1]];
    $ctx = hostContext($app, $announce);
    eq([$ctx['request']['type'] ?? '', $ctx['request']['name'] ?? '', $ctx['request']['message'] ?? '', $ctx['next']['kind'] ?? ''],
        ['preaching', 'Samuel', 'It gave me hope.', 'preaching'], 'announced as Samuel\'s suggestion, with his word on why');
    eq([$preaching['payload']['kind'], $preaching['payload']['request']['name'] ?? ''], ['preaching', 'Samuel'], 'the card names who suggested it');
    $before = hostContext($app, $after)['previous_request'] ?? [];
    eq([$before['kind'] ?? '', $before['name'] ?? '', $before['preaching']['title'] ?? ''], ['preaching suggestion', 'Samuel', $lib['title']], 'the host thanks him after it');
    eq(count(airingsOf($app, (int) $lib['id'])), 1, 'it airs once — the selection left it to the suggestion');
    eq(count(airingsOf($app, $own)), 1, 'and the program\'s own once');
    eq($app->submissions()->publicView($app->submissions()->get((int) $row['id']) ?? [])['status'], 'aired', 'marked aired');
});

test('preaching: only a preaching program takes suggestions — elsewhere the type is dropped — and a waiting one fills the queue with its length', function () {
    $app = TestKit::app();
    $ch = TestKit::main($app);
    $cat = $app->catalog();
    eq($cat->saveProgram((int) $ch['fallback_program_id'], (int) $ch['id'], ['allowed' => ['song', 'preaching']], 'test')['allowed'], ['song'],
        'a music program drops the type');
    check(refuses(fn() => $app->submissions()->submitPreaching(listener($app), TestKit::main($app), ['url' => 'https://youtu.be/PreachMusic']), 'not_accepted_now'),
        'and refuses a suggestion');

    $p = preachingProgram($app, 12 * 60, 60, [], ['preaching', 'song']);
    eq([$p['allowed'], $cat->programRef($p)['format']], [['song', 'preaching'], 'preaching'], 'a preaching program keeps it, published with its format');
    $states = fn(): array => (array) SubmissionWindow::states($app, $ch, $cat->program((int) $p['id']) ?? [], $app->resolver()->blockAt($ch, TestKit::T0), TestKit::T0);
    eq($states(), ['song' => 'open', 'preaching' => 'open'], 'its minute files offer it');
    [$st, $d] = call($app, 'POST', '/api/submissions/preaching', ['channel' => 'main', 'url' => 'https://youtu.be/PreachQueue', 'name' => 'Ada'], authHeaders());
    eq([$st, $d['submission']['type'] ?? '', $d['submission']['status'] ?? ''], [200, 'preaching', 'pending'], 'sent through the API');
    runJobs($app);
    eq($app->submissions()->queuedAirtime((int) $ch['id'], (int) $p['id']), 1_800_000, 'a waiting preaching promises its whole length');
    eq($states()['preaching'], 'closed', 'which fills the 30-minute queue');

    eq($cat->saveProgram((int) $p['id'], (int) $ch['id'], ['settings' => ['format' => 'music']], 'test')['allowed'], ['song'], 'back to music, the type goes');
});

test('preaching: a suggestion too long for the time left goes to the library at once, for a later program, and holds no intake', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    preachings($app, [20]);
    $p = preachingProgram($app, 12 * 60, 45, ['max_queue_min' => 60]);
    ticks($app, 2);
    // 30 minutes, with about 25 left after the program's own preaching: it could never fit.
    $sub = $app->submissions()->submitPreaching(listener($app), TestKit::main($app), ['url' => 'https://youtu.be/PreachLong1', 'name' => 'Ben']);
    runJobs($app);
    $row = $app->submissions()->byPublicId($sub['id']) ?? [];
    eq($app->submissions()->publicView($row)['status'], 'library', 'checked, it may play in a later program');
    eq($app->submissions()->queuedAirtime((int) TestKit::main($app)['id'], (int) $p['id']), 0, 'and keeps no intake closed meanwhile');
    ticks($app, 55);
    check(!array_filter(TestKit::committed($app), fn($i) => $i['submission_id'] === (int) $row['id']), 'nothing of it aired');
    eq(array_count_values(sermonLabels($app, (int) $p['id']))['preaching'] ?? 0, 1, 'the program\'s own preaching aired, then songs');
});

test('preaching: the YouTube check holds a suggestion to 5–90 minutes, a song to its own limits; an overlong one can be overruled', function () {
    $app = TestKit::app();
    $fine = ['ok' => true, 'error' => '', 'embeddable' => true, 'public' => true, 'live' => false, 'age_restricted' => false,
        'duration_ms' => 1_800_000, 'blocked' => [], 'allowed' => null];
    eq(Moderator::videoProblems($fine, $app->config, 'preaching'), [], 'a half-hour sermon');
    eq(Moderator::videoProblems(['duration_ms' => 240_000] + $fine, $app->config, 'preaching'), ['too_short'], 'a four-minute clip is no preaching');
    eq(Moderator::videoProblems(['duration_ms' => 6_000_000] + $fine, $app->config, 'preaching'), ['too_long'], 'a whole service is too long');
    eq(Moderator::videoProblems($fine, $app->config), ['too_long'], 'as a song request it would be too long');

    $ch = TestKit::main($app);
    preachingProgram($app, 12 * 60, 120);
    $sub = $app->submissions()->submitPreaching(listener($app), $ch, ['url' => 'https://youtu.be/PreachOver1']);
    $id = (int) $app->submissions()->byPublicId($sub['id'])['id'];
    $app->submissions()->reject($id, 'not_suitable', ['video' => ['too_long'], 'duration_ms' => 6_000_000], 'moderator');
    $app->store()->update('submissions', ['meta' => json_encode(['youtube' => ['title' => 'A long sermon', 'duration_ms' => 6_000_000]])], 'id = ?', [$id]);
    eq($app->submissions()->overruleBlocker($app->submissions()->get($id) ?? []), null, 'the station\'s length limit can be overruled');
    $app->store()->update('submissions', ['verdict' => json_encode(['video' => ['not_embeddable']])], 'id = ?', [$id]);
    eq($app->submissions()->overruleBlocker($app->submissions()->get($id) ?? []), 'video_unplayable', 'a video that would not play cannot');
});

test('preaching: moderators add preachings by link, up to three hours, and find them by kind', function () {
    $app = TestKit::app(['YOUTUBE_API_KEY' => 'test-key']);
    $http = new FakeHttp();
    $app->set('http', $http);
    $video = fn(string $id, string $duration) => new Arche\Support\HttpResponse(200, (string) json_encode(['items' => [[
        'id' => $id,
        'snippet' => ['title' => 'Pastor Ruth Adeyemi - The Prodigal Son', 'channelTitle' => 'Grace Chapel', 'liveBroadcastContent' => 'none', 'tags' => [], 'description' => ''],
        'contentDetails' => ['duration' => $duration],
        'status' => ['embeddable' => true, 'privacyStatus' => 'public', 'uploadStatus' => 'processed'],
    ]]]));
    $h = modHeaders($app);
    $http->answers[] = $video('PreachLib01', 'PT41M');
    [$st, $d] = call($app, 'POST', '/api/mod/library', ['url' => 'https://youtu.be/PreachLib01', 'kind' => 'preaching', 'themes' => ['grace']], $h);
    eq([$st, $d['item']['kind'] ?? null, $d['item']['title'] ?? null, $d['item']['artist'] ?? null, $d['item']['duration_ms'] ?? null],
        [200, 'preaching', 'The Prodigal Son', 'Pastor Ruth Adeyemi', 2_460_000], 'added as a preaching, its preacher as the artist');
    $http->answers[] = $video('PreachLib02', 'PT41M');
    eq(call($app, 'POST', '/api/mod/library', ['url' => 'https://youtu.be/PreachLib02'], $h)[1]['error'] ?? null, 'video_duration', 'as a song it is too long');
    $http->answers[] = $video('PreachLib03', 'PT3H30M');
    eq(call($app, 'POST', '/api/mod/library', ['url' => 'https://youtu.be/PreachLib03', 'kind' => 'preaching'], $h)[1]['error'] ?? null, 'video_duration', 'and three and a half hours is too long for a preaching');
    eq(array_column(modGet($app, '/api/mod/library', ['kind' => 'preaching'], $h)[1]['items'], 'yt_id'), ['PreachLib01'], 'listed by its kind');
    eq(modGet($app, '/api/mod/overview', [], $h)[1]['library']['preachings'] ?? null, 1, 'counted in the overview');
});

test('preaching: a database from before preachings keeps its library and takes them after its migration', function () {
    $app = TestKit::app();
    $store = $app->store();
    // The library as version 7 left it: its kinds without preachings.
    $sql = (string) $store->value("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'library_items'");
    $store->db->exec('DROP TABLE library_items');
    $store->db->exec(str_replace([", 'preaching'", ", 'testimony'", ", 'mission'", ", 'film'"], '', $sql));
    check(refuses(fn() => preachings($app, [30])), 'version 7 knows no preachings');
    [$song] = TestKit::songs($app, 1);
    $store->query('UPDATE library_items SET plays = 7 WHERE id = ?', [$song]);
    $store->set('schema', 7);
    Arche\Schema::migrate($store, $app->clock->nowMs());
    eq([(string) $store->value('SELECT title FROM library_items WHERE id = ?', [$song]), (int) $store->value('SELECT plays FROM library_items WHERE id = ?', [$song])],
        ['Song 1', 7], 'its songs are kept as they were');
    [$preaching] = preachings($app, [30]);
    eq($app->library()->get($preaching)['kind'] ?? '', 'preaching', 'and it takes preachings');
    check(refuses(fn() => TestKit::songs($app, 1, 240_000, ['yt_id' => 'Preach00001'])), 'one video is still in the library once');
});

test('preaching: without the text model the host still introduces a preaching, and a suggestion by name — never as a song', function () {
    $next = ['kind' => 'preaching', 'title' => 'The Prodigal Son', 'by' => 'Pastor Ruth'];
    $t = Templates::texts('preaching', ['next' => $next]);
    check(str_contains($t['en'], 'preaching: “The Prodigal Son” by Pastor Ruth'), 'en: ' . $t['en']);
    check(str_contains($t['de'], 'Predigt: „The Prodigal Son“ von Pastor Ruth'), 'de: ' . $t['de']);
    $a = Templates::texts('announce', ['next' => $next, 'request' => ['type' => 'preaching', 'name' => 'Samuel', 'place' => 'Accra', 'message' => '']]);
    check(str_contains($a['en'], 'Samuel (Accra) suggested this preaching') && !str_contains($a['en'], 'song'), 'en: ' . $a['en']);
    check(str_contains($a['de'], 'Samuel (Accra) hat uns diese Predigt empfohlen'), 'de: ' . $a['de']);
    eq(Templates::texts('announce', ['request' => ['name' => 'Jonas', 'place' => '', 'message' => '']])['en'], 'This next song is a request from Jonas.', 'a song request as before');
    $program = ['program' => ['title' => ['en' => 'Sunday Sermon', 'de' => 'Sonntagspredigt']], 'next' => $next];
    eq(Templates::texts('intro', $program)['en'], 'Welcome to Sunday Sermon on ARCHE. Now let us listen to a preaching: “The Prodigal Son” by Pastor Ruth.', 'the intro introduces the preaching after it');
    eq(Templates::texts('break', $program)['de'], 'Ihr hört ARCHE. Jetzt hören wir eine Predigt: „The Prodigal Son“ von Pastor Ruth.', 'so does a break');
    eq(Templates::texts('break', ['next' => ['title' => 'Good God', 'artist' => 'Chris Tomlin']])['en'], "You're listening to ARCHE. Up next: Good God – Chris Tomlin.", 'a break before a song as before');
});

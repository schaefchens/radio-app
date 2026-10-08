<?php
declare(strict_types=1);

use Arche\Ai\StubVideoListener;
use Arche\Ai\StubWebResearch;
use Arche\Library\Knowledge;
use Arche\Support\HttpResponse;

/** A Data API answer for one video, with YouTube's topics. */
function knYt(string $id, string $title, string $channel, array $topics = ['Christian_music'], string $duration = 'PT4M'): HttpResponse
{
    return new HttpResponse(200, (string) json_encode(['items' => [[
        'id' => $id,
        'snippet' => ['title' => $title, 'channelTitle' => $channel, 'channelId' => 'UC' . str_pad($channel, 22, 'x'), 'liveBroadcastContent' => 'none',
            'tags' => ['worship'], 'description' => 'Official video. Follow us https://example.org/shop', 'defaultAudioLanguage' => 'de', 'publishedAt' => '2022-08-26T10:00:00Z'],
        'contentDetails' => ['duration' => $duration],
        'status' => ['embeddable' => true, 'privacyStatus' => 'public', 'uploadStatus' => 'processed'],
        'topicDetails' => ['topicCategories' => array_map(fn($t) => 'https://en.wikipedia.org/wiki/' . $t, $topics)],
    ]]]));
}

function knJson(array $data, int $status = 200): HttpResponse
{
    return new HttpResponse($status, (string) json_encode($data));
}

/** OpenAI's background response, finished: its searches' sources and the JSON answer. */
function knResearched(string $id, array $answer, array $sources): HttpResponse
{
    return knJson(['id' => $id, 'status' => 'completed', 'model' => 'gpt-6.1-sol-2026-09-29',
        'output' => [
            ['type' => 'web_search_call', 'action' => ['type' => 'search', 'sources' => array_map(fn($u) => ['type' => 'url', 'url' => $u], $sources)]],
            ['type' => 'web_search_call', 'action' => ['type' => 'open_page', 'url' => 'https://de.wikipedia.org/wiki/Opened']],
            ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($answer), 'annotations' => []]]],
        ],
        'usage' => ['input_tokens' => 20_000, 'output_tokens' => 1_200, 'input_tokens_details' => ['cached_tokens' => 0]],
    ]);
}

/** Gemini's background interaction, finished. */
function knHeard(string $id, array $answer): HttpResponse
{
    return knJson(['id' => $id, 'status' => 'completed', 'model' => 'gemini-3.8-flash',
        // The question comes back too, with content of its own: only the model's output is the answer.
        'steps' => [['type' => 'user_input', 'content' => [['type' => 'text', 'text' => 'Judge this video.']]], ['type' => 'model_output', 'content' => [['type' => 'text', 'text' => json_encode($answer)]]]],
        'usage' => ['total_input_tokens' => 9_000, 'total_output_tokens' => 600, 'total_thought_tokens' => 0],
    ]);
}

/** A research answer as OpenAI's schema shapes it. */
function knResearch(array $over = []): array
{
    return array_replace_recursive([
        'identity' => ['identified' => true, 'title' => 'Ein Gott, der das Meer teilt', 'artist' => 'Timo Langner', 'original' => '',
            'writers' => [['name' => 'Timo Langner', 'role' => 'lyrics', 'died' => '']], 'year' => '2022',
            'artist_background' => 'A German worship leader ([gerth.de](https://www.gerth.de/person/langner-timo.html?utm_source=openai)).',
            'christian_artist' => 'yes'],
        'bible' => ['Exodus 14:21'],
        'facts' => [
            ['en' => 'Timo Langner wrote both the words and the music.', 'de' => 'Timo Langner schrieb Text und Musik.', 'source' => 'https://www.gerth.de/person/langner-timo.html'],
            ['en' => 'It was released in August 2022.', 'de' => 'Es erschien im August 2022.', 'source' => 'https://made-up.example/never-searched'],
        ],
        'content_notes' => 'A worship song trusting God who parts the sea ([media.cvim.de](https://media.cvim.de/x.pdf)).',
        'public_domain' => ['is' => false, 'why' => 'Written in 2022.', 'url' => ''],
        'sources' => ['https://www.gerth.de/person/langner-timo.html'],
    ], $over);
}

function knLiveStation(): array
{
    $app = TestKit::app(['AI_MODE' => 'live', 'OPENAI_KEY' => 'sk-test', 'GEMINI_API_KEY' => 'g-test', 'YOUTUBE_API_KEY' => 'test-key']);
    $http = new FakeHttp();
    $app->set('http', $http);
    return [$app, $http];
}

/** Run the jobs, then let the 30 s of a wait pass, $rounds times. */
function knRun(Arche\App $app, int $rounds = 1): void
{
    for ($i = 0; $i < $rounds; $i++) {
        $app->runner()->runUntilBudget();
        TestKit::clock($app)->advance(31_000);
    }
}

test('knowledge: the library is looked up a few at a time, most played first — research and listening start together and, answered at once, finish in the tick they started', function () {
    $app = TestKit::app();
    $ids = TestKit::songs($app, 5);
    $plays = [];
    foreach ($ids as $n => $id) {
        $app->store()->update('library_items', ['plays' => 10 * $n], 'id = ?', [$id]);
        $plays[(string) $app->library()->get($id)['yt_id']] = 10 * $n;
    }
    $app->tick()->run('test');
    $known = $app->store()->all('SELECT yt_id, state FROM video_knowledge');
    eq(array_column($known, 'state'), ['ready', 'ready', 'ready'], 'three at a time, done in the same tick');
    $looked = array_map(fn($r) => $plays[$r['yt_id']], $known);
    rsort($looked);
    eq($looked, [40, 30, 20], 'the most played first');
    $research = $app->research();
    $listener = $app->listener();
    check($research instanceof StubWebResearch && $listener instanceof StubVideoListener, 'stubs in stub mode');
    eq(count($research->calls), 3, 'researched three');
    eq(count($listener->calls), 3, 'listened to three');
    $first = $app->knowledge()->get((string) $app->library()->get($ids[4])['yt_id']);
    eq($first['analysis']['christian'] ?? null, 'yes', 'the analysis kept');
    eq(count($first['research']['facts'] ?? []), 1, 'the fact kept, its page among the sources');
    eq($first['work'], [], "YouTube's data gone once done");
    TestKit::clock($app)->advance(600_000);
    $app->tick()->run('test');
    eq((int) $app->store()->value("SELECT COUNT(*) FROM video_knowledge WHERE state = 'ready'"), 5, 'the rest ten minutes later');
});

test('knowledge: OpenAI researches and Gemini listens in the background — started in one tick, asked again later, then deleted at both; a fact keeps only a page the search consulted, texts lose their citations', function () {
    [$app, $http] = knLiveStation();
    $http->answers = [
        knYt('LangnerSea1', 'Timo Langner – Ein Gott der das Meer teilt (Offizielles Musikvideo)', 'Gerth Medien'),
        knJson(['id' => 'resp_1', 'status' => 'queued']),
        knJson(['id' => 'v1_listen', 'status' => 'in_progress']),
        knJson(['id' => 'resp_1', 'status' => 'in_progress']),
        knJson(['id' => 'v1_listen', 'status' => 'in_progress']),
    ];
    $row = $app->knowledge()->ensure('LangnerSea1', 'song', 30);
    eq($row['state'] ?? null, 'queued', 'queued');
    knRun($app);
    eq($app->knowledge()->get('LangnerSea1')['state'], 'working', 'both calls open after the first tick');
    $post = $http->body(1);
    eq([$post['background'], $post['store'], $post['tools'][0]['type'], $post['include'][0], $post['text']['format']['strict']], [true, true, 'web_search', 'web_search_call.action.sources', true],
        'research: background, stored, web search with its sources, a strict schema');
    check(str_contains($http->sent[1]['url'], 'api.openai.com/v1/responses'), 'to OpenAI');
    $listen = $http->body(2);
    eq([$listen['input'][0]['type'], $listen['input'][0]['uri'], $listen['input'][0]['resolution'], $listen['background'], $listen['response_format']['mime_type']],
        ['video', 'https://www.youtube.com/watch?v=LangnerSea1', 'low', true, 'application/json'], 'listening: the public video, low resolution, in the background, JSON');
    eq($listen['input'][0]['processing'], ['type' => 'static', 'fps' => 0.2], 'a frame every five seconds: what is heard matters');
    check(str_contains($listen['system_instruction'], 'Scripture is the measure'), "the station's standard goes with it");
    check(str_contains($listen['input'][1]['text'], 'Christian_music'), "YouTube's topics go with it");

    $sources = ['https://www.gerth.de/person/langner-timo.html?utm_source=openai', 'https://music.apple.com/x'];
    $http->answers = [
        knResearched('resp_1', knResearch(), $sources), knJson([]),
        knHeard('v1_listen', StubVideoListener::fixed()), knJson([]),
    ];
    knRun($app);
    $row = $app->knowledge()->get('LangnerSea1');
    eq($row['state'], 'ready', 'ready once both answered');
    eq(array_column($row['research']['facts'], 'en'), ['Timo Langner wrote both the words and the music.'], 'the fact with a page the search never opened is dropped');
    eq($row['research']['identity']['artist_background'], 'A German worship leader.', 'citations stripped');
    check(!str_contains($row['research']['content_notes'], 'http'), 'no links left in the notes');
    eq([$row['title'], $row['artist']], ['Ein Gott, der das Meer teilt', 'Timo Langner'], 'the names research found');
    eq([$row['yt_title'], $row['yt_artist']], ['Ein Gott der das Meer teilt', 'Timo Langner'], "YouTube's split, to know an item still carries it");
    $deleted = array_values(array_filter($http->sent, fn($s) => $s['method'] === 'DELETE'));
    eq(array_map(fn($s) => preg_replace('~^https://[^/]+~', '', $s['url']), $deleted), ['/v1/responses/resp_1', '/v1beta/interactions/v1_listen'], 'both answers deleted once read');
    check($row['cost_micros'] > 0, 'what it cost, kept');
    $kinds = array_column($app->usage()->recent(1), 'kind');
    check(in_array('knowledge:research', $kinds, true) && in_array('knowledge:listen', $kinds, true), 'counted as look-ups');
    eq($app->usage()->spentTodayMicros(), 0, "never against the host's and the checks' budget");
});

test('knowledge: a look-up that takes too long is cancelled — research alone gives the host its facts, the check nothing; both failing is a failed look-up', function () {
    [$app, $http] = knLiveStation();
    $http->answers = [
        knYt('SlowOne0001', 'Slow Song', 'Slow Band'),
        knJson(['id' => 'resp_s', 'status' => 'queued']),
        knJson(['id' => 'v1_s', 'status' => 'in_progress']),
        knJson(['id' => 'resp_s', 'status' => 'in_progress']),
        knJson(['id' => 'v1_s', 'status' => 'in_progress']),
    ];
    $app->knowledge()->ensure('SlowOne0001', 'song');
    knRun($app);
    $http->answers = [knResearched('resp_s', knResearch(), ['https://www.gerth.de/person/langner-timo.html']), knJson([]), knJson(['id' => 'v1_s', 'status' => 'in_progress'])];
    knRun($app);
    eq($app->knowledge()->get('SlowOne0001')['state'], 'working', 'research in, listening still running');
    TestKit::clock($app)->advance(400_000);
    $http->answers = [knJson(['id' => 'v1_s', 'status' => 'in_progress']), knJson([]), knJson([])];
    knRun($app);
    $row = $app->knowledge()->get('SlowOne0001');
    check(in_array('/v1beta/interactions/v1_s/cancel', array_map(fn($s) => (string) parse_url($s['url'], PHP_URL_PATH), $http->sent), true), 'the listening cancelled');
    eq($row['state'], 'ready', 'ready with what came');
    check(str_contains($row['error'], 'listening: timeout'), 'the timeout said');
    eq(Knowledge::forJudge($row), null, 'nothing heard: nothing for the check (it fails closed)');

    $http->answers = [
        knYt('FailsBoth01', 'Fails', 'Nobody'),
        knJson(['id' => 'resp_f', 'status' => 'queued']),
        knJson(['id' => 'v1_f', 'status' => 'in_progress']),
        knJson(['id' => 'resp_f', 'status' => 'failed', 'output' => []]), knJson([]),
        knJson(['id' => 'v1_f', 'status' => 'failed']), knJson([]),
    ];
    $app->knowledge()->ensure('FailsBoth01', 'song');
    knRun($app);
    $row = $app->knowledge()->get('FailsBoth01');
    eq($row['state'], 'failed', 'both failed: a failed look-up');
    check(Knowledge::settled($row), 'settled: a check stops waiting');
});

test('knowledge: look-ups have a budget of their own — spent, a look-up fails "budget" and the backfill waits; the host never pays for them', function () {
    $app = TestKit::app();
    TestKit::songs($app, 4);
    $app->usage()->record('knowledge:listen', 0, 0, 5_000_000);
    $app->tick()->run('test');
    eq((int) $app->store()->value('SELECT COUNT(*) FROM video_knowledge'), 0, 'the backfill waits while the budget is spent');
    check($app->usage()->withinBudget(), "the host's and the checks' budget untouched");
    $row = $app->knowledge()->ensure('Requested01', 'song', 30);
    $app->runner()->runUntilBudget();
    $row = $app->knowledge()->get('Requested01');
    eq([$row['state'], $row['error']], ['failed', 'budget'], 'a look-up over the budget fails, said so');
    $app->knowledge()->saveSettings(['budget_usd' => 20], 'mod:test');
    $app->usage()->record('knowledge:listen', 0, 0, 1);
    eq($app->knowledge()->ensure('Requested01', 'song', 30, true)['state'] ?? null, 'queued', 'tried again when asked to');
});

test('knowledge: clean names go into the library only on air, and only where an item still has YouTube\'s names — a moderator\'s stay; switching on air applies what is known', function () {
    $app = TestKit::app();
    $app->research()->respond(fn() => knResearch());
    $mine = $app->store()->insert('library_items', ['kind' => 'song', 'yt_id' => 'SplitByYt01', 'title' => 'Song', 'artist' => 'Band', 'duration_ms' => 200_000, 'created' => 1, 'updated' => 1]);
    $named = $app->store()->insert('library_items', ['kind' => 'song', 'yt_id' => 'NamedByMod1', 'title' => 'As a moderator wrote it', 'artist' => 'Someone', 'duration_ms' => 200_000, 'created' => 1, 'updated' => 1]);
    // In stub mode without a YouTube key the look-up takes the item's own names as YouTube's.
    $app->knowledge()->ensure('SplitByYt01', 'song');
    $app->runner()->runUntilBudget();
    eq($app->library()->get($mine)['title'], 'Song', 'off air: no name changes');
    $app->knowledge()->saveSettings(['air' => true], 'mod:test');
    eq([$app->library()->get($mine)['title'], $app->library()->get($mine)['artist']], ['Ein Gott, der das Meer teilt', 'Timo Langner'], 'switched on air: the clean names');
    $app->knowledge()->saveSettings(['air' => false], 'mod:test');
    $app->knowledge()->ensure('NamedByMod1', 'song');
    $app->runner()->runUntilBudget();
    // YouTube calls it otherwise: a moderator wrote these names.
    $app->store()->update('video_knowledge', ['yt_title' => 'Raw title', 'yt_artist' => 'Raw channel'], 'yt_id = ?', ['NamedByMod1']);
    $app->knowledge()->saveSettings(['air' => true], 'mod:test');
    eq($app->library()->get($named)['title'], 'As a moderator wrote it', "a moderator's names stay");
    eq($app->library()->get($mine)['title'], 'Ein Gott, der das Meer teilt', 'and the clean ones are not touched again');
});

test('knowledge: the host gets the message as heard, the passage and one fact — a different one each airing, each resting 72 hours once told; nothing off air, no message for what is not biblical', function () {
    $app = TestKit::app();
    $facts = [
        ['en' => 'Fact one.', 'de' => 'Fakt eins.', 'source' => 'https://example.org/one'],
        ['en' => 'Fact two.', 'de' => 'Fakt zwei.', 'source' => 'https://example.org/two'],
    ];
    $app->research()->respond(fn() => knResearch(['facts' => $facts, 'sources' => ['https://example.org/one', 'https://example.org/two']]));
    $app->knowledge()->ensure('HostFacts01', 'song');
    $app->runner()->runUntilBudget();
    $k = $app->knowledge();
    eq($k->forHost($k->get('HostFacts01')), [], 'nothing while the switch is off');
    $k->saveSettings(['air' => true], 'mod:test');
    $first = $k->forHost($k->get('HostFacts01'));
    eq([$first['about']['en'] ?? '', $first['bible'] ?? '', $first['fact']['en'] ?? ''], ['The song says that God stays near in every storm.', 'Psalm 23', 'Fact one.'], 'message, passage and the first fact');
    eq($k->told($first['ref'], $app->clock->nowMs()), ['title' => 'example.org', 'url' => 'https://example.org/one'], 'told: its source for the stage');
    eq($k->forHost($k->get('HostFacts01'))['fact']['en'] ?? '', 'Fact two.', 'the next airing: the other fact');
    $k->told(['yt' => 'HostFacts01', 'i' => 1], $app->clock->nowMs());
    check(!isset($k->forHost($k->get('HostFacts01'))['fact']), 'both told: no fact until one has rested');
    TestKit::clock($app)->advance(Knowledge::FACT_REST_HOURS * 3_600_000 + 1);
    eq($k->forHost($k->get('HostFacts01'))['fact']['en'] ?? '', 'Fact one.', 'rested: the one told longest ago');

    $app->listener()->respond(fn() => ['biblical' => 'no', 'concerns' => [['what' => 'A prayer to Mary', 'why' => 'Not the standard', 'quote' => 'Segne du Maria']]] + StubVideoListener::fixed());
    $k->ensure('MarianSong1', 'song');
    $app->runner()->runUntilBudget();
    check(!isset($k->forHost($k->get('MarianSong1'))['about']), 'no message presented for what is not biblical');
});

test('knowledge: what came is checked — long quotes, unknown values, a fact that prays or talks charts, names with YouTube\'s extras', function () {
    $a = Knowledge::cleanAnalysis([
        'heard' => true, 'christian' => 'maybe', 'biblical' => 'yes', 'energy' => 'wild', 'age' => 'kids',
        'quotes' => [['text' => 'one two three four five six seven eight nine ten eleven twelve thirteen', 'at' => '1:00'], ['text' => 'short and fine', 'at' => 'later']],
        'fits' => ['worship', 'disco'], 'themes' => ['Trust', 'trust', ''],
    ]);
    eq([$a['christian'], $a['biblical'], $a['energy'], $a['age']], ['unclear', 'yes', 'moderate', 'adults'], 'unknown values: the careful default');
    eq($a['quotes'], [['text' => 'short and fine', 'at' => '']], 'a quote of more than 12 words is dropped — never the lyrics');
    eq([$a['fits'], $a['themes']], [['worship'], ['trust']], 'known fits only, themes once');
    eq(Knowledge::cleanAnalysis(['heard' => false, 'christian' => 'yes', 'biblical' => 'yes'])['christian'], 'unclear', 'nothing heard: nothing is sure');
    $r = Knowledge::cleanResearch(knResearch([
        'identity' => ['title' => 'Song #worship @band 🎶'],
        'facts' => [
            ['en' => 'Timo Langner wrote both the words and the music.', 'de' => 'Timo Langner schrieb Text und Musik.', 'source' => 'https://www.gerth.de/person/langner-timo.html'],
            ['en' => 'Lord, bless you all. Amen.', 'de' => 'Herr, segne euch. Amen.', 'source' => 'https://www.gerth.de/person/langner-timo.html'],
            ['en' => 'It reached number 3 in the charts.', 'de' => 'Es erreichte Platz 3 der Charts.', 'source' => 'https://www.gerth.de/person/langner-timo.html'],
        ],
    ]), ['https://gerth.de/person/langner-timo.html/'], 'X');
    eq($r['identity']['title'], 'Song', "names without YouTube's extras");
    eq(array_column($r['facts'], 'en'), ['Timo Langner wrote both the words and the music.'], 'a praying fact and a charts fact dropped; the page matched without www, a last slash or tracking');
    $byId = Knowledge::cleanResearch(knResearch(['facts' => [
        ['en' => 'From the page searched.', 'de' => 'Von der gesuchten Seite.', 'source' => 'https://kirche.example/beitraege?id=18167&utm_source=openai'],
        ['en' => 'From another page of the site.', 'de' => 'Von einer anderen Seite.', 'source' => 'https://kirche.example/beitraege?id=99999'],
    ]]), ['https://kirche.example/beitraege?id=18167'], 'X');
    eq(array_column($byId['facts'], 'en'), ['From the page searched.'], 'a query that names the page counts: another id is another page');
});

test('knowledge: admins set the switches, the budget and the standard — the checks need listening; moderators correct facts only with a page, a full text only in the public domain', function () {
    $app = TestKit::app();
    $k = $app->knowledge();
    eq($k->settings()['standard'], Knowledge::STANDARD, 'the default standard');
    $k->saveSettings(['standard' => 'Only psalms.', 'budget_usd' => 500, 'checks' => true], 'mod:test');
    eq([$k->settings()['standard'], $k->settings()['budget_usd'], $k->settings()['checks']], ['Only psalms.', 100.0, true], 'saved; the budget bounded');
    $k->saveSettings(['standard' => ''], 'mod:test');
    eq($k->settings()['standard'], Knowledge::STANDARD, 'emptied: the default again');
    $live = TestKit::app(['AI_MODE' => 'live', 'OPENAI_KEY' => 'sk-test', 'GEMINI_API_KEY' => '']);
    check(refuses(fn() => $live->knowledge()->saveSettings(['checks' => true], 'mod:test'), 'knowledge_not_configured'), 'no checks without listening');

    $k->ensure('EditMe00001', 'song');
    $app->runner()->runUntilBudget();
    check(refuses(fn() => $k->edit('EditMe00001', ['facts' => [['en' => 'A fact.', 'de' => 'Ein Fakt.', 'source' => '']]], 'mod:x'), 'fact_needs_source'), 'a fact needs its page');
    check(refuses(fn() => $k->edit('EditMe00001', ['facts' => [['en' => 'Amen.', 'de' => 'Amen.', 'source' => 'https://example.org']]], 'mod:x'), 'fact_prays'), 'a fact never prays');
    check(refuses(fn() => $k->edit('EditMe00001', ['text' => 'All the lyrics'], 'mod:x'), 'text_not_public_domain'), 'no full text unless public domain');
    $row = $k->edit('EditMe00001', ['about' => ['en' => 'About trust.', 'de' => 'Über Vertrauen.'], 'facts' => [['en' => 'New.', 'de' => 'Neu.', 'source' => 'https://example.org/new']]], 'mod:x');
    eq([$row['analysis']['message_en'], count($row['research']['facts']), $row['edited_by']], ['About trust.', 1, 'mod:x'], 'corrected, and who did');
});

test('knowledge: a turned-down request\'s video is forgotten after 30 days; the library\'s stay; migration 16 replays on a current database', function () {
    $app = TestKit::app();
    TestKit::songs($app, 1);
    $app->knowledge()->ensure('NotInLib001', 'song');
    $app->runner()->runUntilBudget();
    $app->tick()->run('test');
    eq((int) $app->store()->value('SELECT COUNT(*) FROM video_knowledge'), 2, 'the library song and the request');
    TestKit::clock($app)->advance(31 * 86_400_000);
    eq($app->knowledge()->purge($app->clock->now() - 30 * 86400), 1, 'the request\'s video forgotten');
    eq((int) $app->store()->value('SELECT COUNT(*) FROM video_knowledge'), 1, "the library's kept");
    $app->store()->set('schema', 15);
    Arche\Schema::migrate($app->store(), $app->clock->nowMs());
    eq((int) $app->store()->value('SELECT COUNT(*) FROM video_knowledge'), 1, 'replayed: kept');
});

/** A song request sent and checked; $after: seconds that pass before the check runs again (its waits). */
function knRequest(Arche\App $app, string $yt, int $after = 31): array
{
    $sub = $app->submissions()->submitSong(listener($app), TestKit::main($app), ['url' => "https://youtu.be/$yt", 'name' => 'Jenny', 'place' => 'Munich', 'message' => 'For my mum']);
    runJobs($app);
    TestKit::clock($app)->advance($after * 1000);
    runJobs($app);
    return $app->submissions()->byPublicId($sub['id']) ?? [];
}

function knCheckStation(array $env = []): Arche\App
{
    $app = TestKit::app($env);
    TestKit::songs($app, 12);
    $app->tick()->run('test');
    $app->knowledge()->saveSettings(['checks' => true], 'mod:test');
    return $app;
}

test('knowledge: used in the checks, a request waits for its video\'s look-up and is judged by what was heard — a Christian-sounding parody is turned down; a listener\'s name and message never go out', function () {
    $app = knCheckStation();
    $app->listener()->respond(fn() => ['christian' => 'no', 'biblical' => 'concern', 'explicit' => true, 'age' => 'adults',
        'concerns' => [['what' => 'Profanity in a parody of a spiritual', 'why' => 'Not Christian content', 'quote' => 'the trouble I have seen']]] + StubVideoListener::fixed());
    $seen = [];
    $app->text()->respond('moderate_song', function (string $system, string $user) use (&$seen) {
        $seen = [$system, $user];
        $parody = str_contains($user, '"christian": "no"');
        return ['safe' => true, 'christian' => !$parody, 'biblical' => !$parody, 'program_fit' => true, 'message_ok' => true, 'verdict' => $parody ? 'reject' : 'approve',
            'themes' => [], 'moods' => [], 'languages' => ['en'], 'note' => $parody ? 'A profane parody of a spiritual.' : 'Fine.'];
    });
    $sub = $app->submissions()->submitSong(listener($app), TestKit::main($app), ['url' => 'https://youtu.be/KumbaYo0001', 'name' => 'Jenny', 'place' => 'Munich', 'message' => 'For my mum']);
    runJobs($app);
    eq($app->submissions()->byPublicId($sub['id'])['status'], 'checking', 'waiting for the look-up first');
    TestKit::clock($app)->advance(31_000);
    runJobs($app);
    $row = $app->submissions()->byPublicId($sub['id']);
    eq([$row['status'], $row['reason']], ['rejected', 'not_suitable'], 'judged by what was heard: turned down');
    check(str_contains($seen[0], 'Scripture is the measure'), "the station's standard is in the check's rules");
    check(str_contains($seen[1], '"heard"') && str_contains($seen[1], 'Profanity in a parody'), 'what was heard goes to the judge');
    eq(json_decode((string) $row['verdict'], true)['heard']['christian'] ?? null, 'no', 'kept beside the verdict for the moderators');
    check($app->research()->calls !== [], 'researched');
    foreach ($app->research()->calls as $input) {
        check(!str_contains($input, 'Jenny') && !str_contains($input, 'Munich') && !str_contains($input, 'For my mum'), "no listener's name, place or message in a look-up");
    }
});

test('knowledge: used in the checks, a request whose video nobody could hear is not accepted — or goes to a moderator where they review; a look-up still running holds it 3 minutes at most', function () {
    $app = knCheckStation();
    $app->listener()->respond(fn() => null);
    $row = knRequest($app, 'NotHeard001');
    eq([$row['status'], $row['reason'], json_decode((string) $row['verdict'], true)['error'] ?? ''], ['rejected', 'not_accepted', 'no_knowledge'], 'nothing heard: not accepted (fails closed)');

    $review = knCheckStation(['MODERATION_HUMAN_REVIEW' => '1']);
    $review->listener()->respond(fn() => null);
    eq(knRequest($review, 'NotHeard002')['status'], 'review', 'to a moderator where they review');

    $slow = knCheckStation();
    // A look-up under way that never ends in time (no job: its answers never come).
    $slow->store()->insert('video_knowledge', ['yt_id' => 'StillSlow01', 'kind' => 'song', 'state' => 'working', 'created' => 1, 'updated' => 1]);
    $row = knRequest($slow, 'StillSlow01', 60);
    eq($row['status'], 'checking', 'still waiting after a minute');
    for ($i = 0; $i < 5; $i++) {
        TestKit::clock($slow)->advance(31_000);
        runJobs($slow);
    }
    $row = $slow->submissions()->byPublicId($row['public_id']);
    eq([$row['status'], $row['reason']], ['rejected', 'not_accepted'], 'after 3 minutes judged without it: not accepted');
});

test('knowledge: approval needs "biblical" while the standard is used — a Marian song is turned down; with the switch off the check is as it was', function () {
    $marian = fn() => ['christian' => 'yes', 'biblical' => 'no', 'addressed_to' => 'Mary',
        'concerns' => [['what' => 'A prayer to Mary', 'why' => 'Prayer to Mary is not biblical by the standard', 'quote' => 'Segne du, Maria']]] + StubVideoListener::fixed();
    $judge = fn(string $system, string $user) => ['safe' => true, 'christian' => true, 'biblical' => false, 'program_fit' => true, 'message_ok' => true,
        'verdict' => 'approve', 'themes' => [], 'moods' => [], 'languages' => ['de'], 'note' => 'A Catholic Marian hymn.'];
    $app = knCheckStation();
    $app->listener()->respond($marian);
    $app->text()->respond('moderate_song', $judge);
    $row = knRequest($app, 'SegneMaria1');
    eq([$row['status'], $row['reason']], ['rejected', 'not_suitable'], 'not biblical: turned down, whatever else it is');

    $off = TestKit::app();
    TestKit::songs($off, 12);
    $off->tick()->run('test');
    $off->listener()->respond($marian);
    $off->text()->respond('moderate_song', $judge);
    $sub = $off->submissions()->submitSong(listener($off), TestKit::main($off), ['url' => 'https://youtu.be/SegneMaria2', 'name' => 'Jenny', 'place' => 'Munich']);
    runJobs($off);
    eq($off->submissions()->byPublicId($sub['id'])['status'], 'approved', 'off: as before, no wait and no standard');
    check(!str_contains((string) json_encode($off->text()->calls), 'Scripture is the measure'), 'off: the standard is not in the rules');
});

test('knowledge: a request approved after its look-up joins the library with its clean names while they are on air', function () {
    $app = knCheckStation();
    $app->knowledge()->saveSettings(['air' => true], 'mod:test');
    $app->research()->respond(fn() => knResearch(['sources' => ['https://www.gerth.de/person/langner-timo.html', 'https://made-up.example/never-searched']]));
    $row = knRequest($app, 'CleanName01');
    eq($row['status'], 'approved', 'approved');
    $item = $app->library()->byYouTube('CleanName01');
    eq([$item['title'], $item['artist']], ['Ein Gott, der das Meer teilt', 'Timo Langner'], "the clean names, not YouTube's split");
});

test('knowledge: on air, the host gets each song\'s message, passage and one fact; a fact told rests from when its words are written, and its page goes with the moment to the stage', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    foreach ($app->store()->all('SELECT yt_id FROM library_items') as $r) $app->knowledge()->ensure((string) $r['yt_id'], 'song');
    runJobs($app);
    $app->knowledge()->saveSettings(['air' => true], 'mod:test');
    $app->text()->respond('host_break', function (string $system, string $user): array {
        $m = momentOf($user);
        $side = isset($m['next']['fact']) ? 'next' : (isset($m['previous']['fact']) ? 'previous' : 'none');
        return ['en' => ['text' => 'A song with a story.'], 'de' => ['text' => 'Ein Lied mit Geschichte.'], 'fact' => $side, 'delivery' => 'Warm.'];
    });
    ticks($app, 30);
    $messages = writerMessages($app, 'host_break');
    $breaks = array_values(array_filter($messages, fn($u) => (momentOf($u)['kind'] ?? '') === 'break'));
    check($breaks !== [], 'breaks were written');
    $m = momentOf($breaks[0]);
    eq([$m['next']['about']['en'] ?? '', $m['next']['bible'] ?? ''], ['The song says that God stays near in every storm.', 'Psalm 23'], 'the next song with its message as heard and its passage');
    eq([$m['previous']['fact']['en'] ?? '', isset($m['next']['fact'])], ['The song was written for a small church choir.', false], 'and one fact: the song just heard');
    check(!isset($m['fact_refs']) && !str_contains($breaks[0], 'example.org'), "which fact was offered, and its page, are not the model's");
    $told = array_values(array_filter(TestKit::committed($app), fn($i) => $i['type'] === 'host' && (hostContext($app, $i)['fact_told'] ?? '') !== ''));
    check($told !== [], 'a committed break told a fact');
    eq($told[0]['payload']['cite'] ?? null, ['title' => 'example.org', 'url' => 'https://example.org/song'], 'its page goes with the moment');
    $ctx = hostContext($app, $told[0]);
    $row = $app->knowledge()->get((string) $ctx['fact_refs'][$ctx['fact_told']]['yt']);
    check((int) ($row['facts_told']['0'] ?? 0) > 0, 'marked told: it rests');
    $files = glob($app->config->publicDir . '/program/*/slots/*/*.json') ?: [];
    check(array_filter($files, fn($f) => str_contains((string) file_get_contents($f), 'https://example.org/song')) !== [], 'the minute file carries the page for the stage');
});

test('knowledge: a moment offers one fact at most, and a channel tells one about every quarter hour — a break the song just heard first, a video\'s introduction the video; no intro, no prayer hour; a test moment always may', function () {
    $app = TestKit::app();
    $k = $app->knowledge();
    foreach (['FactSongA01' => 'song', 'FactSongB01' => 'song', 'FactFilm001' => 'film'] as $yt => $kind) $k->ensure($yt, $kind);
    $app->runner()->runUntilBudget();
    $k->saveSettings(['air' => true], 'mod:test');
    $item = fn(string $yt, string $kind = 'song') => ['type' => 'song', 'library_id' => null, 'payload' => ['yt' => $yt, 'kind' => $kind, 'title' => "Title $yt", 'artist' => 'Artist']];
    $main = TestKit::main($app);
    $t = $app->clock->nowMs();
    $frame = fn(string $kind, int $at, int $hb = 0, ?array $program = null, ?bool $due = null, string $next = 'FactSongB01') => $app->hostWriter()
        ->frame(['id' => $hb, 'kind' => $kind, 'program_id' => null], 'Hope', $program, $main, $at, $item('FactSongA01'), $item($next, $next === 'FactFilm001' ? 'film' : 'song'), $due);
    $offered = fn(array $ctx) => array_keys(array_filter(['previous' => isset($ctx['previous']['fact']), 'next' => isset($ctx['next']['fact'])]));

    $a = $frame('break', $t, 7);
    eq([$offered($a), array_keys($a['fact_refs'] ?? [])], [['previous'], ['previous']], 'a break: one fact, of the song just heard');
    check(isset($a['next']['about']), 'the next song keeps its message');
    $k->told($a['fact_refs']['previous'], $t);
    eq($offered($frame('break', $t + 8 * 60_000)), [], 'the next break, eight minutes on: none');
    eq($offered($frame('break', $t - 8 * 60_000)), [], 'nor one written later for eight minutes before');
    // The song just heard has only the fact it told, which rests now: the next song's comes.
    eq($offered($frame('break', $t, 7)), ['next'], 'the same moment written again keeps its turn');
    eq($offered($frame('break', $t + Knowledge::FACT_GAP_MINUTES * 60_000)), ['next'], 'a quarter hour on: one again');
    eq($offered($frame('break', $t + 8 * 60_000, 0, null, true)), ['next'], 'a test moment always may');
    $later = $t + 60 * 60_000;
    eq($offered($frame('film', $later, 0, null, null, 'FactFilm001')), ['next'], "a video program's own moment: the video's fact");
    eq($offered($frame('break', $later, 0, null, null, 'FactFilm001')), ['next'], 'a break before a video: the video first');
    eq($offered($frame('intro', $later)), [], 'an intro opens its program: no fact');
    $prayer = ['title_en' => 'Prayer', 'title_de' => 'Gebet', 'themes' => [], 'settings' => ['format' => 'prayer']];
    eq($offered($frame('break', $later, 0, $prayer)), [], 'a prayer hour: no fact');
});

test('knowledge: the writer is asked whose fact it told only when a song comes with one — delivery stays last; off air it gets title and artist as before', function () {
    $app = TestKit::app(['AI_MODE' => 'live', 'OPENAI_KEY' => 'sk-test', 'ANTHROPIC_KEY' => '']);
    $http = new FakeHttp();
    $app->set('http', $http);
    $hb = ['id' => 0, 'kind' => 'break', 'channel_id' => (int) TestKit::main($app)['id'], 'program_id' => null, 'context' => []];
    $ctx = ['kind' => 'break', 'host_name' => 'Hope', 'program' => null, 'time_of_day_de' => 'Mittag', 'now' => '12:00', 'previous' => null,
        'next' => ['title' => 'Befiehl du deine Wege', 'artist' => 'Paul Gerhardt', 'fact' => ['en' => 'Paul Gerhardt wrote it in 1653.', 'de' => 'Paul Gerhardt schrieb es 1653.']],
        'next_uid' => '', 'fact_refs' => ['next' => ['yt' => 'Befiehl0001', 'i' => 0]]];
    $http->answers[] = openaiAnswer(['en' => ['text' => 'Paul Gerhardt wrote the next hymn in 1653.'], 'de' => ['text' => 'Paul Gerhardt schrieb das nächste Lied 1653.'], 'fact' => 'next', 'delivery' => 'Warm.']);
    $written = $app->hostWriter()->write($hb, $ctx, null, []);
    $schema = $http->body(0)['response_format']['json_schema']['schema'];
    eq($schema['properties']['fact']['enum'] ?? null, ['none', 'next'], 'asked whose fact');
    eq(array_slice($schema['required'], -2), ['fact', 'delivery'], 'delivery stays last');
    eq($written['fact'], 'next', 'told');
    check(!str_contains((string) $http->sent[0]['body'], 'Befiehl0001'), 'which fact was offered never goes out');
    unset($ctx['next']['fact'], $ctx['fact_refs']);
    $http->answers[] = openaiAnswer(['en' => ['text' => 'Next, a hymn.'], 'de' => ['text' => 'Gleich ein Lied.'], 'delivery' => 'Warm.']);
    $written = $app->hostWriter()->write($hb, $ctx, null, []);
    check(!isset($http->body(1)['response_format']['json_schema']['schema']['properties']['fact']), 'no fact offered: not asked');
    eq($written['fact'], '', 'nothing told');
    $rules = $app->hostWriter()->system(null);
    check(str_contains($rules, 'Say nothing about a song, an artist or a video') && str_contains($rules, 'A "fact" given is there to be told'), 'the rule allows what a song comes with, and nothing beyond — and a fact given is told');
});

test('knowledge: the show memory notes whose fact a moment told when it only summarizes that moment — the song, never the listener', function () {
    $app = TestKit::app();
    $t = TestKit::T0;
    $announce = ['type' => 'host', 'state' => 'committed', 'seq' => 1.0, 'start_ms' => $t, 'est_start' => $t, 'dur_ms' => 12_000, 'program_id' => 1, 'submission_id' => null,
        'payload' => ['kind' => 'announce', 'text' => ['en' => 'Jenny from Munich wishes her mum a hymn.'], 'host' => ['name' => 'Hope']],
        'break' => ['kind' => 'announce', 'state' => 'ready', 'source' => 'openai', 'texts' => [],
            'context' => ['request' => ['name' => 'Jenny', 'place' => 'Munich', 'message' => 'For my mum'], 'fact_told' => 'next', 'next' => ['title' => 'Befiehl du deine Wege', 'artist' => 'Paul Gerhardt']]]];
    $memory = $app->showLog()->memory([], [$announce], $t, $t + 60_000, new DateTimeZone('Europe/Berlin'), 'Hope');
    $entry = $memory['so_far'][0] ?? [];
    eq([$entry['summary'] ?? '', $entry['told_fact_of'] ?? ''], ["presented a listener's song request", 'Befiehl du deine Wege'], 'summarized, with the song whose fact was told');
    $json = (string) json_encode($memory);
    check(!str_contains($json, 'Jenny') && !str_contains($json, 'Munich') && !str_contains($json, 'mum'), 'naming nobody');
});

test('knowledge: /mod — moderators read an item\'s look-up, correct it, take its names and look it up again; only admins set the switches, the budget and the standard; the list filters by concerns', function () {
    $app = TestKit::app();
    $ids = TestKit::songs($app, 2);
    $app->listener()->respond(fn($yt) => $yt === (string) $app->library()->get($ids[1])['yt_id']
        ? ['biblical' => 'no', 'concerns' => [['what' => 'A prayer to Mary', 'why' => 'Not the standard', 'quote' => 'Segne du Maria']]] + StubVideoListener::fixed()
        : StubVideoListener::fixed());
    $app->research()->respond(fn() => knResearch(['sources' => ['https://www.gerth.de/person/langner-timo.html', 'https://made-up.example/never-searched']]));
    foreach ($ids as $id) $app->knowledge()->ensure((string) $app->library()->get($id)['yt_id'], 'song');
    runJobs($app);
    $mod = moderatorHeaders($app);
    [$st, $d] = modGet($app, '/api/mod/library', ['kind' => 'song'], $mod);
    eq($st, 200, 'the list');
    $byId = array_column($d['items'], 'knowledge', 'id');
    eq([$byId[$ids[0]]['state'], $byId[$ids[0]]['concern'], $byId[$ids[1]]['biblical'], $byId[$ids[1]]['concern']], ['ready', false, 'no', true], 'each with its knowledge in short');
    eq($byId[$ids[0]]['names'], ['title' => 'Ein Gott, der das Meer teilt', 'artist' => 'Timo Langner'], "research's names offered");
    [, $d] = modGet($app, '/api/mod/library', ['kind' => 'song', 'knowledge' => 'concerns'], $mod);
    eq(array_column($d['items'], 'id'), [$ids[1]], 'filtered by concerns');

    [$st, $d] = call($app, 'GET', "/api/mod/library/{$ids[0]}/knowledge", [], $mod);
    eq([$st, isset($d['knowledge']['work'])], [200, false], 'the whole record, without what a look-up in progress keeps');
    [$st, $d] = call($app, 'PATCH', "/api/mod/library/{$ids[0]}/knowledge", ['about' => ['en' => 'About trust.', 'de' => 'Über Vertrauen.']], $mod);
    eq([$st, $d['knowledge']['analysis']['message_de']], [200, 'Über Vertrauen.'], 'corrected');
    [$st, $d] = call($app, 'POST', "/api/mod/library/{$ids[0]}/knowledge/names", [], $mod);
    eq([$st, $d['item']['title']], [200, 'Ein Gott, der das Meer teilt'], 'the names taken over');
    [$st, $d] = call($app, 'POST', "/api/mod/library/{$ids[0]}/knowledge/again", [], $mod);
    eq($st, 200, 'looked up again');

    eq(call($app, 'GET', '/api/mod/knowledge', [], $mod)[0], 403, 'the settings: admins only');
    eq(call($app, 'PUT', '/api/mod/knowledge', ['checks' => true], $mod)[0], 403, 'and their saving');
    $admin = modHeaders($app);
    [$st, $d] = call($app, 'GET', '/api/mod/knowledge', [], $admin);
    eq([$st, $d['settings']['checks'], $d['standardDefault']], [200, false, Knowledge::STANDARD], 'admins read them');
    [$st, $d] = call($app, 'PUT', '/api/mod/knowledge', ['checks' => true, 'air' => true, 'budget_usd' => 12, 'standard' => 'Scripture only.'], $admin);
    eq([$st, $d['settings']], [200, ['checks' => true, 'air' => true, 'budget_usd' => 12.0, 'standard' => 'Scripture only.']], 'and set them');
    eq(call($app, 'GET', '/api/mod/library', [], authHeaders())[0], 403, 'listeners see none of it');
});

test('knowledge: a request tries a video\'s failed look-up again once it is ten minutes old — not one that just failed', function () {
    $app = knCheckStation();
    $fails = true;
    $app->listener()->respond(function () use (&$fails) {
        return $fails ? null : StubVideoListener::fixed();
    });
    eq(knRequest($app, 'FlakyVideo1')['status'], 'rejected', 'nothing heard: not accepted');
    $fails = false;
    TestKit::clock($app)->advance(11 * 60_000);
    eq(knRequest($app, 'FlakyVideo1')['status'], 'approved', 'ten minutes on, a new request looks it up again');
});

test('knowledge: one station\'s look-ups go to another as a file — taken where it knows nothing, its own kept unless replaced, every record checked again on the way in; nothing else of the station travels', function () {
    $here = TestKit::app();
    $ids = TestKit::songs($here, 2);
    $here->research()->respond(fn() => knResearch(['sources' => ['https://www.gerth.de/person/langner-timo.html']]));
    foreach ($ids as $id) $here->knowledge()->ensure((string) $here->library()->get($id)['yt_id'], 'song');
    $here->knowledge()->ensure('NotInLib002', 'song');
    runJobs($here);
    $file = $here->knowledge()->export();
    eq([$file['format'], count($file['rows'])], ['arche-knowledge', 2], "the library's, not a turned-down request's video");
    check(!isset($file['rows'][0]['facts_told'], $file['rows'][0]['edited_by'], $file['rows'][0]['work']), 'no rotation, moderator or look-up under way');
    // Tampered with on the way: a fact whose page the search never consulted, and a text that is no public domain.
    $file['rows'][0]['research']['facts'][] = ['en' => 'Made up.', 'de' => 'Erfunden.', 'source' => 'https://made-up.example/x'];
    $file['rows'][0]['text'] = 'All the lyrics';
    $file['rows'][] = ['yt_id' => 'bad id', 'research' => [], 'analysis' => []];

    $live = TestKit::app(['KNOWLEDGE_DAILY_BUDGET_USD' => '0']);
    $live->store()->query('INSERT INTO library_items (kind, yt_id, title, artist, duration_ms, created, updated) SELECT kind, yt_id, title, artist, duration_ms, 1, 1 FROM library_items WHERE 0');
    foreach ($file['rows'] as $r) {
        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $r['yt_id'])) $live->store()->insert('library_items', ['kind' => 'song', 'yt_id' => $r['yt_id'], 'title' => $r['yt_title'], 'artist' => $r['yt_artist'], 'duration_ms' => 200_000, 'created' => 1, 'updated' => 1]);
    }
    $hostsBefore = $live->store()->all('SELECT * FROM hosts');
    eq($live->knowledge()->import($file, false, 'mod:test'), ['taken' => 2, 'kept' => 0, 'refused' => 1], 'taken, a broken row refused');
    $row = $live->knowledge()->get($file['rows'][0]['yt_id']);
    eq([$row['state'], $row['text'], $row['facts_told']], ['ready', '', []], 'checked again: the text gone, the rotation starts afresh');
    eq(count($row['research']['facts']), count($file['rows'][0]['research']['facts']) - 1, 'the fact its search never consulted gone');
    check(!in_array('Made up.', array_column($row['research']['facts'], 'en'), true), 'not that one');
    eq($live->store()->all('SELECT * FROM hosts'), $hostsBefore, "the station's hosts untouched");
    eq((int) $live->store()->value("SELECT COUNT(*) FROM jobs WHERE type = 'knowledge'"), 0, 'nothing looked up here: no budget yet');
    $live->store()->update('video_knowledge', ['analysis' => json_encode(['heard' => true, 'message_en' => 'Mine.'] + StubVideoListener::fixed())], 'yt_id = ?', [$row['yt_id']]);
    eq($live->knowledge()->import($file, false, 'mod:test')['kept'], 2, 'what it knows is kept');
    eq($live->knowledge()->import($file, true, 'mod:test')['taken'], 2, '…unless replaced');
    check(refuses(fn() => $live->knowledge()->import(['format' => 'arche-setup'], false, 'mod:test'), 'not_knowledge_file'), 'another file is refused');
    check(refuses(fn() => $live->knowledge()->saveSettings(['checks' => true], 'mod:test'), 'knowledge_no_budget'), 'no checks without a budget to look anything up');
});

test('knowledge: /mod — only admins download and upload look-ups', function () {
    $app = TestKit::app();
    TestKit::songs($app, 1);
    $app->knowledge()->ensure((string) $app->store()->value('SELECT yt_id FROM library_items'), 'song');
    runJobs($app);
    $mod = moderatorHeaders($app);
    eq(call($app, 'GET', '/api/mod/knowledge/export', [], $mod)[0], 403, 'not moderators');
    [$st, $d] = call($app, 'GET', '/api/mod/knowledge/export', [], modHeaders($app));
    eq([$st, $d['format'], count($d['rows'])], [200, 'arche-knowledge', 1], 'admins download');
});

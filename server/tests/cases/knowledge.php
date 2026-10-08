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
    eq($k->told('HostFacts01', $first['ref']['i'], $app->clock->nowMs()), ['title' => 'example.org', 'url' => 'https://example.org/one'], 'told: its source for the stage');
    eq($k->forHost($k->get('HostFacts01'))['fact']['en'] ?? '', 'Fact two.', 'the next airing: the other fact');
    $k->told('HostFacts01', 1, $app->clock->nowMs());
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

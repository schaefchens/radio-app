<?php
declare(strict_types=1);

use Arche\Host\Speech;
use Arche\Host\Templates;

/**
 * What a voice is given to speak (Host\Speech). YouTube's titles and the
 * symbols that Qwen read out, or paused at in the wrong place, are made
 * speakable. People's own words stay as written. The writer gets titles as
 * a host would say them. The cases are what aired on 2026-10-07.
 */

test('speech: titles, symbols and capitals a voice cannot say are made speakable', function () {
    foreach ([
        ['en', "You've been listening to Tuvo Shel Elohim @SOLUIsrael by Goodness of God in HEBREW.", "You've been listening to Tuvo Shel Elohim by Goodness of God in Hebrew."],
        ['en', 'Next: Oceans | Hillsong UNITED & friends 🎶', 'Next: Oceans, Hillsong United and friends.'],
        ['en', 'Welcome to ARCHE Live — stay with us!!', 'Welcome to Arche Live, stay with us!'],
        ['en', 'Graves Into Gardens ft. Brandon Lake, e.g. on #SundayWorship', 'Graves Into Gardens featuring Brandon Lake, for example on Sunday Worship.'],
        ['de', 'Gleich: In Christ Alone - Nur-Gnade-Männerensemble — schön', 'Gleich: In Christ Alone, Nur-Gnade-Männerensemble, schön.'],
        ['de', 'WOHL DEM, DER NICHT WANDELT.... von Benjamin', 'Wohl Dem, Der Nicht Wandelt, von Benjamin.'],
        ['de', 'Das Arrangement Deutsch / Aramäisch (Jo Hepp), z. B. rund um die Uhr, 24/7', 'Das Arrangement Deutsch und Aramäisch, Jo Hepp, zum Beispiel rund um die Uhr, 24/7.'],
        ['de', 'Joshua Aaron & Chief Riverwind 🎶 EVERY TRIBE ✈️ Ein Gedi כל שבט', 'Joshua Aaron und Chief Riverwind Every Tribe Ein Gedi.'],
        ['de', 'Hört gut zu: „Befiehl du deine Wege“ von ERF?!', 'Hört gut zu: Befiehl du deine Wege von ERF?'],
    ] as [$lang, $in, $out]) eq(Speech::forVoice($in, $lang), $out, "spoken: $in");
    eq(Speech::forVoice('כל שבט', 'de'), 'כל שבט', 'nothing left: the words as they came, rather than silence');
});

test('speech: people\'s own words keep every word and every alphabet; only their typography changes', function () {
    eq(Speech::forVoice('Bitte betet für Мария 🙏🙏 – sie hat Krebs...!!', 'de', true), 'Bitte betet für Мария, sie hat Krebs!', 'a prayer request');
    eq(Speech::forVoice('Pray for my MOM & DAD #healing (please)', 'en', true), 'Pray for my MOM and DAD healing, please.', 'capitals and words stay theirs');
});

test('speech: the writer is given titles as a host would say them', function () {
    foreach ([
        'What a Friend We Have in Jesus|AcousticWorship#ChristianMusic' => 'What a Friend We Have in Jesus',
        'Tuvo Shel Elohim @SOLUIsrael' => 'Tuvo Shel Elohim',
        'Oceans (Where Feet May Fail) [Official Lyric Video]' => 'Oceans (Where Feet May Fail)',
        'Amazing Grace (Live)' => 'Amazing Grace',
        'WOHL DEM, DER NICHT WANDELT....' => 'WOHL DEM, DER NICHT WANDELT',
        'Joshua Aaron & Chief Riverwind 🎶 EVERY TRIBE ✈️ Ein Gedi, Israel כל שבט' => 'Joshua Aaron & Chief Riverwind EVERY TRIBE Ein Gedi, Israel',
        'Gnade ist stark (His Mercy is More)' => 'Gnade ist stark (His Mercy is More)',
    ] as $raw => $said) eq(Speech::title($raw), $said, "title: $raw");
});

test('speech: every template and lead-in keeps its words on the way to the voice', function () {
    $words = fn(string $s): array => array_values(array_filter(preg_split('/[^\p{L}\p{N}’\']+/u', mb_strtolower($s)) ?: [], fn($w) => $w !== ''));
    $program = ['title' => ['en' => 'Morning', 'de' => 'Morgen']];
    $next = ['title' => 'Oceans', 'artist' => 'Hillsong UNITED'];
    $texts = [];
    foreach (['intro', 'break', 'announce', 'contrib', 'outro', 'preaching', 'testimony', 'mission', 'film', 'prayer', 'present', 'prayertime', 'encourage'] as $kind) {
        $texts[] = Templates::texts($kind, ['program' => $program, 'next' => $next, 'requests' => 2]);
        $texts[] = Templates::texts($kind, ['program' => $program, 'format' => 'prayer hour', 'after' => ['en' => 'Night', 'de' => 'Nacht']]);
    }
    foreach ($texts as $t) {
        foreach ($t as $lang => $text) eq($words(Speech::forVoice($text, $lang)), $words($text), "template: $text");
    }
    foreach (['prayer', 'prayer_anon', 'request', 'request_anon', 'opendoors', 'opendoors_anywhere'] as $case) {
        foreach (['en', 'de'] as $lang) {
            for ($n = 0; $n < 6; $n++) {
                $lead = Templates::leadIn($case, $lang, $n, 'Tom ' . ($lang === 'de' ? 'aus' : 'from') . ' Berlin', 'Nigeria');
                eq($words(Speech::forVoice($lead, $lang, true)), $words($lead), "lead-in: $lead");
            }
        }
    }
});

test('speech: the voice is given the speakable text, and the stage keeps the words as written', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    workerHope($app);
    $key = newWorker($app);
    workerPoll($app, $key);
    $written = ['en' => 'Next: Oceans | Hillsong UNITED & friends 🎶', 'de' => 'Gleich: Oceans – Hillsong UNITED & Freunde 🎶'];
    $app->text()->respond('host_break', fn() => ['en' => ['text' => $written['en']], 'de' => ['text' => $written['de']]]);
    $id = breakNow($app);
    runJobs($app, 2);
    eq(array_column($app->workers()->tasksFor('break', $id), 'text'), ['Next: Oceans, Hillsong United and friends.', 'Gleich: Oceans, Hillsong United und Freunde.'], 'what the voice can say');
    eq($app->hostBreaks()->get($id)['texts'], $written, 'the words as written, for the stage');
});

test('speech: the writer is told how its host\'s voice reads, after who the host is', function () {
    $app = TestKit::app();
    $w = $app->hostWriter();
    $qwen = $w->system(['name' => 'Faith', 'provider' => 'worker', 'model' => 'qwen3-tts-1.7b-customvoice']);
    check(str_contains($qwen, 'Qwen3-TTS') && str_contains($qwen, 'No dash between phrases'), 'a Qwen host: plain sentences, no symbols');
    check(strpos($qwen, 'Who you are: Faith.') < strpos($qwen, 'How you are heard'), 'after the persona: the rules stay one cached prefix');
    check(str_contains($w->system(['name' => 'Hope', 'provider' => 'openai', 'model' => 'tts-1']), 'words and punctuation alone carry the feeling'), 'a voice without direction');
    check(str_contains($w->system(['name' => 'Hope', 'provider' => 'openai', 'model' => 'gpt-4o-mini-tts']), 'an OpenAI voice reads'), 'an OpenAI voice');
});

test('speech: each moment tells the voice how it should sound, on top of the host\'s own direction', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    workerHope($app);
    $key = newWorker($app);
    workerPoll($app, $key);
    $app->text()->respond('host_break', fn() => ['en' => ['text' => 'What a joy.'], 'de' => ['text' => 'Was für eine Freude.'], 'delivery' => '  Glad and "bright", with a smile; stress the title. ']);
    $id = breakNow($app);
    runJobs($app, 2);
    eq($app->hostBreaks()->get($id)['context']['delivery'] ?? null, 'Glad and bright, with a smile; stress the title.', 'kept with the moment, cleaned');
    eq(array_column(array_column($app->workers()->tasksFor('break', $id), 'request'), 'instruct'),
        array_fill(0, 2, 'Warm and calm, like a Christian radio host. Glad and bright, with a smile; stress the title.'), 'Qwen is told both, the host\'s own direction first');
    $app->text()->respond('host_outro', fn() => ['en' => ['text' => 'Goodbye.'], 'de' => ['text' => 'Tschüss.']]);
    $out = breakNow($app, 'outro');
    runJobs($app, 2);
    eq(array_column(array_column($app->workers()->tasksFor('break', $out), 'request'), 'instruct'), array_fill(0, 2, 'Warm and calm, like a Christian radio host.'), 'an answer without one leaves the host\'s direction alone');
    eq(Speech::direction('Warm and calm', ''), 'Warm and calm', 'nothing added without a delivery');
});

test('speech: a delivery is one clean line, capped, and never names a listener or prays', function () {
    $long = Speech::delivery(str_repeat('Warm and glad, ', 30));
    check(mb_strlen($long) <= 201 && str_ends_with($long, '.'), 'capped at a word: ' . $long);
    eq(Speech::delivery("Tender\nand [softly] calm"), 'Tender and softly calm', 'one line, no brackets');
    eq(Speech::delivery('Warm, thank Anna warmly', ['previous_request' => ['name' => 'Anna', 'place' => 'Köln']]), '', 'a listener\'s name drops it');
    eq(Speech::delivery('As if from Köln', ['request' => ['name' => 'Anna', 'place' => 'Köln']]), '', 'so does a place');
    eq(Speech::delivery(['not', 'a string']), '', 'only text');
    $app = TestKit::app(['STATION_LANGS' => 'en,de']);
    $app->text()->respond('host_break', fn() => ['en' => ['text' => 'Hello.'], 'de' => ['text' => 'Hallo.'], 'delivery' => 'Solemn, then say Amen.']);
    eq($app->hostWriter()->write(['id' => 0, 'kind' => 'break', 'channel_id' => (int) TestKit::main($app)['id'], 'program_id' => null, 'context' => []], ['kind' => 'break'])['delivery'], '', 'a delivery that prays is dropped');
});

test('speech: words the model does not write are told how to sound by the station', function () {
    $app = TestKit::app();
    $main = (int) TestKit::main($app)['id'];
    $opening = $app->hostWriter()->write(['id' => 0, 'kind' => 'opening', 'channel_id' => $main, 'program_id' => null, 'context' => ['fixed' => ['en' => 'Gracious God, we come to you.']]], []);
    eq([$opening['source'], $opening['delivery']], ['moderator', Speech::fixedDelivery('opening')], 'a moderator\'s opening prayer: calm and reverent');
    check(str_contains(Speech::fixedDelivery('reading'), 'compassionate') && str_contains(Speech::fixedDelivery('intercession'), 'heartfelt'), 'people\'s requests and prayers read gently');
    eq(Speech::fixedDelivery('break'), '', 'the model\'s moments bring their own');
    eq(Speech::moodDelivery('joyful'), 'Glad and bright, with a smile in the voice; lively but not rushed.', 'a recorded line by its mood');
    eq(Speech::moodDelivery(''), '', 'a line without a mood: the host\'s own direction');
});

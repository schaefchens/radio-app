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

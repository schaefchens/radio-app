<?php
declare(strict_types=1);

use Arche\Host\HostWriter;
use Arche\Host\Templates;

/**
 * The host never prays — listeners do, and it invites them to: what the
 * model is told, what replaces an answer that prays anyway, and every text
 * that airs without the model.
 */

test('host: the model is told never to pray', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $system = '';
    $app->text()->respond('host_break', function (string $s) use (&$system) {
        $system = $s;
        return ['en' => ['text' => 'You are listening to ARCHE.'], 'de' => ['text' => 'Ihr hört ARCHE.']];
    });
    ticks($app, 30);
    check(str_contains($system, 'You never pray.') && str_contains($system, '"Amen"'), 'the rule is in the prompt');
    check(!preg_match('/\bpray (?:briefly|for the listed)|opening prayer, speaking to God|close with a short blessing/', $system), 'and no moment asks for a prayer or a blessing');
});

test('host: the guard catches a prayer in the host\'s words and leaves invitations to pray alone', function () {
    foreach ([
        'Lord, hear our prayers.', 'Herr, erhöre uns.', 'We are grateful. Father, thank you.', 'Let us pray for Maria.', "Let's all pray together.",
        'Lasst uns für Maria beten.', 'Lass uns beten.', 'God bless you all.', 'Gott segne dich.', 'Wir wünschen dir Gottes Segen.',
        "We ask this in Jesus' name.", 'Darum bitten wir in Jesu Namen.', 'Amen.', 'Vater, wir danken dir.',
    ] as $t) check(HostWriter::prays($t), "caught: $t");
    foreach ([
        'Bete mit für Maria.', 'In dieser Stunde betet ihr füreinander.', 'Schickt eure Gebete über die App.', 'Please pray for Maria.',
        'Pray along on the prayer wall.', 'Herr Müller, schön dass du da bist.', 'Thank you for every request and every prayer.', 'Einen schönen Abend!',
        'Take a moment to pray for this request.', 'A prayer request from Open Doors for persecuted Christians in Nigeria:',
    ] as $t) check(!HostWriter::prays($t), "left alone: $t");
});

test('host: no text that airs without the model prays', function () {
    $program = ['title' => ['en' => 'Morning', 'de' => 'Morgen']];
    $hour = ['format' => 'prayer hour', 'program' => ['title' => ['en' => 'Prayer Hour', 'de' => 'Gebetsstunde']], 'time_of_day_de' => 'Abend', 'after' => ['en' => 'Night', 'de' => 'Nacht']];
    $requests = [['on_wall' => false, 'name' => 'Ana', 'place' => 'Porto', 'text' => 'x'], ['on_wall' => true, 'text' => 'y']];
    $cases = [];
    foreach (['intro', 'break', 'announce', 'contrib', 'outro', 'preaching', 'testimony', 'mission', 'film', 'invite', 'opening'] as $kind) {
        $cases["$kind"] = Templates::texts($kind, ['program' => $program, 'next' => ['title' => 'Song', 'artist' => 'Band']]);
        $cases["$kind, prayer hour"] = Templates::texts($kind, $hour + ['opening_by' => 'Maria']);
    }
    // A video program's videos: introduced by an intro, a break or their own
    // moment, or announced as a listener's suggestion — named or anonymous.
    foreach (['preaching', 'testimony', 'mission', 'film'] as $video) {
        $next = ['kind' => $video, 'title' => 'Hope', 'by' => 'Grace Chapel'];
        foreach (['intro', 'break', $video] as $kind) $cases["$kind before a $video"] = Templates::texts($kind, ['program' => $program, 'next' => $next]);
        foreach (['Ana', ''] as $name) {
            $cases["a $video suggested by '$name'"] = Templates::texts('announce',
                ['program' => $program, 'next' => $next, 'request' => ['type' => $video, 'name' => $name, 'place' => 'Porto', 'message' => '']]);
        }
    }
    foreach ([1, 3] as $n) $cases["prayer, $n requests read"] = Templates::texts('prayer', ['program' => $program, 'requests' => $n]);
    // After an item of a group whose links the stage shows: the sentence pointing to more from them.
    foreach (['break', 'outro', 'preaching'] as $kind) {
        $cases["$kind after a group's item"] = Templates::texts($kind, ['program' => $program, 'next' => ['title' => 'Song', 'artist' => 'Band'],
            'previous_group' => ['name' => 'Grace Chapel', 'about' => ['en' => 'A church.', 'de' => 'Eine Gemeinde.']]]);
    }
    foreach (['open', 'read', 'new', 'again', 'general'] as $phase) {
        $cases["prayer $phase"] = Templates::texts('prayer', $hour + ['phase' => $phase, 'prayers' => $phase === 'general' ? [] : $requests]);
    }
    foreach ($cases as $what => $texts) {
        foreach (['en', 'de'] as $l) check(!HostWriter::prays((string) ($texts[$l] ?? '')), "$what ($l): " . ($texts[$l] ?? ''));
    }
    foreach (['prayer', 'prayer_anon', 'request', 'request_anon', 'opendoors', 'opendoors_anywhere'] as $case) {
        for ($n = 0; $n < 6; $n++) {
            foreach (['en', 'de'] as $l) check(!HostWriter::prays(Templates::leadIn($case, $l, $n, 'Tom from Berlin', 'Nigeria')), "lead-in $case $n ($l)");
        }
    }
});

test('host: lead-ins change from one reading to the next, and one for a sender without a name names nobody', function () {
    foreach (['prayer', 'prayer_anon', 'request', 'request_anon', 'opendoors'] as $case) {
        foreach (['en', 'de'] as $l) {
            for ($n = 0; $n < 12; $n++) {
                check(Templates::leadIn($case, $l, $n, 'Tom', 'Nigeria') !== Templates::leadIn($case, $l, $n + 1, 'Tom', 'Nigeria'), "$case $l: $n and the next differ");
            }
        }
    }
    for ($n = 0; $n < 10; $n++) {
        foreach (['en', 'de'] as $l) {
            foreach (['prayer_anon', 'request_anon'] as $case) check(!str_contains(Templates::leadIn($case, $l, $n, 'Tom from Berlin'), 'Tom'), "the anonymous lead-in $case $n ($l) has no name");
        }
    }
    eq([Templates::who('Tom', 'Berlin', 'de'), Templates::who('Tom', '', 'en'), Templates::who('', 'Berlin', 'en')], ['Tom aus Berlin', 'Tom', ''], 'first name and place as the host says them');
});

test('host: a request or recording whose sender stayed anonymous is announced without a name — and a place alone names nobody', function () {
    foreach (['announce' => 'request', 'contrib' => 'contribution'] as $kind => $key) {
        foreach ([['name' => '', 'place' => ''], ['name' => '', 'place' => 'Berlin']] as $who) {
            $texts = Templates::texts($kind, [$key => $who]);
            foreach (['en', 'de'] as $l) check(!str_contains($texts[$l], 'Berlin') && !str_contains($texts[$l], '()'), "$kind ($l): {$texts[$l]}");
        }
        check(str_contains(Templates::texts($kind, [$key => ['name' => 'Jonas', 'place' => 'Hamburg']])['en'], 'Jonas (Hamburg)'), "$kind names who gave a name");
    }
});

test('host: a reading is voiced once, in its own language, and the daily cap neither stops nor counts it', function () {
    $app = TestKit::app(['HOST_MAX_BREAKS_PER_DAY' => '1']);
    $ch = TestKit::main($app);
    $program = $app->catalog()->program((int) $ch['fallback_program_id']) ?? [];
    $sub = prayerRow($app, $ch, ['text' => 'Bitte betet für meine Schwester.', 'name' => 'Lea', 'place' => 'Kiel', 'consent_air' => 0, 'lang' => 'de']);
    $id = (int) $app->submissions()->byPublicId($sub)['id'];
    $app->store()->update('submissions', ['verdict' => json_encode(['languages' => ['de']])], 'id = ?', [$id]);
    // The day's one break of the host's own words is used up.
    $app->store()->insert('host_breaks', ['channel_id' => (int) $ch['id'], 'kind' => 'break', 'state' => 'ready', 'source' => 'stub', 'created' => $app->clock->now(), 'updated' => $app->clock->now()]);
    $item = ['est_start' => $app->clock->nowMs(), 'unit' => null];
    $reading = $app->hostBreaks()->create($ch, $program, 'reading', $item, ['prayer_ids' => [$id], 'n' => 0]);
    $app->runner()->runUntilBudget(1);
    eq($app->store()->value("SELECT phase FROM jobs WHERE type = 'host' AND ref_id = ?", [$reading]), 'tts:de', 'from the script straight to the German voice');
    $app->runner()->runUntilBudget(1);
    $hb = $app->hostBreaks()->get($reading) ?? [];
    eq([$hb['state'], array_keys($hb['audio']), $hb['source']], ['ready', ['de'], 'listener'], 'voiced once, in German, as the listener wrote it');
    eq($hb['texts']['de'], 'Lea aus Kiel bittet um Gebet: Bitte betet für meine Schwester.', 'a lead-in, then the request word for word');
    eq($app->hostBreaks()->airDuration($hb), max(2000, (int) $hb['durations']['de'] + 4_000), 'with a few seconds of quiet after it');

    $break = $app->hostBreaks()->create($ch, $program, 'break', $item);
    runJobs($app);
    eq([$app->hostBreaks()->get($break)['state'], $app->hostBreaks()->get($break)['source']], ['failed', 'skipped:daily_cap'], 'the host\'s own words wait for tomorrow');
});

test('host: an opening prayer in no language the station speaks fails, rather than letting the AI pray', function () {
    $app = TestKit::app(['STATION_LANGS' => 'en']);
    TestKit::songs($app, 12);
    $p = prayerHour($app, 735);
    $app->openingPrayers()->addText((int) $p['id'], 'Anna', '', 'Herr, sei bei uns. Amen.', 'test');
    ticks($app, 25);
    $opening = $app->store()->one("SELECT * FROM host_breaks WHERE kind = 'opening'");
    eq([$opening['state'] ?? null, $opening['source'] ?? null], ['failed', 'skipped:no_text'], 'nothing to say in English');
    $text = $app->text();
    check($text instanceof Arche\Ai\StubText && !in_array('host_opening', array_column($text->calls, 'kind'), true), 'and no model was asked');
});

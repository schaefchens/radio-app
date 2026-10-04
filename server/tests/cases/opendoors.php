<?php
declare(strict_types=1);

use Arche\Program\OpenDoors;
use Arche\Support\HttpResponse;

/**
 * Open Doors' daily prayer request: fetched and translated by a job, read
 * out first in every prayer hour of its day — German as published, English
 * translated — and on the wall with its source.
 */

/** The feed as Open Doors Deutschland publishes it: newest first, a date and a country in the title. */
function openDoorsFeed(string ...$items): string
{
    return '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:dc="http://purl.org/dc/elements/1.1/"><channel>'
        . '<title>Open Doors tägliches Gebetsanliegen für verfolgte Christen</title><link>https://www.opendoors.de/</link><language>de</language>'
        . implode('', $items) . '</channel></rss>';
}

function openDoorsItem(string $date, string $country, string $text, string $guid, string $pubDate): string
{
    return "<item><title>$date $country</title><link>https://www.opendoors.de/</link><description>" . htmlspecialchars($text, ENT_XML1)
        . "</description><pubDate>$pubDate</pubDate><dc:creator>Open Doors Deutschland</dc:creator><guid isPermaLink=\"false\">$guid</guid></item>";
}

const OD_TEXT = 'Bewaffnete Kämpfer töteten die Frau von Pastor Josiah. Durch Seelsorge konnte er vergeben und hilft nun anderen Christen. Beten wir, dass Jesus sie stärkt.';

test('open doors: the newest request of the feed, its country from the title, as plain text — someone else\'s XML, read without network or entities', function () {
    $feed = openDoorsFeed(
        openDoorsItem('22.09.2026', 'Nigeria', 'Ältere Bitte.', '24360', 'Tue, 22 Sep 2026 00:00 +0200'),
        openDoorsItem('23.09.2026', 'Nordkorea', OD_TEXT . ' &amp;', '24361', 'Wed, 23 Sep 2026 00:00 +0200'),
    );
    $item = OpenDoors::newest($feed) ?? [];
    eq([$item['guid'] ?? null, $item['country_de'] ?? null], ['24361', 'Nordkorea'], 'the newest, whatever its place in the feed');
    eq($item['published'] ?? null, strtotime('2026-09-23 00:00 +0200') * 1000, 'published that day');
    eq($item['de'] ?? null, OD_TEXT . ' &', 'plain text');
    $long = OpenDoors::newest(openDoorsFeed(openDoorsItem('23.09.2026', 'Irak', str_repeat('Ein langer Satz über verfolgte Christen. ', 40), '1', 'Wed, 23 Sep 2026 00:00 +0200')));
    check(mb_strlen((string) $long['de']) <= 1200 && str_ends_with((string) $long['de'], '.'), 'a long one is cut at a sentence');
    eq(OpenDoors::newest('<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file:///etc/passwd">]><rss><channel><item><title>&x;</title><description>&x;</description><pubDate>Wed, 23 Sep 2026 00:00 +0200</pubDate></item></channel></rss>'),
        null, 'an external entity is never expanded');
    eq(OpenDoors::newest('not xml'), null, 'nothing from something that is not a feed');
});

test('open doors: a job fetches the request and translates it once; a feed that is down keeps the last one; an old one is not read', function () {
    $app = TestKit::app(['OPENDOORS_FEED_URL' => 'https://feed.test/gebet']);
    $http = new FakeHttp();
    $app->set('http', $http);
    $calls = 0;
    $app->text()->respond('translate_opendoors', function (string $system, string $user) use (&$calls) {
        $calls++;
        check(str_contains($user, 'Pastor Josiah'), 'the request is given to translate');
        return ['country' => 'North Korea', 'text' => 'Armed fighters killed the wife of Pastor Josiah. Let us pray that Jesus strengthens them.'];
    });
    $feed = openDoorsFeed(openDoorsItem('23.09.2026', 'Nordkorea', OD_TEXT, '24361', 'Wed, 23 Sep 2026 00:00 +0200'));
    $http->answers[] = new HttpResponse(200, $feed);
    $app->openDoors()->queue();
    runJobs($app);
    eq([$http->sent[0]['method'] ?? null, $http->sent[0]['url'] ?? null], ['GET', 'https://feed.test/gebet'], 'fetched from the feed');
    $item = $app->openDoors()->current() ?? [];
    eq([$item['de'] ?? null, $item['country_de'] ?? null, $item['country_en'] ?? null], [OD_TEXT, 'Nordkorea', 'North Korea'], 'kept, with the country in both languages');
    check(str_starts_with((string) ($item['en'] ?? ''), 'Armed fighters'), 'and an English version');
    $http->answers[] = new HttpResponse(200, $feed);
    $app->openDoors()->queue();
    runJobs($app);
    eq($calls, 1, 'the same request is translated once');
    for ($i = 0; $i < 4; $i++) $http->answers[] = new HttpResponse(503, '');
    $app->openDoors()->queue();
    runJobs($app, 10);
    eq(($app->openDoors()->current() ?? [])['guid'] ?? null, '24361', 'the feed down: the last request stays');
    TestKit::clock($app)->advance(49 * 3_600_000);
    eq($app->openDoors()->current(), null, 'two days later it is no longer the day\'s request');

    $off = TestKit::app();
    $off->set('http', $none = new FakeHttp());
    $off->openDoors()->queue();
    runJobs($off);
    eq([$none->sent, $off->openDoors()->current()], [[], null], 'turned off (as in tests and e2e): no call at all');
});

test('open doors: read first in every prayer hour of its day — German as published, English translated — and on the wall with its source', function () {
    $app = TestKit::app(['OPENDOORS_FEED_URL' => 'https://feed.test/gebet']);
    $app->set('http', new FakeHttp());
    TestKit::songs($app, 12);
    $store = $app->store();
    $store->set('opendoors', ['guid' => '24361', 'published' => TestKit::T0 - 10 * 3_600_000, 'country_de' => 'Nordkorea', 'de' => OD_TEXT,
        'country_en' => 'North Korea', 'en' => 'Armed fighters killed the wife of Pastor Josiah. Let us pray that Jesus strengthens them.']);
    // Two prayer hours today: 12:15–13:00 and 13:30–14:15 (Berlin).
    $cat = $app->catalog();
    $cid = (int) TestKit::main($app)['id'];
    $bed = $app->library()->addBed(silentMp3(300), 'Pad', 'test');
    $p = $cat->saveProgram(null, $cid, ['slug' => 'prayer', 'title_en' => 'Prayer Hour', 'title_de' => 'Gebetsstunde',
        'settings' => ['format' => 'prayer', 'prayer' => ['collect' => ['songs' => 0, 'minutes' => 10, 'bed_id' => (int) $bed['id']]]]], 'test');
    eq($cat->program((int) $p['id'])['settings']['prayer']['opendoors'] ?? null, true, 'on by default');
    $plan = $cat->saveDayPlan(null, $cid, 'Prayer', [['start_min' => 735, 'end_min' => 780, 'program_id' => $p['id']], ['start_min' => 810, 'end_min' => 855, 'program_id' => $p['id']]], 'test');
    $cat->addSpecialDay($cid, ['name' => 'Prayer', 'kind' => 'date', 'month' => 9, 'day' => 23, 'day_plan_id' => $plan], 'test');
    $mine = '';
    for ($m = 0; $m < 40; $m++) {
        if ($m === 16) $mine = prayFor($app, 'Mine');
        $app->tick()->run('test');
        TestKit::clock($app)->advance(60_000);
    }
    $run = runOf($app, (int) $p['id']);
    $readings = array_values(array_filter($run, fn($r) => str_starts_with($r['label'], 'reading')));
    $first = $readings[0]['item'];
    $de = (string) $first['payload']['text']['de'];
    $en = (string) $first['payload']['text']['en'];
    check(str_contains($de, 'Open Doors') && str_contains($de, 'Nordkorea') && str_ends_with($de, OD_TEXT), "first Open Doors' request, in German as published: $de");
    check(str_contains($en, 'North Korea') && str_ends_with($en, 'Let us pray that Jesus strengthens them.'), "and translated for English listeners: $en");
    check(str_ends_with((string) $readings[1]['item']['payload']['text']['en'], "Please pray for Mine's family."), 'then the listeners\' requests');
    $present = array_values(array_filter($run, fn($r) => $r['label'] === 'present'))[0];
    eq([$present['context']['requests'] ?? null, $present['context']['opendoors'] ?? null], [1, true], 'the host knows: one from a listener, and Open Doors\'');
    $entry = array_values(array_filter($app->submissions()->wall('main'), fn($e) => ($e['source'] ?? '') !== ''))[0] ?? [];
    eq([$entry['source'] ?? null, $entry['texts']['en'] ?? null], ['Open Doors · Nordkorea', 'Armed fighters killed the wife of Pastor Josiah. Let us pray that Jesus strengthens them.'],
        'on the wall with its source, and its translation');
    eq($app->submissions()->collected('main'), 0, 'everything collected has been read; Open Doors\' never counted');

    ticks($app, 70);
    $station = (int) $store->value("SELECT id FROM identities WHERE role = 'station'");
    eq((int) $store->value('SELECT COUNT(*) FROM submissions WHERE identity_id = ?', [$station]), 2, 'the same request in both hours of the day, once each');
    $outro = array_values(array_filter(runOf($app, (int) $p['id']), fn($r) => $r['label'] === 'outro'))[0];
    eq($outro['context']['requests'] ?? null, 1, 'the outro thanks the listeners for theirs');
    [, $users] = modGet($app, '/api/mod/users', [], modHeaders($app));
    check(!in_array('station', array_column($users['users'] ?? [], 'id'), true) && !in_array('station', array_column($users['users'] ?? [], 'publicId'), true),
        'the station\'s identity is listed nowhere in /mod');

    // A program that leaves it out.
    $cat->saveProgram((int) $p['id'], $cid, ['settings' => ['format' => 'prayer', 'prayer' => ['opendoors' => false]]], 'test');
    eq($cat->program((int) $p['id'])['settings']['prayer']['opendoors'], false, 'switched off for this program');
});

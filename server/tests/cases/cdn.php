<?php
declare(strict_types=1);

use Arche\Program\Timing;
use Arche\Support\HttpResponse;

const CDN_ENV = ['CDN_BASE_URL' => 'https://cdn.example.net/', 'BUNNY_API_KEY' => 'bunny-key', 'BUNNY_PULL_ZONE_ID' => '42'];

/** One page of Bunny's v2 log API. @param list<string> $paths */
function bunnyLog(array $paths, bool $hasMore = false): HttpResponse
{
    $rows = array_map(fn(string $p) => ['statusCode' => 200, 'cacheStatus' => 'HIT', 'path' => $p, 'countryCode' => 'DE'], $paths);
    return new HttpResponse(200, (string) json_encode(['data' => $rows, 'pagination' => ['offset' => 0, 'limit' => 10000, 'returned' => count($rows), 'hasMore' => $hasMore]]));
}

test('cdn: the session tells the app where program files come from; "off" keeps a local stack on its origin', function () {
    eq(TestKit::app(CDN_ENV)->config->cdnBase(), 'https://cdn.example.net', 'base without the trailing slash');
    eq(TestKit::app(['CDN_BASE_URL' => 'off'] + CDN_ENV)->config->cdnBase(), '', 'off');
    eq(TestKit::app()->config->cdnBase(), '', 'none by default');
    [, $d] = call(TestKit::app(CDN_ENV), 'POST', '/api/session', [], authHeaders());
    eq($d['config']['cdn'] ?? null, 'https://cdn.example.net', 'in the session config');
    [, $d] = call(TestKit::app(), 'POST', '/api/session', [], authHeaders());
    eq($d['config']['cdn'] ?? null, '', 'empty without one: the app stays on its origin');
});

test('cdn: listeners are the requests for one minute file in the CDN log, and win when they are more', function () {
    $app = TestKit::app(CDN_ENV);
    $http = new FakeHttp();
    $app->set('http', $http);
    $minute = Timing::floorMinute(TestKit::T0) - 2 * Timing::MINUTE;
    $path = '/' . Timing::slotPath('main', $minute);
    $http->answers[] = bunnyLog([$path, $path, $path, $path . '.bak']);
    $out = $app->cdn()->maintain();
    eq($out['listeners']['main'] ?? null, 3, 'exactly that file, three requests');
    $url = (string) $http->sent[0]['url'];
    check(str_starts_with($url, 'https://logging.bunnycdn.com/v2/pullzones/42/logs?'), "the zone's v2 log");
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    eq([$q['urlContains'], $q['status'], $q['from'], $q['to']], [$path, '2xx', gmdate('Y-m-d\TH:i:s\Z', intdiv($minute, 1000)), gmdate('Y-m-d\TH:i:s\Z', intdiv($minute, 1000) + 60)], 'one minute, successful requests only');
    eq($http->sent[0]['headers']['AccessKey'] ?? '', 'bunny-key', 'the account key as AccessKey');
    eq($app->presence()->listeners('main'), 3, 'the CDN count is the audience');

    $http->answers[] = new HttpResponse(503, '');
    TestKit::clock($app)->advance(Timing::MINUTE);
    eq($app->cdn()->maintain()['listeners']['main'], null, 'a failed log read is no count');
    eq($app->cdn()->listeners('main'), 3, 'and keeps the last one while it is fresh');
    TestKit::clock($app)->advance(10 * Timing::MINUTE);
    eq($app->cdn()->listeners('main'), null, 'an old count is not shown as now');
    eq(TestKit::app(CDN_ENV)->cdn()->listeners('main'), null, 'no count in a fresh station');
});

test('cdn: deleted media is purged at the edge; a failed purge stays queued; nothing without a CDN', function () {
    $app = TestKit::app(CDN_ENV);
    $http = new FakeHttp();
    $app->set('http', $http);
    $url = $app->media()->put('contrib', 'deleted0001.mp3', 'voice');
    $app->media()->delete($url);
    eq($app->store()->get('cdn_purge'), [$url], 'queued, no network in the publish phase');

    $http->answers[] = new HttpResponse(500, '');
    $app->cdn()->maintain();
    eq($app->store()->get('cdn_purge'), [$url], 'kept after a failure');

    $http->answers[] = new HttpResponse(200, '');
    $http->answers[] = bunnyLog([]);
    $out = $app->cdn()->maintain();
    eq($out['purged'], 1, 'purged');
    eq($app->store()->get('cdn_purge'), [], 'queue empty');
    $purge = array_values(array_filter($http->sent, fn($s) => str_starts_with((string) $s['url'], 'https://api.bunny.net/purge')));
    parse_str((string) parse_url((string) end($purge)['url'], PHP_URL_QUERY), $q);
    eq($q['url'], 'https://cdn.example.net' . $url, 'the CDN URL of the file');

    $plain = TestKit::app();
    $u = $plain->media()->put('contrib', 'deleted0002.mp3', 'x');
    $plain->media()->delete($u);
    eq($plain->store()->get('cdn_purge'), null, 'without a CDN nothing is queued');
    eq($plain->cdn()->maintain(), ['skipped' => 'not configured'], 'and nothing runs');
});

test('cdn: host clips removed by retention are purged at the edge too', function () {
    $app = TestKit::app(CDN_ENV);
    $clip = $app->media()->put('host', 'hostclip0001.mp3', 'Jenny from Munich');
    touch((string) $app->media()->path($clip), (int) (TestKit::T0 / 1000) - 3 * 86400);
    $app->set('http', new FakeHttp());
    $app->tick()->run('test');
    check(!is_file((string) $app->media()->path($clip)), 'deleted here after 48 h');
    check(in_array($clip, (array) $app->store()->get('cdn_purge'), true) || $app->store()->get('cdn_purge') === [], 'queued for the edge (or already purged in the same tick)');
});

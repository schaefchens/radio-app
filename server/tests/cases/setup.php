<?php
declare(strict_types=1);

use Arche\App;
use Arche\Plan\StationSetup;
use Arche\Support\HttpClient;
use Arche\Support\HttpResponse;

/**
 * The station's setup as a file for a local stack (Plan\StationSetup): what
 * an admin downloads holds what the station made, nothing of its listeners
 * and nothing secret; a local stack takes it in place of its own setup,
 * keeps its admin and its computers, fetches the media and starts afresh.
 */

/** The public site, file by file: what it has answers 200, the rest 404. */
final class SiteHttp extends HttpClient
{
    /** @var list<string> */
    public array $asked = [];

    /** @param array<string,string> $files URL => bytes */
    public function __construct(public array $files = []) {}

    public function request(string $method, string $url, array $headers = [], string|array|null $body = null, int $timeout = 20): HttpResponse
    {
        $this->asked[] = $url;
        return isset($this->files[$url]) ? new HttpResponse(200, $this->files[$url]) : new HttpResponse(404, 'Not found');
    }
}

/** True when $fn throws with $text in its message. */
function failsWith(callable $fn, string $text): bool
{
    try {
        $fn();
        return false;
    } catch (Throwable $e) {
        return str_contains($e->getMessage(), $text);
    }
}

/**
 * A station with something of everything the setup takes — songs, a group,
 * a host with a key and a picture, a recorded line, a program picture — and
 * of everything it leaves: a listener's recording in the library, a
 * request, an opening prayer, moderators' notes.
 *
 * @return array<string,mixed>
 */
function liveStation(App $app): array
{
    $store = $app->store();
    $now = $app->clock->now();
    [$channel, $program] = onAir($app);
    $morning = $app->catalog()->saveProgram(null, (int) $channel['id'], ['slug' => 'morning', 'title_en' => 'Morning', 'title_de' => 'Morgen', 'allowed' => ['song']], 'test');
    $store->update('programs', ['image' => '/media/stage/fedcba9876543210.webp'], 'id = ?', [$morning['id']]);
    TestKit::songs($app, 3);
    TestKit::songs($app, 1, 240_000, ['thumb' => 'https://i.ytimg.com/vi/abc/hqdefault.jpg']);
    // A song a listener asked for, in the library since — and a listener's recording, titled with name and place.
    TestKit::songs($app, 1, 240_000, ['source' => 'submission', 'submission_id' => 41, 'thumb' => '/media/thumbs/requested01.jpg']);
    $store->insert('library_items', ['kind' => 'contrib', 'audio' => '/media/contrib/0123456789abcdef.mp3', 'title' => 'Anna, Köln', 'duration_ms' => 60_000,
        'source' => 'submission', 'submission_id' => 42, 'created' => $now, 'updated' => $now]);
    $app->groups()->save(null, ['name' => 'Grace Chapel', 'about_en' => 'A church in Accra.', 'notice' => true, 'note' => 'Pastor Mensah asked by mail.'], 'test');

    $joy = elevenHost($app, ['name' => 'Joy']);
    $store->update('hosts', ['avatar' => '/media/hosts/0123456789abcdef.webp', 'last_error' => 'quota_exceeded', 'resting_until' => $now + 3600, 'fail_count' => 2], 'id = ?', [$joy['id']]);
    noRefill($app);
    libraryMode($app, ['encourage'], $program);
    $line = recordedLine($app, 'encourage');
    $store->update('host_lines', ['created_by' => 'mod:QX7Z', 'note' => 'Slow down a little.'], 'id = ?', [$line['id']]);
    $store->insert('host_lines', ['host_id' => hope($app)['id'], 'kind' => 'encourage', 'texts' => '{"en":"Never recorded."}', 'state' => 'failed',
        'error' => 'voice failed', 'created' => $now, 'updated' => $now]);

    $who = listener($app);
    $store->insert('submissions', ['public_id' => 'sub-anna', 'identity_id' => $who['id'], 'channel_id' => $channel['id'], 'program_id' => $program['id'],
        'type' => 'song', 'status' => 'approved', 'name' => 'Anna', 'place' => 'Köln', 'message' => 'Für meine Mutter', 'created' => $now, 'updated' => $now]);
    $store->insert('opening_prayers', ['program_id' => $program['id'], 'mode' => 'text', 'name' => 'Pastor Miller', 'text_en' => 'Words of Pastor Miller.',
        'created_by' => 'mod:QX7Z', 'created' => $now]);
    return ['joy' => (int) $joy['id'], 'line' => $line, 'morning' => (int) $morning['id']];
}

test('setup: an admin downloads the station\'s setup, nothing of its listeners and nothing secret', function () {
    $app = TestKit::app();
    $h = modHeaders($app);
    $given = liveStation($app);
    $sealed = (string) $app->store()->value('SELECT api_key FROM hosts WHERE id = ?', [$given['joy']]);
    check($sealed !== '', 'Joy has a key here');

    eq(call($app, 'GET', '/api/mod/export', [], moderatorHeaders($app))[0], 403, 'moderators cannot');
    [$status, $setup] = call($app, 'GET', '/api/mod/export', [], $h);
    eq($status, 200, 'admins can');
    eq([$setup['format'], $setup['version'], $setup['site'], $setup['exported']], ['arche-station-setup', 1, 'https://radio.schaefchens.de', intdiv(TestKit::T0, 1000)], 'what it is, from where, when');
    eq(array_keys($setup['tables']), ['channels', 'programs', 'day_plans', 'day_plan_blocks', 'week_plan', 'special_days', 'library_groups',
        'library_items', 'hosts', 'host_lineups', 'program_lines', 'host_line_options', 'host_lines'], 'the setup and nothing else');
    eq(array_column($setup['tables']['programs'], 'slug'), ['live', 'morning'], 'the programs');

    $items = $setup['tables']['library_items'];
    eq(count($items), 5, 'the songs, the requested one too');
    check(!in_array('contrib', array_column($items, 'kind'), true), 'no listener\'s recording');
    eq(array_values(array_unique(array_column($items, 'submission_id'))), [null], 'no link to a submission');
    $joy = array_values(array_filter($setup['tables']['hosts'], fn(array $r): bool => $r['name'] === 'Joy'))[0] ?? [];
    eq([$joy['api_key'], $joy['key_hint'], $joy['last_error'], $joy['resting_until'], $joy['fail_count']], ['', '', '', 0, 0], 'no key, no rest, no error');
    eq([$joy['provider'], $joy['avatar'], json_decode($joy['voices'], true)], ['elevenlabs', '/media/hosts/0123456789abcdef.webp', ['en' => EL_VOICE]], 'its voice and picture stay');
    eq($setup['tables']['library_groups'][0]['note'], '', 'no moderator\'s note on a group');
    eq(array_column($setup['tables']['host_lines'], 'id'), [(int) $given['line']['id']], 'the recorded line, not the failed one');
    eq([$setup['tables']['host_lines'][0]['created_by'], $setup['tables']['host_lines'][0]['note']], ['', ''], 'nobody who wrote it, no note');
    eq(count($setup['tables']['program_lines']), 1, 'the program\'s words from the lines');

    $json = (string) json_encode($setup, JSON_UNESCAPED_UNICODE);
    $admin = $app->identities()->resolve($h['x-arche-id'], $h['x-arche-secret'], false) ?? [];
    foreach (['Anna', 'Köln', 'Mutter', 'Pastor Miller', 'Mensah', 'QX7Z', 'sub-anna', $sealed, EL_KEY, (string) $admin['public_id']] as $s) {
        check(!str_contains($json, $s), "nothing of \"$s\"");
    }

    $media = $setup['media'];
    sort($media);
    $want = ['/media/hosts/0123456789abcdef.webp', '/media/stage/fedcba9876543210.webp', '/media/thumbs/requested01.jpg', ...array_values($given['line']['audio'])];
    sort($want);
    eq($media, $want, 'the media it shows and plays, from this site only');
    check((bool) $app->store()->value("SELECT 1 FROM audit WHERE event = 'Station setup exported'"), 'audited');
});

test('setup: a local stack takes the setup in place of its own, and keeps its admin and computers', function () {
    $live = TestKit::app();
    $given = liveStation($live);
    $setup = (new StationSetup($live))->export();

    $local = TestKit::app(['ARCHE_ENV' => 'local']);
    $h = modHeaders($local);
    $key = newWorker($local);
    programChangeAt($local, 12 * 60 + 30);
    TestKit::songs($local, 6);
    ticks($local, 3);
    $store = $local->store();
    $who = listener($local);
    $store->insert('submissions', ['public_id' => 'sub-local', 'identity_id' => $who['id'], 'channel_id' => 1, 'program_id' => 2,
        'type' => 'song', 'status' => 'approved', 'created' => 1, 'updated' => 1]);
    check((int) $store->value("SELECT COUNT(*) FROM timeline_items WHERE state = 'committed'") > 0, 'a program on air here');
    $planVersion = (int) $store->get('plan_version');

    // One picture is here already; the site has every file but the thumbnail.
    $local->media()->put('stage', 'fedcba9876543210.webp', 'already here');
    $site = new SiteHttp();
    foreach ($setup['media'] as $path) {
        if ($path !== '/media/thumbs/requested01.jpg') $site->files['https://radio.schaefchens.de' . $path] = "file:$path";
    }
    $local->set('http', $site);
    $said = [];
    $out = (new StationSetup($local))->import($setup, function (string $line) use (&$said): void {
        $said[] = $line;
    });

    // A fresh process sees the station as the next tick will (no caches of the old setup).
    $fresh = new App($local->config, $local->clock);
    eq($out['tables'], StationSetup::counts($setup['tables']), 'every table counted');
    eq((new StationSetup($fresh))->export()['tables'], $setup['tables'], 'the setup here is the one exported, row for row');
    eq((int) $fresh->store()->value("SELECT COUNT(*) FROM programs WHERE slug = 'evening'"), 0, 'this stack\'s own program is gone');
    foreach (['timeline_items', 'host_breaks', 'jobs', 'submissions'] as $table) {
        eq((int) $fresh->store()->value("SELECT COUNT(*) FROM $table"), 0, "no $table of the old setup");
    }
    eq((int) $fresh->store()->value("SELECT COUNT(*) FROM kv WHERE key LIKE 'frontier:%' OR key LIKE 'published:%'"), 0, 'the program starts afresh');
    eq((int) $fresh->store()->get('plan_version'), $planVersion + 1, 'a new plan version');
    eq((int) $fresh->store()->value('PRAGMA foreign_keys'), 1, 'foreign keys on again');
    check(!is_file($local->config->dataDir . '/maintenance'), 'ticks go on');
    check((bool) $fresh->store()->value("SELECT 1 FROM audit WHERE event = 'Station setup imported' AND detail LIKE 'from https://radio.schaefchens.de%'"), 'audited');

    // The local admin and the local computer stay.
    eq(call($fresh, 'GET', '/api/mod/status', [], $h)[0], 200, 'the admin is still admin');
    eq(workerPoll($fresh, $key)[0], 200, 'the computer still polls');

    // The old station, as it was, in a backup.
    $backup = $local->config->dataDir . '/backups/' . $out['backup'];
    check(is_file($backup), 'a backup first');
    eq((int) (new PDO('sqlite:' . $backup))->query("SELECT COUNT(*) FROM programs WHERE slug = 'evening'")->fetchColumn(), 1, 'the backup holds the old setup');

    // Media: fetched from the site, what is here kept, a missing one reported.
    eq([$out['media']['fetched'], $out['media']['kept'], $out['media']['failed']], [count($setup['media']) - 2, 1, ['/media/thumbs/requested01.jpg']], 'media');
    eq(file_get_contents($fresh->publicPath('media/hosts/0123456789abcdef.webp')), 'file:/media/hosts/0123456789abcdef.webp', 'a fetched picture');
    eq(file_get_contents($fresh->publicPath('media/stage/fedcba9876543210.webp')), 'already here', 'one here is kept');
    check(!in_array('https://radio.schaefchens.de/media/stage/fedcba9876543210.webp', $site->asked, true), 'and not asked for');
    check(in_array('Media: ' . (count($setup['media']) - 2) . ' fetched, 1 already here, 1 failed', $said, true), 'told as it goes');

    // The program goes on with the imported plan and library; a new host takes a new id.
    ticks($fresh, 2);
    $programs = array_map('intval', array_column($setup['tables']['programs'], 'id'));
    $aired = array_map('intval', array_column($fresh->store()->all("SELECT DISTINCT program_id FROM timeline_items WHERE state = 'committed'"), 'program_id'));
    check($aired !== [] && array_diff($aired, $programs) === [], 'on air again, in the imported programs');
    $songs = array_map('intval', array_column($fresh->store()->all("SELECT library_id FROM timeline_items WHERE type = 'song' AND library_id IS NOT NULL"), 'library_id'));
    check($songs !== [] && array_diff($songs, array_map('intval', array_column($setup['tables']['library_items'], 'id'))) === [], 'from the imported library');
    $newHost = $fresh->hosts()->save(null, ['name' => 'New'], 'test');
    check((int) $newHost['id'] > max(array_column($setup['tables']['hosts'], 'id')), 'a new host never takes an imported one\'s id');
});

test('setup: only a local stack takes a setup, only a whole one, and a broken one changes nothing', function () {
    $live = TestKit::app();
    liveStation($live);
    $setup = (new StationSetup($live))->export();

    $station = TestKit::app();
    check(failsWith(fn() => (new StationSetup($station))->import($setup), 'Only a local stack'), 'never on a station that is not a local stack');

    $local = TestKit::app(['ARCHE_ENV' => 'local']);
    $local->set('http', new SiteHttp());
    programChangeAt($local, 12 * 60 + 30);
    $programs = fn(): array => $local->store()->all('SELECT * FROM programs ORDER BY id');
    $before = $programs();
    $import = fn(array $data) => (new StationSetup($local))->import($data);
    check(failsWith(fn() => $import(['format' => 'something else'] + $setup), 'not a station setup'), 'only a setup file');
    check(failsWith(fn() => $import(['version' => 2] + $setup), 'newer ARCHE'), 'not one from a newer ARCHE');
    $noHosts = $setup;
    unset($noHosts['tables']['hosts']);
    check(failsWith(fn() => $import($noHosts), 'has no hosts'), 'only a whole one');
    $nested = $setup;
    $nested['tables']['programs'][0]['settings'] = ['format' => 'music'];
    check(failsWith(fn() => $import($nested), 'plain values'), 'rows of plain values');
    check(failsWith(fn() => $import(['site' => 'http://radio.schaefchens.de'] + $setup), 'https site'), 'media from an https site');
    $broken = $setup;
    $broken['tables']['day_plan_blocks'][0]['program_id'] = 999;
    check(failsWith(fn() => $import($broken), 'day_plan_blocks row'), 'a setup that does not hold together');
    eq($programs(), $before, 'none of them changed anything');
    eq((int) $local->store()->value('PRAGMA foreign_keys'), 1, 'foreign keys on again');
    check(!is_file($local->config->dataDir . '/maintenance'), 'ticks go on');

    // A file edited by hand brings no key and no listener's recording either.
    $edited = $setup;
    $edited['tables']['hosts'][0]['api_key'] = 'sealed-elsewhere';
    $edited['tables']['library_items'][] = ['id' => 900, 'kind' => 'contrib', 'title' => 'Anna, Köln', 'audio' => '/media/contrib/0123456789abcdef.mp3',
        'duration_ms' => 1000, 'created' => 1, 'updated' => 1];
    $import($edited);
    eq((string) $local->store()->value('SELECT api_key FROM hosts ORDER BY id LIMIT 1'), '', 'no key comes in');
    eq((int) $local->store()->value("SELECT COUNT(*) FROM library_items WHERE kind = 'contrib'"), 0, 'no recording comes in');
});

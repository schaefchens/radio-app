<?php
declare(strict_types=1);

use Arche\Host\Hosts;
use Arche\Support\HttpResponse;
use Arche\Support\Sealed;

/**
 * The station's on-air hosts (Host\Hosts): made in /mod by admins with a key
 * of their own (sealed, never shown again), picked per show from a program's
 * lineup, replaced by the next who can speak when a voice fails — and what
 * listeners see of them in the files.
 */

const EL_VOICE = '21m00Tcm4TlvDq8ikWAM';
const EL_KEY = 'sk_0123456789abcdef0123456789abcdef';

/** @param array<string,mixed> $data @return array<string,mixed> */
function makeHost(Arche\App $app, array $data): array
{
    return $app->hosts()->save(null, $data, 'test');
}

/** An ElevenLabs host that may speak (a key, a voice, characters for the day). @return array<string,mixed> */
function elevenHost(Arche\App $app, array $data = []): array
{
    return makeHost($app, $data + ['name' => 'Hope', 'provider' => 'elevenlabs', 'api_key' => EL_KEY, 'voices' => ['en' => EL_VOICE], 'max_chars_day' => 5000]);
}

/** The seeded host every fresh station has (the first made). @return array<string,mixed> */
function hope(Arche\App $app): array
{
    $first = (int) $app->store()->value('SELECT MIN(id) FROM hosts');
    return $app->hosts()->get($first) ?? throw new LogicException('no host');
}

/** @return array{0:array<string,mixed>,1:array<string,mixed>} the main channel and the program on air all day */
function onAir(Arche\App $app): array
{
    $ch = TestKit::main($app);
    return [$ch, $app->catalog()->program((int) $ch['fallback_program_id']) ?? throw new LogicException('no program')];
}

/** One plain break of the program on air, as the Drafter makes it. */
function breakNow(Arche\App $app, string $kind = 'break'): int
{
    [$ch, $program] = onAir($app);
    $now = $app->clock->nowMs();
    return $app->hostBreaks()->create($ch, $program, $kind, ['est_start' => $now, 'block_start' => $now, 'unit' => null]);
}

/** A provider's MP3 (the bundled stub clip). */
function mp3(): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/resources/stub-voice.mp3');
}

/** Headers of a passphrase identity with the moderator role. */
function moderatorHeaders(Arche\App $app): array
{
    $h = authHeaders();
    [$cid, $cs] = credential();
    call($app, 'POST', '/api/identity/claim', ['credId' => $cid, 'credSecret' => $cs], $h);
    $me = $app->identities()->resolve($h['x-arche-id'], $h['x-arche-secret'], false) ?? throw new LogicException('no identity');
    $app->identities()->setRole((string) $me['public_id'], 'moderator', 'test');
    return $h;
}

/** @return list<array<string,mixed>> every host item of the published minute files */
function publishedHostItems(Arche\App $app, string $channel = 'main'): array
{
    $out = [];
    foreach (glob($app->publicPath("program/$channel/slots/*/*.json")) ?: [] as $f) {
        foreach (json_decode((string) file_get_contents($f), true)['items'] ?? [] as $it) {
            if ($it['type'] === 'host') $out[$it['id']] = $it;
        }
    }
    return array_values($out);
}

test('hosts: a fresh station has Hope on its main channel; the channels\' hosts become hosts and lineups — one per distinct host — and a replay changes nothing', function () {
    $app = TestKit::app();
    eq(array_column($app->hosts()->all(), 'name'), ['Hope'], 'a fresh install: Hope');
    $main = TestKit::main($app);
    eq($app->hosts()->lineup('channel', (int) $main['id']), [['id' => hope($app)['id'], 'role' => 'main']], 'on the main channel');

    $store = $app->store();
    $cat = $app->catalog();
    $kids = $cat->saveChannel(null, ['slug' => 'kids', 'name_en' => 'Kids', 'name_de' => 'Kinder'], 'test');
    $cat->saveChannel(null, ['slug' => 'prayer', 'name_en' => 'Prayer', 'name_de' => 'Gebet'], 'test');
    // As the channels were before hosts: two share Hope, one has its own.
    $store->update('channels', ['host_name' => 'Sunny', 'host_voice_en' => 'nova', 'host_voice_de' => 'shimmer', 'host_style' => 'Cheerful.', 'host_avatar' => '/media/stage/s.webp'], 'id = ?', [(int) $kids['id']]);
    $store->query('DELETE FROM host_lineups');
    $store->query('DELETE FROM hosts');
    $version = $cat->version();
    $store->set('schema', 12);
    Arche\Schema::migrate($store, $app->clock->nowMs());
    $hosts = $store->all('SELECT id, name, avatar, style, voices, instructions, provider, model FROM hosts ORDER BY id');
    eq(array_column($hosts, 'name'), ['Hope', 'Sunny'], 'one host per distinct channel host, the main channel\'s first');
    eq([json_decode($hosts[1]['voices'], true), $hosts[1]['avatar'], $hosts[1]['provider'], $hosts[1]['model']], [['en' => 'nova', 'de' => 'shimmer'], '/media/stage/s.webp', 'openai', 'gpt-4o-mini-tts'], 'with its voices and picture');
    eq([$hosts[1]['style'], $hosts[1]['instructions']], ['Cheerful.', 'Warm and calm, like a Christian radio host. Cheerful.'], 'its style notes still go to the writer and to the voice');
    eq(array_map(fn($r) => $r['slug'] . ':' . $r['name'], $store->all('SELECT c.slug, h.name FROM host_lineups l JOIN channels c ON c.id = l.channel_id JOIN hosts h ON h.id = l.host_id ORDER BY c.id')),
        ['main:Hope', 'kids:Sunny', 'prayer:Hope'], 'each channel its host, on air');
    eq($cat->version(), $version, 'no new plan: drafts voiced already stay');
    $store->set('schema', 12);
    Arche\Schema::migrate($store, $app->clock->nowMs());
    eq([(int) $store->value('SELECT COUNT(*) FROM hosts'), (int) $store->value('SELECT COUNT(*) FROM host_lineups')], [2, 3], 'a replay adds nothing');
});

test('hosts: admins make them, moderators read them; the key is sealed, comes back as its last characters only, and never reaches the audit', function () {
    $app = TestKit::app();
    $admin = modHeaders($app);
    $mod = moderatorHeaders($app);
    $body = ['name' => 'David', 'provider' => 'elevenlabs', 'api_key' => EL_KEY, 'voices' => ['en' => EL_VOICE], 'max_chars_day' => 300, 'color' => '#E0763A'];
    eq(call($app, 'POST', '/api/mod/hosts', $body, authHeaders())[0], 403, 'not for listeners');
    eq(call($app, 'POST', '/api/mod/hosts', $body, $mod)[0], 403, 'not for moderators');
    [$st, $d] = call($app, 'POST', '/api/mod/hosts', $body, $admin);
    eq($st, 200, 'an admin makes one');
    $id = (int) $d['host']['id'];
    $sealed = (string) $app->store()->value('SELECT api_key FROM hosts WHERE id = ?', [$id]);
    check(str_starts_with($sealed, 'v1:') && !str_contains($sealed, '0123456789abcdef'), 'sealed in the database');
    eq((new Sealed(str_repeat('a1', 32)))->open($sealed), EL_KEY, 'with a key from the pepper');
    check(!str_contains((string) json_encode($d), '0123456789abcdef'), 'never in an answer');
    eq([$d['host']['key_set'], $d['host']['key_hint'], $d['host']['color']], [true, '…cdef', '#e0763a'], 'only that there is one, and its end');
    [$st, $list] = modGet($app, '/api/mod/hosts', [], $mod);
    eq($st, 200, 'moderators read the hosts (a program\'s lineup is theirs)');
    $seen = array_values(array_filter($list['hosts'], fn($h) => $h['id'] === $id))[0] ?? [];
    check(!array_key_exists('key_hint', $seen) && $seen['key_set'] === true, 'without even the key\'s end');
    $audit = implode("\n", array_column($app->store()->all('SELECT detail FROM audit'), 'detail'));
    check(str_contains($audit, 'key changed') && !str_contains($audit, '0123456789abcdef') && !str_contains($audit, 'cdef'), 'the audit says a key was set, nothing of it');
    eq($app->hosts()->key($app->hosts()->get($id) ?? []), EL_KEY, 'opened for the voice only');

    // A sealed value that does not open (the pepper changed): enter it again.
    $app->store()->update('hosts', ['api_key' => 'v1:' . base64_encode(random_bytes(60))], 'id = ?', [$id]);
    $app->set('hosts', new Hosts($app));
    $h = $app->hosts()->get($id) ?? [];
    eq([$app->hosts()->view($h, true)['key_unreadable'], $app->hosts()->key($h)], [true, ''], 'an unreadable key: none to speak with, and it says so');
    // Switching the service drops the other one's key; '' removes it.
    $h = $app->hosts()->save($id, ['provider' => 'openai'], 'test');
    eq([$h['api_key'], $h['model'], $h['voices']], ['', 'gpt-4o-mini-tts', ['en' => 'coral', 'de' => 'coral']], 'to OpenAI: no key, its model and voice');
});

test('hosts: what a save checks, and settings kept within what each service takes', function () {
    $app = TestKit::app();
    foreach ([
        [['name' => ''], 'host_name'],
        [['name' => str_repeat('x', 41)], 'host_name'],
        [['name' => 'A', 'color' => 'red'], 'invalid_color'],
        [['name' => 'A', 'about_en' => str_repeat('x', 201)], 'host_about'],
        [['name' => 'A', 'style' => str_repeat('x', 601)], 'host_style'],
        [['name' => 'A', 'instructions' => str_repeat('x', 501)], 'host_instructions'],
        [['name' => 'A', 'model' => 'GPT 4!'], 'host_model'],
        [['name' => 'A', 'voices' => ['en' => 'Coral Voice']], 'host_voice'],
        [['name' => 'A', 'provider' => 'elevenlabs', 'voices' => ['en' => 'coral']], 'host_voice'],
        [['name' => 'A', 'provider' => 'acme'], 'host_provider'],
        [['name' => 'A', 'api_key' => 'short'], 'host_key'],
    ] as [$data, $code]) check(refuses(fn() => makeHost($app, $data), $code), "refused ($code): " . json_encode($data));
    eq(makeHost($app, ['name' => 'Fast', 'settings' => ['speed' => 9]])['settings'], ['speed' => 4.0], 'OpenAI speed at most 4');
    $el = makeHost($app, ['name' => 'El', 'provider' => 'elevenlabs', 'settings' => ['stability' => 2, 'style' => -1, 'speed' => 2, 'language' => false]]);
    eq($el['settings'], ['stability' => 1.0, 'similarity' => 0.75, 'style' => 0.0, 'speaker_boost' => true, 'speed' => 1.2, 'language' => false], 'ElevenLabs settings clamped, defaults filled in');
    eq([$el['model'], $el['voices'], $el['max_chars_day']], ['eleven_flash_v2_5', [], 0], 'its model; no voice until one is chosen; no cap');
    check(!$app->hosts()->canSpeak($el), 'and so it does not speak');
});

test('hosts: one in a lineup cannot be deleted, nor the last one', function () {
    $app = TestKit::app();
    [$ch, $program] = onAir($app);
    $hope = hope($app);
    check(refuses(fn() => $app->hosts()->delete($hope['id'], 'test'), 'host_in_use'), 'Hope is on the main channel');
    $spare = makeHost($app, ['name' => 'Spare']);
    $app->hosts()->delete($spare['id'], 'test');
    check($app->hosts()->get($spare['id']) === null, 'one in no lineup goes');
    $david = makeHost($app, ['name' => 'David']);
    $app->hosts()->setLineup('channel', (int) $ch['id'], [['id' => $david['id'], 'role' => 'main']]);
    $app->hosts()->delete($hope['id'], 'test');
    $app->hosts()->setLineup('channel', (int) $ch['id'], []);
    check(refuses(fn() => $app->hosts()->delete($david['id'], 'test'), 'last_host'), 'the station keeps one');
    eq(makeHost($app, ['name' => 'New'])['id'] > $david['id'], true, 'ids are never handed out again');
});

test('hosts: a program\'s lineup goes with its PATCH, an older /mod tab leaves it alone, and a lineup needs someone on air', function () {
    $app = TestKit::app();
    $admin = modHeaders($app);
    [$ch, $program] = onAir($app);
    $david = makeHost($app, ['name' => 'David']);
    $hope = hope($app);
    $pid = (int) $program['id'];
    [$st, $d] = call($app, 'PATCH', "/api/mod/programs/$pid", ['hosts' => [['id' => $david['id'], 'role' => 'main'], ['id' => $hope['id'], 'role' => 'fallback']]], $admin);
    eq([$st, $d['program']['hosts']], [200, [['id' => $david['id'], 'role' => 'main'], ['id' => $hope['id'], 'role' => 'fallback']]], 'set with the program');
    // An older tab sends the settings it knows and no lineup.
    [$st] = call($app, 'PATCH', "/api/mod/programs/$pid", ['settings' => ['host' => ['enabled' => true, 'every_songs' => 2, 'intro' => true, 'outro' => true]]], $admin);
    eq([$st, $app->hosts()->lineup('program', $pid)], [200, [['id' => $david['id'], 'role' => 'main'], ['id' => $hope['id'], 'role' => 'fallback']]], 'kept');
    foreach ([[['id' => $hope['id'], 'role' => 'fallback']], [['id' => 999, 'role' => 'main']], [['id' => $hope['id'], 'role' => 'main'], ['id' => $hope['id'], 'role' => 'fallback']], [['id' => $hope['id'], 'role' => 'boss']]] as $bad) {
        eq(call($app, 'PATCH', "/api/mod/programs/$pid", ['hosts' => $bad], $admin), [422, ['error' => 'host_lineup']], 'refused: ' . json_encode($bad));
    }
    eq(array_column($app->hosts()->effective($ch, $program)['mains'], 'name'), ['David'], 'the program\'s own');
    call($app, 'PATCH', "/api/mod/programs/$pid", ['hosts' => []], $admin);
    eq(array_column($app->hosts()->effective($ch, $program)['mains'], 'name'), ['Hope'], 'none of its own: the channel\'s');
    $kids = $app->catalog()->saveChannel(null, ['slug' => 'kids', 'name_en' => 'Kids', 'name_de' => 'Kinder'], 'test');
    eq(array_column($app->hosts()->effective($kids, null)['mains'], 'name'), ['Hope'], 'a channel without: the main channel\'s');
    [$st, $d] = call($app, 'PATCH', '/api/mod/channels/' . $kids['id'], ['hosts' => [['id' => $david['id'], 'role' => 'main']]], $admin);
    eq([$st, array_column($app->hosts()->effective($kids, null)['mains'], 'name')], [200, ['David']], 'its own');
});

test('hosts: one host per show, at random among the on-air hosts who can speak — never the previous show\'s when another can — kept for the show', function () {
    $app = TestKit::app();
    [$ch, $program] = onAir($app);
    $pid = (int) $program['id'];
    $hosts = $app->hosts();
    $hosts->useRandom(fn(int $min, int $max): int => $min);
    $anna = makeHost($app, ['name' => 'Anna']);
    $ben = makeHost($app, ['name' => 'Ben']);
    $hosts->setLineup('program', $pid, [['id' => $anna['id'], 'role' => 'main'], ['id' => $ben['id'], 'role' => 'main']]);
    $mains = $hosts->effective($ch, $program)['mains'];
    eq($hosts->showHost($ch, $pid, 1_000, $mains), $anna['id'], 'a show gets one');
    eq($hosts->showHost($ch, $pid, 1_000, $mains), $anna['id'], 'and keeps it');
    eq($hosts->showHost($ch, $pid, 2_000, $mains), $ben['id'], 'the next show: another');
    eq($hosts->showHost($ch, $pid, 3_000, $mains), $anna['id'], 'and the one after: again another');
    eq($hosts->showHost($ch, $pid, 2_000, $mains), $ben['id'], 'a show already picked keeps its host');
    $hosts->failed($ben, new Arche\Ai\VoiceError('openai', 401, 'invalid_api_key', 'OpenAI HTTP 401'));
    eq($hosts->showHost($ch, $pid, 4_000, $hosts->effective($ch, $program)['mains']), $anna['id'], 'only who can speak — even the last show\'s');
    $hosts->setLineup('program', $pid, [['id' => $ben['id'], 'role' => 'main']]);
    eq($hosts->showHost($ch, $pid, 1_000, $hosts->effective($ch, $program)['mains']), null, 'a pick that left the lineup goes; nobody who can speak: none yet');
    // A program on air all day: a show per local day.
    eq($hosts->showStart($ch, $pid, TestKit::T0), (new DateTimeImmutable('2026-09-23 00:00', new DateTimeZone('Europe/Berlin')))->getTimestamp() * 1000, 'the day\'s start in the station\'s zone');

    // In the program: every moment of a show has the same host.
    $app = TestKit::app();
    TestKit::songs($app, 12);
    [$ch, $program] = onAir($app);
    $a = makeHost($app, ['name' => 'Anna']);
    $b = makeHost($app, ['name' => 'Ben']);
    $app->hosts()->setLineup('program', (int) $program['id'], [['id' => $a['id'], 'role' => 'main'], ['id' => $b['id'], 'role' => 'main']]);
    ticks($app, 40);
    $ready = $app->store()->all("SELECT context FROM host_breaks WHERE state = 'ready'");
    check(count($ready) >= 3, 'several moments aired');
    eq(count(array_unique(array_map(fn($r) => json_decode($r['context'], true)['host_id'] ?? 0, $ready))), 1, 'all by one host');
});

test('hosts: who steps in — one of the same name first, then the fallbacks in order, then the other on-air hosts', function () {
    $app = TestKit::app();
    [$ch, $program] = onAir($app);
    $hosts = $app->hosts();
    $hosts->useRandom(fn(int $min, int $max): int => $min);
    $hopeEl = elevenHost($app);
    $david = makeHost($app, ['name' => 'David']);
    $grace = makeHost($app, ['name' => 'Grace']);
    $hope = hope($app);
    $hosts->setLineup('program', (int) $program['id'], [
        ['id' => $hopeEl['id'], 'role' => 'main'], ['id' => $david['id'], 'role' => 'main'],
        ['id' => $grace['id'], 'role' => 'fallback'], ['id' => $hope['id'], 'role' => 'fallback'],
    ]);
    $hb = $app->hostBreaks()->get(breakNow($app)) ?? [];
    eq($hosts->forBreak($hb)['id'], $hopeEl['id'], 'the show\'s host');
    eq($hosts->forBreak($hb, [$hopeEl['id']])['id'], $hope['id'], 'it cannot: the same persona with another voice');
    eq($hosts->forBreak($hb, [$hopeEl['id'], $hope['id']])['id'], $grace['id'], 'then the first fallback');
    eq($hosts->forBreak($hb, [$hopeEl['id'], $hope['id'], $grace['id']])['id'], $david['id'], 'then the other on-air host');
    eq($hosts->forBreak($hb, [$hopeEl['id'], $hope['id'], $grace['id'], $david['id']]), null, 'then nobody');
    // ElevenLabs without a daily cap never speaks: nobody in a lineup of it alone, so no moments planned.
    $app = TestKit::app();
    TestKit::songs($app, 12);
    [$ch, $program] = onAir($app);
    $capless = makeHost($app, ['name' => 'Free', 'provider' => 'elevenlabs', 'api_key' => EL_KEY, 'voices' => ['en' => EL_VOICE]]);
    $app->hosts()->setLineup('program', (int) $program['id'], [['id' => $capless['id'], 'role' => 'main']]);
    check(!$app->hostBreaks()->available($ch, $program) && $app->hostBreaks()->available(), 'this program has no voice — the station still has one');
    ticks($app, 20);
    eq((int) $app->store()->value('SELECT COUNT(*) FROM host_breaks'), 0, 'so no moment is planned that nobody could speak');
});

test('hosts: in stub mode no provider is called — an ElevenLabs host too — and its characters still count against its cap', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $http = new FakeHttp();
    $app->set('http', $http);
    [$ch, $program] = onAir($app);
    // Room for one language of the stub's words, not two: one host voices both, so Hope does.
    $el = elevenHost($app, ['name' => 'Eli', 'max_chars_day' => 70]);
    $app->hosts()->setLineup('program', (int) $program['id'], [['id' => $el['id'], 'role' => 'main'], ['id' => hope($app)['id'], 'role' => 'fallback']]);
    $id = breakNow($app);
    runJobs($app);
    $hb = $app->hostBreaks()->get($id) ?? [];
    eq([$hb['state'], $hb['context']['host_id'] ?? null, $hb['context']['tried'] ?? null], ['ready', hope($app)['id'], [$el['id']]], 'too long for Eli\'s day: Hope, before any clip');
    eq($http->sent, [], 'no request left the station');
    $app->hosts()->save($el['id'], ['max_chars_day' => 5000], 'test');
    $id = breakNow($app);
    runJobs($app);
    eq([$app->hostBreaks()->get($id)['context']['host_id'] ?? null, $http->sent], [$el['id'], []], 'with room, Eli — still without a request');
    check($app->hosts()->usedToday($el['id']) > 50, 'its characters counted');
});

test('hosts: an ElevenLabs quota used up — the same persona on OpenAI voices the moment without a new script, ElevenLabs rests until tomorrow', function () {
    $app = TestKit::app(['OPENAI_KEY' => 'sk-station-0123456789'] + LIVE_NO_KEYS);
    $http = new FakeHttp();
    $app->set('http', $http);
    [$ch, $program] = onAir($app);
    $el = elevenHost($app, ['settings' => ['stability' => 0.3]]);
    $hope = hope($app);
    $app->hosts()->setLineup('program', (int) $program['id'], [['id' => $el['id'], 'role' => 'main'], ['id' => $hope['id'], 'role' => 'fallback']]);
    $id = breakNow($app);
    $http->answers = [
        openaiAnswer(['en' => ['text' => 'Welcome back.'], 'de' => ['text' => 'Willkommen zurück.'], 'delivery' => 'Glad and bright, with a smile.']),
        new HttpResponse(401, (string) json_encode(['detail' => ['status' => 'quota_exceeded', 'message' => 'This request exceeds your quota of 10000. You have 3 credits remaining.']])),
        new HttpResponse(200, mp3()),
        new HttpResponse(200, mp3()),
    ];
    runJobs($app);
    $hb = $app->hostBreaks()->get($id) ?? [];
    eq([$hb['state'], $hb['context']['host_id'] ?? null, array_keys($hb['audio'])], ['ready', $hope['id'], ['en', 'de']], 'voiced by Hope on OpenAI');
    eq(count($http->sent), 4, 'one script, one refusal, two clips — no second script');
    $el11 = $http->sent[1];
    eq([$el11['url'], $el11['headers']['xi-api-key'] ?? ''], ['https://api.elevenlabs.io/v1/text-to-speech/' . EL_VOICE . '?output_format=mp3_44100_128', EL_KEY], 'ElevenLabs with the host\'s voice and key');
    // JSON numbers: 0.0 and 1.0 travel as 0 and 1.
    eq($http->body(1), ['text' => 'Welcome back.', 'model_id' => 'eleven_flash_v2_5', 'voice_settings' => ['stability' => 0.3, 'similarity_boost' => 0.75, 'style' => 0, 'use_speaker_boost' => true, 'speed' => 1], 'language_code' => 'en'], 'its model, settings and the language');
    eq([$http->sent[2]['url'], $http->sent[2]['headers']['Authorization'] ?? ''], ['https://api.openai.com/v1/audio/speech', 'Bearer sk-station-0123456789'], 'OpenAI with the station\'s key: Hope has none of her own');
    eq($http->body(2), ['model' => 'gpt-4o-mini-tts', 'voice' => 'coral', 'input' => 'Welcome back.', 'response_format' => 'mp3',
        'instructions' => 'Speak natural English. Warm and calm, like a Christian radio host. Glad and bright, with a smile.'], 'the voice, its direction, and how this moment sounds — kept for the next host');
    check(str_starts_with((string) $http->body(3)['instructions'], 'Sprich natürliches Deutsch.') && str_ends_with((string) $http->body(3)['instructions'], 'Glad and bright, with a smile.'), 'German told as German, in the same mood');
    $after = $app->hosts()->get($el['id']) ?? [];
    eq($after['resting_until'], (intdiv($app->clock->now(), 86400) + 1) * 86400, 'ElevenLabs rests until the next UTC day');
    check(str_contains($after['last_error'], 'quota_exceeded') && !str_contains($after['last_error'], 'cdef'), 'with the reason, never the key');
    eq($app->hostBreaks()->payload($hb)['host'], ['name' => 'Hope', 'avatar' => null, 'color' => '#2f7bff', 'about' => ['en' => '', 'de' => ''], 'voice' => 'openai'], 'listeners see who spoke it');
    eq((int) $app->store()->value('SELECT input_tokens FROM ai_usage WHERE kind = ?', [Hosts::usageKind($hope['id'])]), mb_strlen('Welcome back.Willkommen zurück.'), 'Hope\'s characters counted as hers');
});

test('hosts: a fallback of another name gets the words written again for them first', function () {
    $app = TestKit::app(['OPENAI_KEY' => 'sk-station-0123456789'] + LIVE_NO_KEYS);
    $http = new FakeHttp();
    $app->set('http', $http);
    [$ch, $program] = onAir($app);
    $el = elevenHost($app);
    $david = makeHost($app, ['name' => 'David', 'about_en' => 'Mornings and your requests.', 'style' => 'Short and cheerful.']);
    $app->hosts()->setLineup('program', (int) $program['id'], [['id' => $el['id'], 'role' => 'main'], ['id' => $david['id'], 'role' => 'fallback']]);
    $id = breakNow($app);
    $http->answers = [
        openaiAnswer(['en' => ['text' => 'This is Hope.'], 'de' => ['text' => 'Hier ist Hope.']]),
        new HttpResponse(503, '{"detail":{"status":"system_busy"}}'),
        openaiAnswer(['en' => ['text' => 'This is David.'], 'de' => ['text' => 'Hier ist David.']]),
        new HttpResponse(200, mp3()),
        new HttpResponse(200, mp3()),
    ];
    runJobs($app);
    $hb = $app->hostBreaks()->get($id) ?? [];
    eq([$hb['state'], $hb['context']['host_id'] ?? null, $hb['texts'], $hb['context']['host_name'] ?? null], ['ready', $david['id'], ['en' => 'This is David.', 'de' => 'Hier ist David.'], 'David'], 'written and voiced for David');
    $system = (string) $http->body(2)['messages'][0]['content'];
    check(str_contains($system, 'Who you are: David.') && str_contains($system, 'Mornings and your requests.') && str_contains($system, 'Short and cheerful.'), 'the second script knows who David is');
    check(strpos($system, 'You never pray.') < strpos($system, 'Who you are: David.'), 'the rules first, the persona last (one cached prompt for every host)');
    eq([$app->hosts()->get($el['id'])['fail_count'], $app->hosts()->get($el['id'])['resting_until']], [1, 0], 'one busy answer is counted, not yet a rest');
});

test('hosts: temporary errors with nobody else are retried as before, and three in a row rest the host; the tick\'s own time running out is never the host\'s fault', function () {
    $app = TestKit::app(['OPENAI_KEY' => 'sk-station-0123456789'] + LIVE_NO_KEYS);
    $http = new FakeHttp();
    $app->set('http', $http);
    $id = breakNow($app);
    $http->answers = [openaiAnswer(['en' => ['text' => 'Hello.'], 'de' => ['text' => 'Hallo.']]), ...array_fill(0, 3, new HttpResponse(500, '{"error":{"message":"busy"}}'))];
    runJobs($app);
    $hb = $app->hostBreaks()->get($id) ?? [];
    $hope = hope($app);
    eq([$hb['state'], $hb['source']], ['failed', 'skipped:no_voice'], 'retried, then nobody could');
    eq([$hope['fail_count'], $hope['resting_until']], [3, $app->clock->now() + 600], 'three in a row: ten minutes of rest');
    eq(count($http->sent), 4, 'one script, three tries');

    $app = TestKit::app(['OPENAI_KEY' => 'sk-station-0123456789'] + LIVE_NO_KEYS);
    $http = new FakeHttp();
    $app->set('http', $http);
    $id = breakNow($app);
    $http->answers = [openaiAnswer(['en' => ['text' => 'Hello.'], 'de' => ['text' => 'Hallo.']])];
    $app->runner()->runUntilBudget(1);
    $app->budget->restart(1);
    $http->answers = [new RuntimeException('HTTP request failed: Operation timed out')];
    check(refuses(fn() => $app->hostBreaks()->runPhase(['ref_id' => $id, 'phase' => 'tts:en'])) && $app->hostBreaks()->get($id)['state'] === 'pending', 'cut short by the tick: thrown for the next tick');
    eq(hope($app)['fail_count'], 0, 'and not counted against the host');
});

test('hosts: listeners see who speaks — the moment names its host, the schedule a program\'s, channels.json the first — and which voices may read what they send', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    [$ch, $program] = onAir($app);
    $app->hosts()->useRandom(fn(int $min, int $max): int => $min);
    $david = elevenHost($app, ['name' => 'David', 'color' => '#e0763a', 'about_en' => 'Mornings and your requests.', 'about_de' => 'Morgens und eure Wünsche.']);
    $app->hosts()->setLineup('program', (int) $program['id'], [['id' => $david['id'], 'role' => 'main'], ['id' => hope($app)['id'], 'role' => 'fallback']]);
    ticks($app, 30);
    $items = publishedHostItems($app);
    check($items !== [], 'host moments aired');
    eq(array_unique(array_map(fn($i) => $i['host']['name'] ?? null, $items)), ['David'], 'each names David');
    eq($items[0]['host'], ['name' => 'David', 'avatar' => null, 'color' => '#e0763a', 'about' => ['en' => 'Mornings and your requests.', 'de' => 'Morgens und eure Wünsche.'], 'voice' => 'elevenlabs'], 'as listeners see him');
    $slots = glob($app->publicPath('program/main/slots/*/*.json')) ?: [];
    $slot = json_decode((string) file_get_contents((string) end($slots)), true);
    eq($slot['programs'][$program['slug']]['voicedBy'], ['openai', 'elevenlabs'], 'the forms may tell: ElevenLabs reads out in this program');
    $day = json_decode((string) file_get_contents((string) (glob($app->publicPath('program/main/days/*.json')) ?: [])[1]), true);
    eq(array_column($day['programs'][$program['slug']]['hosts'], 'name'), ['David'], 'the schedule names its on-air hosts');
    $channels = json_decode((string) file_get_contents($app->publicPath('program/channels.json')), true);
    eq($channels['channels'][0]['host']['name'], 'Hope', 'channels.json: the channel\'s own first host');
});

test('hosts: "Try voice" speaks unsaved changes and counts them; an ElevenLabs host without a cap stays silent; the catalog never asks ElevenLabs in stub mode', function () {
    $app = TestKit::app();
    $admin = modHeaders($app);
    $hope = hope($app);
    [$st, $d] = call($app, 'POST', '/api/mod/hosts/try', ['host_id' => $hope['id'], 'lang' => 'de', 'text' => 'Hallo, hier ist Hope.', 'draft' => ['instructions' => 'Leise.', 'voices' => ['de' => 'marin']]], $admin);
    eq($st, 200, 'tried');
    check(strlen(base64_decode((string) $d['audio'])) > 1000 && $d['ms'] > 0, 'an MP3 comes back');
    eq([$d['voice'], $d['model']], ['marin', 'gpt-4o-mini-tts'], 'and which voice spoke it: the one being tried, not the saved one');
    eq($app->hosts()->usedToday($hope['id']), mb_strlen('Hallo, hier ist Hope.'), 'counted');
    eq(hope($app)['instructions'], 'Warm and calm, like a Christian radio host.', 'nothing saved');
    $free = makeHost($app, ['name' => 'Free', 'provider' => 'elevenlabs', 'api_key' => EL_KEY, 'voices' => ['en' => EL_VOICE]]);
    eq(call($app, 'POST', '/api/mod/hosts/try', ['host_id' => $free['id'], 'lang' => 'en', 'text' => 'Hello.'], $admin), [422, ['error' => 'host_no_room']], 'ElevenLabs needs a cap');
    eq(call($app, 'POST', '/api/mod/hosts/try', ['host_id' => $hope['id'], 'lang' => 'en', 'text' => str_repeat('x', 301)], $admin), [422, ['error' => 'host_try_text']], 'at most 300 characters');
    eq(call($app, 'POST', '/api/mod/hosts/try', ['host_id' => $hope['id'], 'text' => 'Hi.'], moderatorHeaders($app))[0], 403, 'admins only');
    [$st, $cat] = call($app, 'POST', '/api/mod/hosts/catalog', ['provider' => 'elevenlabs', 'host_id' => $free['id']], $admin);
    eq([$st, $cat['stub'] ?? false, $cat['voices']], [200, true, []], 'stub mode: ElevenLabs is not asked');
    [$st, $cat] = call($app, 'POST', '/api/mod/hosts/catalog', ['provider' => 'openai'], $admin);
    eq([$st, count($cat['voices'])], [200, 13], 'OpenAI\'s voices, marin and cedar among them');
    for ($i = 0; $i < 19; $i++) call($app, 'POST', '/api/mod/hosts/try', ['host_id' => $hope['id'], 'lang' => 'en', 'text' => 'Hi.'], $admin);
    eq(call($app, 'POST', '/api/mod/hosts/try', ['host_id' => $hope['id'], 'lang' => 'en', 'text' => 'Hi.'], $admin), [429, ['error' => 'rate_limited']], 'twenty an hour');
});

test('hosts: a moment written again for a deleted account\'s sake finds its host anew', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    [$ch, $program] = onAir($app);
    $at = $app->clock->nowMs() + 600_000;
    $app->drafter()->addHost($ch, $program, 'break', $at, ['program_id' => (int) $program['id'], 'block_start' => $at, 'block_end' => $at + 3_600_000]);
    $row = $app->store()->one("SELECT h.id FROM host_breaks h JOIN timeline_items t ON t.host_break_id = h.id WHERE t.state = 'draft' LIMIT 1");
    check($row !== null, 'a drafted moment');
    $id = (int) $row['id'];
    $hb = $app->hostBreaks()->get($id) ?? [];
    $app->store()->update('host_breaks', ['context' => json_encode(['host_id' => 77, 'tried' => [76]] + $hb['context'])], 'id = ?', [$id]);
    $new = $app->hostBreaks()->rewrite($id);
    check($new !== null, 'drafted afresh');
    $ctx = $app->hostBreaks()->get((int) $new)['context'] ?? [];
    check(!isset($ctx['host_id']) && !isset($ctx['tried']) && isset($ctx['show']), 'no host yet, the same show');
});

<?php
declare(strict_types=1);

use Arche\Host\HostBreaks;
use Arche\Http\Kernel;
use Arche\Http\Request;
use Arche\Schema;
use Arche\Workers\Computers;
use Arche\Workers\Profile;
use Arche\Workers\Tasks;

/**
 * Computers that work for the station (Workers\Computers, Workers\Tasks;
 * herde's protocol/PROTOCOL.md): our own Macs and machines lent by people we
 * trust, pulling work over HTTPS — their results finish the moment, the line,
 * the try; a clip that does not fit its words is spoken again, then the
 * moment goes to the next host; a computer gone quiet is a host that cannot
 * speak. Listeners' words reach our own computers only.
 */

const QWEN_VOICES = [['id' => 'Ryan', 'label' => 'Ryan'], ['id' => 'Sohee', 'label' => 'Sohee'], ['id' => 'Serena', 'label' => 'Serena']];

// --- protocol 1 (ARCHE's first voice worker) ---------------------------------------------------

/** What a protocol 1 worker reports with every poll. @return array<string,mixed> */
function qwenReport(array $more = []): array
{
    return $more + ['name' => 'Studio Mac', 'version' => 'arche-worker 1.0.0', 'engine' => ['model' => Computers::MODEL], 'voices' => QWEN_VOICES, 'languages' => ['en', 'de'], 'ready' => true];
}

/** One of our computers, added by an admin: its key. */
function newWorker(Arche\App $app, string $name = 'Studio Mac'): string
{
    return $app->computers()->create($name, 'test')['key'];
}

/** A protocol 1 poll, as ARCHE's first worker made it: it knows no profile, so it is given no speech. @return array{0:int,1:array<string,mixed>} */
function workerPollV1(Arche\App $app, string $key, array $more = []): array
{
    return call($app, 'POST', '/api/worker/poll', qwenReport($more), ['x-arche-worker-key' => $key]);
}

/**
 * A computer that polls, takes the most urgent speech it may, and says what
 * it is — once protocol 1's way, now herde's, in step with the station's
 * profile: these voices in English and German, or not ready.
 *
 * @return array{0:int,1:array<string,mixed>}
 */
function workerPoll(Arche\App $app, string $key, array $more = []): array
{
    $report = qwenReport($more);
    $voices = array_map(fn($v) => is_array($v) ? $v : ['id' => (string) $v, 'label' => (string) $v], $report['voices']);
    [$s, $d] = poll2($app, $key, ['state' => $report['ready'] ? 'ready' : 'loading', 'free' => ['tts' => 1]], herdeCaps($voices, $report['languages']));
    if ($s !== 200) return [$s, $d];
    foreach ($d['offers'] as $offer) {
        $lease = 'lease-' . bin2hex(random_bytes(8));
        if (claim2($app, $key, (int) $offer['task'], $lease)[0] !== 200) continue;
        $GLOBALS['workerLeases'][(int) $offer['task']] = $lease;
        $t = $app->workerTasks()->task((int) $offer['task']) ?? throw new LogicException('task vanished');
        // As protocol 1 handed a task over (Tasks::leaseV1).
        return [200, ['task' => ['id' => $t['id'], 'purpose' => $t['purpose'], 'text' => (string) ($t['input']['text'] ?? ''), 'lang' => $t['lang'], 'voice' => $t['voice'],
            'model' => (string) ($app->hosts()->get($t['host_id'])['model'] ?? '') ?: Computers::MODEL, 'instruct' => (string) ($t['input']['instruct'] ?? ''),
            'temperature' => (float) ($t['input']['temperature'] ?? 0.7), 'seed' => (int) ($t['input']['seed'] ?? 0), 'lease_until' => $t['lease_until']],
            'retry_after' => $d['retry_after']]];
    }
    return [200, ['task' => null, 'retry_after' => $d['retry_after']]];
}

/** The speech for a task workerPoll took, handed back as herde does (multipart). @return array{0:int,1:array<string,mixed>} */
function workerUpload(Arche\App $app, string $key, int $taskId, ?string $mp3 = null): array
{
    return result2($app, $key, $taskId, $GLOBALS['workerLeases'][$taskId] ?? 'never-claimed-lease', $mp3);
}

/** The seeded host, speaking with a computer's voice from now on (Ryan in English, Sohee in German). @return array<string,mixed> */
function workerHope(Arche\App $app): array
{
    return $app->hosts()->save(hope($app)['id'], ['provider' => 'worker'], 'test');
}

/** Polls and uploads every task a protocol 1 worker is given, at most $n. @return list<array<string,mixed>> the tasks */
function speakAll(Arche\App $app, string $key, int $n = 10, ?string $mp3 = null): array
{
    $done = [];
    for ($i = 0; $i < $n; $i++) {
        $task = workerPoll($app, $key)[1]['task'] ?? null;
        if ($task === null) break;
        workerUpload($app, $key, (int) $task['id'], $mp3);
        $done[] = $task;
    }
    return $done;
}

// --- protocol 2 (herde) ---------------------------------------------------------------------------

/** A herde worker's capabilities: Qwen's speech, these voices in English and German, set up as the station's profile asks. @return array<string,mixed> */
function herdeCaps(?array $voices = null, array $langs = ['en', 'de'], array $engines = [], ?array $setup = null): array
{
    $voices ??= QWEN_VOICES;
    return ['name' => 'Studio Mac', 'platform' => ['os' => 'darwin', 'arch' => 'arm64', 'accelerator' => 'Apple M1 Max'], 'engines' => [
        ['kind' => 'tts', 'model' => Computers::TTS, 'location' => 'local', 'max_chars' => 2000,
            'voices' => array_map(fn($v) => $v + ['langs' => $langs, 'instruct' => true], $voices), 'setup' => $setup ?? herdeSetup()],
        ...$engines,
    ]];
}

/** What herde's stub (the tests' computer) runs when it runs the station's profile: its caps' setup. @param array<string,array<string,string>> $voices own voices' recordings @return array<string,mixed> */
function herdeSetup(array $voices = [], array $more = []): array
{
    return $more + ['code' => Profile::CODE, 'backend' => 'stub', 'model' => '', 'clone_model' => '', 'dtype' => '', 'speech' => Profile::SPEECH,
        'voices' => $voices, 'checks' => [['voice' => 'Sohee', 'lang' => 'de', 'ok' => true, 'cps' => 13.0]]];
}

/** How a computer stands with the station's profile, as its poll reports it: in step, unless told otherwise. @return array<string,mixed> */
function profileReport(Arche\App $app, string $key, array $more = []): array
{
    return $more + ['hash' => $app->workerProfile()->hash(), 'state' => 'ok', 'verified_ago' => 60];
}

/** A protocol 2 poll; the caps go along unless `caps` is false; in step with the profile unless `profile` says otherwise (null: no report). @return array{0:int,1:array<string,mixed>} */
function poll2(Arche\App $app, string $key, array $more = [], ?array $caps = null, bool $sendCaps = true): array
{
    $caps ??= herdeCaps();
    $body = $more + ['protocol' => 2, 'version' => 'herde/0.5.0', 'kinds' => ['tts' => [1], 'text' => [1]], 'state' => 'ready',
        'caps_hash' => hash('sha256', (string) json_encode($caps)), 'running' => [], 'free' => ['tts' => 1, 'text' => 1], 'profile' => profileReport($app, $key)];
    if ($body['profile'] === null) unset($body['profile']);
    if ($sendCaps && !isset($more['caps'])) $body['caps'] = $caps;
    return call($app, 'POST', '/api/worker/v2/poll', $body, ['x-worker-key' => $key]);
}

/** @return array{0:int,1:array<string,mixed>} */
function claim2(Arche\App $app, string $key, int $taskId, string $lease): array
{
    [$s, $d] = call($app, 'POST', "/api/worker/v2/tasks/$taskId/claim", ['lease' => $lease], ['x-worker-key' => $key]);
    // As the computer reads it (a task's input is an object in the answer).
    return [$s, json_decode((string) json_encode($d), true)];
}

/** Speech handed back, multipart like herde's. @return array{0:int,1:array<string,mixed>} */
function result2(Arche\App $app, string $key, int $taskId, string $lease, ?string $mp3 = null): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'wk');
    file_put_contents($tmp, $mp3 ?? mp3());
    $req = new Request('POST', "/api/worker/v2/tasks/$taskId/result", [], ['x-worker-key' => $key], '', ['lease' => $lease, 'ms' => '3000'], ['audio' => ['tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK]], '10.0.0.9');
    $res = (new Kernel($app))->handle($req);
    return [$res->status, $res->data];
}

/** @return array{0:int,1:array<string,mixed>} */
function fail2(Arche\App $app, string $key, int $taskId, string $lease, string $reason, bool $retry = true): array
{
    return call($app, 'POST', "/api/worker/v2/tasks/$taskId/fail", ['lease' => $lease, 'reason' => $reason, 'retry' => $retry, 'error' => 'x'], ['x-worker-key' => $key]);
}

/** A computer that joined with an invite. @return array{0:string,1:int} its key and id */
function joined(Arche\App $app, string $trust, bool $live = false, string $name = "Peter's PC"): array
{
    [$s, $inv] = call($app, 'POST', '/api/mod/workers/invites', ['name' => $name, 'trust' => $trust], adminOf($app));
    eq($s, 200, 'an admin invites a computer');
    [$s, $j] = call($app, 'POST', '/api/worker/v2/join', ['code' => $inv['code'], 'name' => $name, 'version' => 'herde/0.2.0', 'platform' => ['os' => 'linux', 'arch' => 'x86_64']]);
    eq($s, 200, 'and it joins');
    if ($live) $app->computers()->update((int) $j['worker']['id'], ['live' => true], 'test');
    return [(string) $j['key'], (int) $j['worker']['id']];
}

/** A task straight into the queue (the queue's own rules, without a moment around it). */
function queued(Arche\App $app, string $purpose, string $privacy, string $lang = 'de', string $voice = 'Sohee', int $deadline = 0): int
{
    $host = ['id' => hope($app)['id'], 'model' => Computers::MODEL, 'voices' => ['en' => $voice, 'de' => $voice], 'settings' => [], 'instructions' => 'Warm.'];
    return $app->workerTasks()->request($purpose, 1, $host, $lang, 'Gleich hört ihr ein Lied über Hoffnung und Vertrauen.', $deadline, privacy: $privacy, due: $deadline > 0 ? $deadline + 30 : 0);
}

/** @return list<int> */
function offered(array $poll): array
{
    return array_map(fn($o) => (int) $o['task'], $poll['offers'] ?? []);
}

function herdeFixture(string $name): mixed
{
    $file = dirname(__DIR__) . "/herde-protocol/$name.json";
    if (!is_file($file)) throw new RuntimeException("herde fixture missing: $file");
    return json_decode((string) file_get_contents($file), true);
}

/**
 * Where an answer differs from the fixture's: the same keys, the same kinds
 * of values; each item of a list like one of the fixture's items (an offer
 * of speech, of text, with or without a deadline).
 *
 * @return list<string>
 */
function herdeDiff(mixed $actual, mixed $fixture, string $path): array
{
    $kind = fn($v) => is_int($v) || is_float($v) ? 'number' : (is_array($v) ? (array_is_list($v) && $v !== [] ? 'list' : 'map') : gettype($v));
    if (is_array($fixture) && $fixture !== [] && array_is_list($fixture)) {
        if (!is_array($actual) || !array_is_list($actual)) return ["$path is no list"];
        $out = [];
        foreach ($actual as $i => $item) {
            $best = null;
            foreach ($fixture as $candidate) {
                $d = herdeDiff($item, $candidate, "{$path}[$i]");
                if ($d === []) {
                    $best = [];
                    break;
                }
                if ($best === null || count($d) < count($best)) $best = $d;
            }
            array_push($out, ...($best ?? []));
        }
        return $out;
    }
    if (is_array($fixture) && !array_is_list($fixture)) {
        if (!is_array($actual) || ($actual !== [] && array_is_list($actual))) return ["$path is no object"];
        $out = [];
        if ($missing = array_diff(array_keys($fixture), array_keys($actual))) $out[] = "$path lacks " . implode(',', $missing);
        if ($extra = array_diff(array_keys($actual), array_keys($fixture))) $out[] = "$path has unexpected " . implode(',', $extra);
        foreach ($actual as $k => $v) if (array_key_exists($k, $fixture)) array_push($out, ...herdeDiff($v, $fixture[$k], "$path.$k"));
        return $out;
    }
    if ($fixture === [] || $fixture === null) return [];
    return $kind($actual) === $kind($fixture) ? [] : ["$path is a " . $kind($actual) . ', not a ' . $kind($fixture)];
}

// --- keys, invites, joining --------------------------------------------------------------------

test('workers: a computer\'s key is shown once and kept as an HMAC; a wrong key, a switched-off computer and moderators are turned away', function () {
    $app = TestKit::app();
    $admin = adminOf($app);
    [$s, $d] = call($app, 'POST', '/api/mod/workers', ['name' => 'Studio Mac'], $admin);
    eq($s, 200, 'an admin adds one of ours');
    $key = (string) $d['key'];
    check(strlen($key) === 64, 'with a long key, shown now');
    $row = $app->store()->one('SELECT key_mac, key_hint, trust FROM computers');
    check($row['key_mac'] !== $key && !str_contains((string) $row['key_mac'], $key), 'kept only as an HMAC');
    eq([$row['key_hint'], $row['trust']], [substr($key, -4), 'own'], 'its last four characters; ours');
    eq(call($app, 'GET', '/api/mod/workers', [], $admin)[1]['workers'][0]['key_hint'] ?? null, substr($key, -4), 'the list never shows more');
    check(!str_contains((string) json_encode(call($app, 'GET', '/api/mod/workers', [], $admin)[1]), $key), 'nor the key');

    eq(workerPollV1($app, str_repeat('0', 64)), [401, ['error' => 'bad_worker_key']], 'a wrong key is refused');
    eq(workerPollV1($app, $key)[0], 200, 'its own is not');
    eq(poll2($app, $key)[0], 200, 'the same key speaks protocol 2, in its own header');
    $id = (int) $d['worker']['id'];
    call($app, 'PATCH', "/api/mod/workers/$id", ['active' => false], $admin);
    eq(workerPollV1($app, $key), [403, ['error' => 'worker_inactive']], 'switched off');
    eq(poll2($app, $key), [403, ['error' => 'worker_inactive']], 'in either protocol');
    [, $rotated] = call($app, 'PATCH', "/api/mod/workers/$id", ['active' => true, 'rotate' => true], $admin);
    eq(workerPollV1($app, $key)[0], 401, 'a new key: the old one stops working');
    eq(workerPollV1($app, (string) $rotated['key'])[0], 200, 'the new one works');
    eq(call($app, 'GET', '/api/mod/workers', [], moderatorHeaders($app))[0], 403, 'computers are for admins');
    eq(call($app, 'DELETE', "/api/mod/workers/$id", [], $admin)[0], 200, 'removed');
    check(!str_contains((string) json_encode($app->store()->all('SELECT detail FROM audit')), $key), 'the audit never names a key');
});

test('workers: an invite is a one-time code — used once, gone after three days, wrong codes counted; trust comes from it', function () {
    $app = TestKit::app();
    $admin = adminOf($app);
    [$s, $inv] = call($app, 'POST', '/api/mod/workers/invites', ['name' => "Peter's PC", 'trust' => 'lender'], $admin);
    eq([$s, $inv['invite']['trust'], $inv['invite']['name']], [200, 'lender', "Peter's PC"], 'an invite for a lent computer');
    check((bool) preg_match('/^[0-9A-Z]{4}(-[0-9A-Z]{1,4}){6}$/', (string) $inv['code']), 'a code to read out: ' . $inv['code']);
    eq(array_column(call($app, 'GET', '/api/mod/workers', [], $admin)[1]['invites'], 'id'), [$inv['invite']['id']], 'listed until used');
    check(!str_contains((string) json_encode($app->store()->all('SELECT * FROM worker_invites')), str_replace('-', '', strtolower((string) $inv['code']))), 'kept only as an HMAC');
    $platform = ['os' => 'linux', 'arch' => 'x86_64'];
    // Typed by hand: lower case, spaces instead of dashes.
    $typed = strtolower(str_replace('-', ' ', (string) $inv['code']));
    [$s, $j] = call($app, 'POST', '/api/worker/v2/join', ['code' => $typed, 'name' => 'gaming-pc', 'version' => 'herde/0.2.0', 'platform' => $platform]);
    eq([$s, $j['worker']['trust'], $j['worker']['name'], $j['project']['protocol']], [200, 'lender', "Peter's PC", 2], 'joined as a lender, named as invited');
    eq(call($app, 'POST', '/api/worker/v2/join', ['code' => $inv['code'], 'name' => 'again', 'version' => 'x', 'platform' => $platform]), [401, ['error' => 'bad_code']], 'used once');
    eq(call($app, 'GET', '/api/mod/workers', [], $admin)[1]['invites'], [], 'and no longer listed');
    eq(poll2($app, (string) $j['key'])[0], 200, 'its key works');
    eq(workerPollV1($app, (string) $j['key']), [403, ['error' => 'worker_inactive']], 'but not in protocol 1, which knows no trust');

    [, $old] = call($app, 'POST', '/api/mod/workers/invites', ['name' => 'Late', 'trust' => 'own'], $admin);
    TestKit::clock($app)->advance(73 * 3_600_000);
    eq(call($app, 'POST', '/api/worker/v2/join', ['code' => $old['code'], 'name' => 'x', 'version' => 'x', 'platform' => $platform])[1], ['error' => 'bad_code'], 'expired after three days');
    [, $gone] = call($app, 'POST', '/api/mod/workers/invites', ['name' => 'Withdrawn', 'trust' => 'own'], $admin);
    eq(call($app, 'DELETE', "/api/mod/workers/invites/{$gone['invite']['id']}", [], $admin)[0], 200, 'an invite withdrawn');
    eq(call($app, 'POST', '/api/worker/v2/join', ['code' => $gone['code'], 'name' => 'x', 'version' => 'x', 'platform' => $platform])[0], 401, 'cannot be used');
    eq(call($app, 'POST', '/api/mod/workers/invites', ['name' => 'X', 'trust' => 'admin'], $admin)[0], 422, 'trust is ours or a lender\'s');
    // Two wrong codes above within this hour (expired, withdrawn); ten an hour from one address.
    $tries = [];
    for ($i = 0; $i < 8; $i++) $tries[] = call($app, 'POST', '/api/worker/v2/join', ['code' => str_repeat('ab12', 7), 'name' => 'x', 'version' => 'x', 'platform' => $platform])[0];
    eq(array_unique($tries), [401], 'wrong codes are refused');
    eq(call($app, 'POST', '/api/worker/v2/join', ['code' => str_repeat('cd34', 7), 'name' => 'x', 'version' => 'x', 'platform' => $platform])[0], 429, 'and counted per address');
    eq(call($app, 'POST', '/api/mod/workers/invites', ['name' => 'X', 'trust' => 'own'], moderatorHeaders($app))[0], 403, 'invites are for admins');
});

test('workers: the protocol\'s example exchanges (herde) — the station answers each in their shape', function () {
    $app = TestKit::app();
    $admin = adminOf($app);
    $answer = function (string $name, array $actual) {
        $f = herdeFixture($name);
        eq($actual[0], (int) $f['response']['status'], "$name: status");
        $body = json_decode((string) json_encode($actual[1]), true);
        // A station with a profile names it in every poll answer; most fixtures show a project without one.
        if (is_array($body) && !array_key_exists('profile', $f['response']['body'])) unset($body['profile']);
        eq(herdeDiff($body, $f['response']['body'], $name), [], "$name: the answer's shape");
    };
    $platform = ['os' => 'linux', 'arch' => 'x86_64'];
    [, $inv] = call($app, 'POST', '/api/mod/workers/invites', ['name' => "Peter's PC", 'trust' => 'own'], $admin);
    $answer('join.ok', call($app, 'POST', '/api/worker/v2/join', ['code' => $inv['code'], 'name' => "Peter's PC", 'version' => 'herde/0.2.0', 'platform' => $platform]));
    $answer('join.bad-code', call($app, 'POST', '/api/worker/v2/join', ['code' => 'AAAA-BBBB-CCCC-DDDD', 'name' => 'x', 'version' => 'x', 'platform' => $platform]));
    $key = $app->computers()->create('Studio Mac', 'test')['key'];
    $app->computers()->update((int) $app->store()->value("SELECT id FROM computers WHERE name = 'Studio Mac'"), [], 'test');

    // Work of every kind waiting: a live moment's two languages, a text task, a line.
    // Waiting with a time (a try's: its result is taken without a moment around it).
    $live = queued($app, 'try', 'private', 'de', 'Sohee', $app->clock->now() + 140);
    queued($app, 'break', 'private', 'en', 'Sohee', $app->clock->now() + 140);
    $app->store()->insert('worker_tasks', ['kind' => 'text', 'class' => 'background', 'purpose' => 'check', 'model' => 'gemma4-31b', 'size' => 3912,
        'input' => json_encode(['model' => 'gemma4-31b', 'messages' => [['role' => 'user', 'content' => '…']], 'max_tokens' => 1024]), 'created' => 1, 'updated' => 1]);
    queued($app, 'line', 'public', 'de', 'Sohee');
    $text = ['kind' => 'text', 'model' => 'gemma4-31b', 'location' => 'local', 'json_schema' => true, 'context' => 32768, 'max_tokens' => 4096, 'loaded' => true];
    $caps = herdeCaps(null, ['en', 'de'], [$text]);
    $answer('poll.first', poll2($app, $key, [], $caps));
    $answer('poll.need-caps', poll2($app, $key, ['caps_hash' => str_repeat('0', 64)], $caps, false));
    [, $c] = claim2($app, $key, $live, '4d8f0b2e-7c1a-4b5e-9f3d-2a6c8e1b0d47');
    $answer('claim.tts', [200, $c]);
    $answer('claim.gone', call($app, 'POST', "/api/worker/v2/tasks/$live/claim", ['lease' => 'another-lease-1234'], ['x-worker-key' => $key]));
    $answer('poll.busy', poll2($app, $key, ['running' => [['task' => $live, 'lease' => '4d8f0b2e-7c1a-4b5e-9f3d-2a6c8e1b0d47', 'progress' => 0.5], ['task' => 9999, 'lease' => 'a9c05e1f-3b7d-4c2a']], 'free' => ['tts' => 0, 'text' => 0]], $caps, false));
    $answer('poll.paused', poll2($app, $key, ['state' => 'paused', 'resume_in' => 3600, 'free' => ['tts' => 0, 'text' => 0]], $caps, false));
    $answer('poll.upgrade', poll2($app, $key, ['kinds' => ['tts' => [7]]], $caps, false));
    // The profile (Workers\Profile), with Faith in both languages as in the example.
    voiceUpload($app, 'Faith', 'de');
    voiceUpload($app, 'Faith', 'en', wav(5.0), 'That was My God Is Greater. Great to have you with us.');
    $profile = workerGet($app, $key, 'profile');
    $shown = json_decode((string) $profile->json, true);
    // The stub that the tests' computers run is admitted on a local stack only.
    unset($shown['tts']['backends']['stub']);
    $answer('profile', [$profile->status, $shown]);
    $unknown = workerGet($app, $key, 'files/' . str_repeat('c', 64));
    $answer('files.unknown', [$unknown->status, $unknown->data]);
    $inStep = capsWithOwn($app);
    $inStep['engines'][] = $text;
    $answer('poll.profile', poll2($app, $key, [], $inStep));
    $answer('poll.profile-syncing', poll2($app, $key, ['profile' => ['hash' => '', 'state' => 'syncing', 'verified_ago' => 0, 'detail' => 'fetching the Base model for its own voices']], $inStep, false));
    poll2($app, $key, [], $inStep, false); // in step again, for the takes below
    $answer('result.tts-retake', result2($app, $key, $live, '4d8f0b2e-7c1a-4b5e-9f3d-2a6c8e1b0d47', (string) file_get_contents(silentMp3(60))));
    claim2($app, $key, $live, 'second-take-lease-0001');
    $answer('result.tts', result2($app, $key, $live, 'second-take-lease-0001'));
    $answer('result.gone', result2($app, $key, $live, 'never-claimed-lease-01'));
    $line = queued($app, 'line', 'public');
    claim2($app, $key, $line, 'lease-for-the-line-01');
    $answer('fail.shutdown', fail2($app, $key, $line, 'lease-for-the-line-01', 'shutdown'));
    claim2($app, $key, $line, 'lease-for-the-line-02');
    $answer('fail.invalid', fail2($app, $key, $line, 'lease-for-the-line-02', 'invalid'));
    $answer('error.bad-key', poll2($app, str_repeat('f', 64)));
    $app->computers()->update((int) $app->store()->value("SELECT id FROM computers WHERE name = 'Studio Mac'"), ['active' => false], 'test');
    $answer('error.inactive', poll2($app, $key));
    for ($i = 0; $i < 10; $i++) call($app, 'POST', '/api/worker/v2/join', ['code' => str_repeat('zz99', 6), 'name' => 'x', 'version' => 'x', 'platform' => $platform]);
    $answer('error.rate-limited', call($app, 'POST', '/api/worker/v2/join', ['code' => str_repeat('zz98', 6), 'name' => 'x', 'version' => 'x', 'platform' => $platform]));
});

// --- privacy: listeners' words on our own computers only ---------------------------------------------

test('workers: a lender is offered public work only, and work on air only when an admin lets it — what names a listener never leaves our own', function () {
    $app = TestKit::app();
    workerHope($app);
    [$lender, $lid] = joined($app, 'lender');
    $own = newWorker($app);
    $private = queued($app, 'break', 'private', 'de', 'Sohee', $app->clock->now() + 300);
    $plain = queued($app, 'break', 'public', 'de', 'Sohee', $app->clock->now() + 300);
    $line = queued($app, 'line', 'public');
    $typed = queued($app, 'line', 'private');
    $try = queued($app, 'try', 'private', 'de', 'Sohee', $app->clock->now() + 120);
    eq(offered(poll2($app, $lender)[1]), [$line], 'a lender: the line the model wrote, nothing on air, nothing private');
    foreach ([$private, $plain, $typed, $try] as $t) eq(claim2($app, $lender, $t, 'lender-lease-' . $t . 'xx'), [409, ['error' => 'gone']], "and it cannot claim task $t either (the same answer as gone)");
    $app->computers()->update($lid, ['live' => true], 'test');
    eq(offered(poll2($app, $lender)[1]), [$plain, $line], 'let speak on air: the moment that names nobody too');
    eq(offered(poll2($app, $own)[1]), [$private, $plain, $try, $line, $typed], 'ours: everything, the most urgent first');
    eq(call($app, 'GET', '/api/mod/workers', [], adminOf($app))[1]['workers'][0]['trust'] ?? null, 'lender', '/mod says whose it is');
});

test('workers: what a moment says decides who may voice it — a request, a reading, who prays, a listener\'s words are private', function () {
    $app = TestKit::app();
    $hb = fn(string $kind, array $context, string $source = 'stub') => ['kind' => $kind, 'source' => $source, 'context' => $context];
    check(HostBreaks::isPublic($hb('break', ['host_id' => 1, 'delivery' => 'Warm.'])), 'a plain break is public');
    check(!HostBreaks::isPublic($hb('break', ['request' => ['name' => 'Anna', 'place' => 'Kiel']])), 'a moment announcing a request is not');
    check(!HostBreaks::isPublic($hb('break', ['previous_request' => ['name' => 'Anna']])), 'nor one reacting to the request before');
    check(!HostBreaks::isPublic($hb('break', ['community' => [['text' => 'Gott ist treu', 'name' => 'Ben']]])), 'nor one quoting a listener in the chat');
    check(!HostBreaks::isPublic($hb('reading', ['prayer_ids' => [4]], 'listener')), 'nor a prayer request read out');
    check(!HostBreaks::isPublic($hb('intro', ['opening_by' => 'Maria'])), 'nor a prayer hour\'s welcome that names who prays');
    check(!HostBreaks::isPublic($hb('opening', [], 'moderator')), 'nor a moderator\'s prayer');

    // A line the model wrote is public; one a moderator typed may say anything.
    TestKit::songs($app, 12);
    $host = workerHope($app);
    libraryMode($app, ['encourage']);
    noRefill($app);
    $typed = $app->lines()->add(['host_id' => $host['id'], 'kind' => 'encourage', 'texts' => ['en' => 'Pray with us.', 'de' => 'Betet mit uns.'], 'tags' => ['time' => 'any', 'mood' => 'calm']], 'test');
    $own = newWorker($app);
    runJobs($app, 3);
    eq(array_unique(array_column($app->workerTasks()->tasksFor('line', (int) $typed['id']), 'privacy')), ['private'], 'a typed line stays with our own computers');
    poll2($app, $own);
    $model = $app->store()->insert('host_lines', ['host_id' => $host['id'], 'kind' => 'encourage', 'texts' => json_encode(['en' => 'Look at the wall.', 'de' => 'Schau auf die Wand.']),
        'tags' => '{}', 'state' => 'recording', 'source' => 'model', 'chars' => 36, 'created' => 1, 'updated' => 1]);
    $app->lines()->queue();
    runJobs($app, 3);
    eq(array_unique(array_column($app->workerTasks()->tasksFor('line', $model), 'privacy')), ['public'], 'a written one may go to a lender');
    [$s, $d] = call($app, 'POST', '/api/mod/hosts/try', ['host_id' => $host['id'], 'lang' => 'de', 'text' => 'Willkommen.', 'draft' => []], adminOf($app));
    eq([$s, $app->workerTasks()->task((int) $d['task'])['privacy'] ?? null], [202, 'private'], 'a try may be anything typed in /mod: ours only');
});

test('workers: with only a lent computer online, a moment that names nobody is voiced by it — one that names a listener goes to the next host', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    [, $program] = onAir($app);
    $faith = workerHope($app);
    $joy = makeHost($app, ['name' => 'Joy', 'voices' => ['en' => 'coral']]);
    $app->hosts()->setLineup('program', (int) $program['id'], [['id' => $faith['id'], 'role' => 'main'], ['id' => $joy['id'], 'role' => 'fallback']]);
    [$lender, $lid] = joined($app, 'lender', true);
    poll2($app, $lender);
    check($app->hosts()->canSpeak($app->hosts()->get($faith['id']), 0, false) && !$app->hosts()->canSpeak($app->hosts()->get($faith['id'])), 'Faith can speak what is public, not what is private');
    $plain = breakNow($app);
    runJobs($app, 2);
    $tasks = $app->workerTasks()->tasksFor('break', $plain);
    eq([count($tasks), array_unique(array_column($tasks, 'privacy'))], [2, ['public']], 'a plain break is asked of the computers, as public work');
    $first = offered(poll2($app, $lender)[1])[0];
    claim2($app, $lender, $first, 'lender-lease-first-01');
    eq(result2($app, $lender, $first, 'lender-lease-first-01')[1], ['ok' => true], 'the lender speaks it');
    $second = offered(poll2($app, $lender)[1])[0];
    claim2($app, $lender, $second, 'lender-lease-second-1');
    result2($app, $lender, $second, 'lender-lease-second-1');
    $hb = $app->hostBreaks()->get($plain);
    eq([$hb['state'], $hb['context']['host_id']], ['ready', $faith['id']], 'and the moment is ready, in Faith\'s voice');

    // A prayer for what listeners sent: never a lender's.
    $prayer = breakNow($app, 'prayer');
    runJobs($app, 3);
    eq($app->hostBreaks()->get($prayer)['context']['host_id'], $joy['id'], 'the next host speaks it');
    eq($app->workerTasks()->tasksFor('break', $prayer), [], 'nothing of it asked of the computers');
    check($lid > 0, 'lender');
});

// --- offers, claims, leases ---------------------------------------------------------------------------

test('workers: offers match voice, language and model in SQL — no voices means no work, and no pile of other voices hides one', function () {
    $app = TestKit::app();
    workerHope($app);
    $key = newWorker($app);
    $mine = queued($app, 'break', 'private', 'de', 'Sohee', $app->clock->now() + 300);
    eq(offered(poll2($app, $key, [], herdeCaps([]))[1]), [], 'a computer that names no voices gets nothing (it once got everything)');
    eq(offered(poll2($app, $key, [], herdeCaps(null, ['en']))[1]), [], 'Sohee in English only: not this German task');
    for ($i = 0; $i < 60; $i++) queued($app, 'line', 'private', 'de', 'Vivian');
    eq(offered(poll2($app, $key)[1]), [$mine], 'sixty tasks for a voice it lacks hide nothing (a lease once looked at the first fifty)');
    $other = $app->workerTasks()->request('line', 9, ['id' => 1, 'model' => 'some-other-tts', 'voices' => ['de' => 'Sohee'], 'settings' => [], 'instructions' => ''], 'de', 'Hallo.');
    check(!in_array($other, offered(poll2($app, $key)[1]), true), 'nor a task for another model');
    eq(poll2($app, $key, ['free' => ['tts' => 0, 'text' => 0]])[1]['offers'], [], 'no free slot: nothing offered');
});

test('workers: a claim is one guarded update — two computers race, one wins; the same claim again is answered alike; too late or too many is gone', function () {
    $app = TestKit::app();
    workerHope($app);
    $a = newWorker($app, 'A');
    $b = newWorker($app, 'B');
    poll2($app, $a);
    poll2($app, $b);
    $t = queued($app, 'break', 'private', 'de', 'Sohee', $app->clock->now() + 300);
    [$s, $won] = claim2($app, $a, $t, 'lease-of-a-00000001');
    eq([$s, $won['task']['id'], $won['task']['class'], $won['task']['input']['voice'], $won['task']['lease_in']], [200, $t, 'live', 'Sohee', Tasks::LEASE_SECONDS], 'A takes it, with its input and its lease');
    check(str_contains((string) $won['task']['input']['text'], 'Hoffnung') && $won['task']['due_in'] === 330 && $won['task']['claim_in'] === 300, 'the words, and when they are due');
    eq(claim2($app, $b, $t, 'lease-of-b-00000001'), [409, ['error' => 'gone']], 'B finds it gone');
    eq(claim2($app, $a, $t, 'lease-of-a-00000001'), [200, $won], 'A asking again (its answer lost): the same');
    eq(claim2($app, $a, $t, 'lease-of-a-00000002')[0], 409, 'another token of A: gone while the first lease holds');
    eq(claim2($app, $a, $t, 'bad lease')[0], 400, 'a token must look like one');

    $late = queued($app, 'break', 'private', 'de', 'Sohee', $app->clock->now() + 10);
    TestKit::clock($app)->advance(11_000);
    eq(claim2($app, $b, $late, 'lease-of-b-00000002')[0], 409, 'past its claim-by time: nobody starts it');

    [$lender] = joined($app, 'lender');
    poll2($app, $lender);
    $l1 = queued($app, 'line', 'public');
    $l2 = queued($app, 'line', 'public');
    eq(claim2($app, $lender, $l1, 'lender-lease-00000001')[0], 200, 'a lender takes one');
    eq(claim2($app, $lender, $l2, 'lender-lease-00000002')[0], 409, 'one at a time');
});

test('workers: a lease lives while the polls list it — a long take stays, silence gives it back as a mishap, a shutdown counts nothing', function () {
    $app = TestKit::app();
    workerHope($app);
    $key = newWorker($app);
    poll2($app, $key);
    $t = queued($app, 'try', 'private');
    claim2($app, $key, $t, 'long-take-lease-0001');
    $changes = fn() => (int) $app->store()->value('SELECT total_changes()');
    for ($i = 0; $i < 16; $i++) {
        TestKit::clock($app)->advance(25_000);
        [, $p] = poll2($app, $key, ['running' => [['task' => $t, 'lease' => 'long-take-lease-0001', 'progress' => $i / 16]], 'free' => ['tts' => 0, 'text' => 0]], null, false);
        eq($p['cancel'], [], "after {$i}×25 s still its own");
        $app->workerTasks()->maintain();
    }
    eq($app->workerTasks()->task($t)['state'], 'leased', 'a take of 400 s stays leased (protocol 1 gave it back halfway)');
    eq(result2($app, $key, $t, 'long-take-lease-0001')[1], ['ok' => true], 'and its result is taken');

    $quiet = queued($app, 'try', 'private');
    claim2($app, $key, $quiet, 'quiet-lease-00000001');
    TestKit::clock($app)->advance(46_000);
    $app->workerTasks()->maintain();
    $row = $app->workerTasks()->task($quiet);
    eq([$row['state'], $row['failures'], $row['takes'], $row['input']['seed']], ['queued', 1, 0, 0], 'silence: back to the queue as a mishap, not a take, with the same seed');
    eq(result2($app, $key, $quiet, 'quiet-lease-00000001')[1], ['ok' => true], 'its late result is still taken: nobody claimed it since');

    $again = queued($app, 'try', 'private');
    claim2($app, $key, $again, 'first-lease-00000001');
    TestKit::clock($app)->advance(46_000);
    $other = newWorker($app, 'Other');
    poll2($app, $other);
    eq(claim2($app, $other, $again, 'other-lease-00000001')[0], 200, 'a lapsed lease can be claimed by another');
    eq(result2($app, $key, $again, 'first-lease-00000001')[0], 409, 'and the first one\'s late result is then gone');

    $stopped = queued($app, 'line', 'private');
    claim2($app, $key, $stopped, 'stop-lease-000000001');
    eq(fail2($app, $key, $stopped, 'stop-lease-000000001', 'shutdown')[1], ['ok' => true], 'stopping');
    $row = $app->workerTasks()->task($stopped);
    eq([$row['state'], $row['failures'], $row['takes']], ['queued', 0, 0], 'gives it back, nothing counted');
    check($changes() > 0, 'changes counted');
});

test('workers: cancelled work is named back; a result sent twice gets the same answer, one file, usage counted once; a retake is one take', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $host = workerHope($app);
    $key = newWorker($app);
    poll2($app, $key);
    $id = breakNow($app);
    runJobs($app, 2);
    [$en, $de] = array_column($app->workerTasks()->tasksFor('break', $id), 'id');
    claim2($app, $key, $en, 'cancel-lease-0000001');
    $app->hostBreaks()->cancel($id);
    [, $p] = poll2($app, $key, ['running' => [['task' => $en, 'lease' => 'cancel-lease-0000001']], 'free' => ['tts' => 0, 'text' => 0]], null, false);
    eq($p['cancel'], [['task' => $en, 'lease' => 'cancel-lease-0000001']], 'a plan change: named back for the computer to stop');
    eq(result2($app, $key, $en, 'cancel-lease-0000001')[0], 409, 'its result is turned away');
    eq(glob($app->publicPath('media/host') . '/*/' . $id . '-*') ?: [], [], 'no clip of it kept');
    eq($app->store()->value("SELECT COUNT(*) FROM worker_tasks WHERE ref_id = ? AND purpose = 'break' AND input != '{}'", [$id]), 0, 'and the words are gone from the tasks');
    check($de > 0, 'two languages');

    $line = queued($app, 'try', 'private');
    claim2($app, $key, $line, 'twice-lease-00000001');
    eq(result2($app, $key, $line, 'twice-lease-00000001', (string) file_get_contents(silentMp3(60)))[1], ['ok' => false, 'retake' => true], '60 s for one sentence: again');
    eq(result2($app, $key, $line, 'twice-lease-00000001', (string) file_get_contents(silentMp3(60)))[1], ['ok' => false, 'retake' => true], 'sent twice: the same answer');
    $row = $app->workerTasks()->task($line);
    eq([$row['state'], $row['takes'], $row['input']['seed']], ['queued', 1, 1], 'one take, another seed');
    claim2($app, $key, $line, 'twice-lease-00000002');
    $chars = $row['size'];
    $before = (int) $app->store()->value('SELECT COALESCE(SUM(input_tokens), 0) FROM ai_usage');
    eq(result2($app, $key, $line, 'twice-lease-00000002')[1], ['ok' => true], 'right this time');
    eq(result2($app, $key, $line, 'twice-lease-00000002')[1], ['ok' => true], 'sent again: the same');
    eq((int) $app->store()->value('SELECT COALESCE(SUM(input_tokens), 0) FROM ai_usage') - $before, $chars, 'its characters counted once');
    eq(is_file($app->config->dataDir . "/tries/$line.mp3"), true, 'and one file kept');
    check($host['id'] > 0, 'host');
});

test('workers: a host switch while a clip is on its way never leaves one moment in two voices', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    [, $program] = onAir($app);
    $faith = workerHope($app);
    $joy = makeHost($app, ['name' => 'Joy', 'voices' => ['en' => 'coral']]);
    $app->hosts()->setLineup('program', (int) $program['id'], [['id' => $faith['id'], 'role' => 'main'], ['id' => $joy['id'], 'role' => 'fallback']]);
    $key = newWorker($app);
    poll2($app, $key);
    $id = breakNow($app);
    runJobs($app, 2);
    [$en, $de] = array_column($app->workerTasks()->tasksFor('break', $id), 'id');
    claim2($app, $key, $en, 'race-lease-en-000001');
    claim2($app, $key, $de, 'race-lease-de-000001');
    // German cannot be spoken at all: the moment goes to Joy while the English clip is still on its way.
    fail2($app, $key, $de, 'race-lease-de-000001', 'engine', false);
    TestKit::clock($app)->advance(31_000);
    runJobs($app, 4);
    $hb = $app->hostBreaks()->get($id);
    eq([$hb['state'], $hb['context']['host_id']], ['ready', $joy['id']], 'Joy spoke the moment');
    $voiced = $hb['audio'];
    eq(result2($app, $key, $en, 'race-lease-en-000001')[0], 409, 'Faith\'s English clip arriving now is turned away');
    eq($app->hostBreaks()->get($id)['audio'], $voiced, 'and the moment keeps Joy\'s voice in both languages');
    check(!$app->hostBreaks()->voiced($id, 'en', '/media/host/x.mp3', 3000, $en), 'a clip of a round that is over is never merged');
});

test('workers: three failures in a row rest a computer for ten minutes — it is offered nothing and speaks for no host meanwhile', function () {
    $app = TestKit::app();
    $host = workerHope($app);
    $key = newWorker($app);
    poll2($app, $key);
    for ($i = 0; $i < 3; $i++) {
        $t = queued($app, 'line', 'private');
        claim2($app, $key, $t, "broken-lease-0000$i");
        fail2($app, $key, $t, "broken-lease-0000$i", 'engine');
    }
    queued($app, 'line', 'private');
    eq(poll2($app, $key)[1]['offers'], [], 'resting: nothing offered');
    $app->hosts()->refresh();
    check(!$app->hosts()->canSpeak($app->hosts()->get($host['id'])), 'and its host cannot speak');
    TestKit::clock($app)->advance(601_000);
    check(poll2($app, $key)[1]['offers'] !== [], 'ten minutes later: work again');
});

test('workers: an idle poll writes nothing — listeners share the PHP processes with every computer\'s polls', function () {
    $app = TestKit::app();
    workerHope($app);
    $key = newWorker($app);
    poll2($app, $key);
    $changes = fn() => (int) $app->store()->value('SELECT total_changes()');
    $before = $changes();
    TestKit::clock($app)->advance(10_000);
    [$s, $p] = poll2($app, $key, [], null, false);
    eq([$s, $p['offers'], $p['need_caps'], $p['retry_after']], [200, [], false, 4], 'nothing to do, asked again soon (a worker host is in use)');
    eq($changes(), $before, 'and not one row written');
    TestKit::clock($app)->advance(30_000);
    poll2($app, $key, [], null, false);
    check($changes() > $before, 'its last seen, every half minute');
    $mark = $changes();
    poll2($app, $key, ['state' => 'paused', 'resume_in' => 3600], null, false);
    $c = call($app, 'GET', '/api/mod/workers', [], adminOf($app))[1]['workers'][0];
    eq([$c['state'], $c['online'], $c['resume_at'] > 0], ['paused', false, true], 'paused by its owner: noted at once, and not online for the hosts');
    check($changes() > $mark, 'a change is written');
});

// --- protocol 1, as the Mac speaks it until it runs herde -------------------------------------------

test('workers: a worker host speaks only while a computer offering its voice is online and ready', function () {
    $app = TestKit::app();
    $host = workerHope($app);
    eq([$host['provider'], $host['voices']], ['worker', ['en' => 'Ryan', 'de' => 'Sohee']], 'a worker host with the voices chosen by ear');
    check(!$app->hosts()->canSpeak($host), 'no computer yet: it cannot speak');
    $key = newWorker($app);
    workerPoll($app, $key, ['voices' => [['id' => 'Serena', 'label' => 'Serena']]]);
    check(!$app->hosts()->canSpeak($app->hosts()->get($host['id'])), 'one without its voice does not count');
    workerPoll($app, $key);
    check($app->hosts()->canSpeak($app->hosts()->get($host['id'])), 'one with its voice does');
    workerPoll($app, $key, ['ready' => false]);
    $app->hosts()->refresh();
    check(!$app->hosts()->canSpeak($app->hosts()->get($host['id'])), 'only checking, or its model loading: it cannot');
    workerPoll($app, $key);
    TestKit::clock($app)->advance((Computers::ONLINE_SECONDS + 1) * 1000);
    $app->hosts()->refresh();
    check(!$app->hosts()->canSpeak($app->hosts()->get($host['id'])), 'quiet for a while: it cannot');
    $v = array_values(array_filter(call($app, 'GET', '/api/mod/hosts', [], adminOf($app))[1]['hosts'], fn($h) => $h['id'] === $host['id']))[0];
    eq([$v['worker_online'], $v['key_set'], $v['station_key']], [false, false, false], '/mod says so; no key involved');
});

test('workers: a moment in a worker host\'s voice is asked for every language at once; the results make it ready, and it airs', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    workerHope($app);
    $key = newWorker($app);
    workerPoll($app, $key);
    $id = breakNow($app);
    runJobs($app, 2);
    $hb = $app->hostBreaks()->get($id);
    eq($hb['state'], 'pending', 'written, waiting for its voice');
    $tasks = $app->workerTasks()->tasksFor('break', $id);
    eq(array_column($tasks, 'lang'), ['en', 'de'], 'both languages asked at once');
    eq([$tasks[0]['voice'], $tasks[1]['voice'], $tasks[0]['input']['temperature'], $tasks[0]['privacy'], $tasks[0]['class']], ['Ryan', 'Sohee', 0.7, 'public', 'live'], 'each in its voice; a plain break names nobody');
    eq($hb['context']['tasks'], array_column($tasks, 'id'), 'the round the moment waits for');
    $job = $app->store()->one("SELECT status, phase, lease_until FROM jobs WHERE type = 'host' AND ref_id = ?", [$id]);
    check($job['phase'] === 'wait' && (int) $job['lease_until'] > $app->clock->now(), 'the job waits, it does not spin');

    [$s, $first] = workerPoll($app, $key);
    eq([$s, $first['task']['lang'], $first['task']['text'] !== '', $first['task']['voice'], $first['task']['model']], [200, 'en', true, 'Ryan', Computers::MODEL], 'a computer in step with the profile is given the first');
    eq(workerUpload($app, $key, (int) $first['task']['id']), [200, ['ok' => true]], 'and uploads it');
    eq($app->hostBreaks()->get($id)['state'], 'pending', 'one language is not the moment');
    $second = workerPoll($app, $key)[1]['task'];
    workerUpload($app, $key, (int) $second['id']);
    $hb = $app->hostBreaks()->get($id);
    eq($hb['state'], 'ready', 'the last upload makes it ready');
    check(str_starts_with((string) $hb['audio']['en'], '/media/host/') && str_starts_with((string) $hb['audio']['de'], '/media/host/'), 'its clips where every clip goes');
    eq(workerUpload($app, $key, (int) $second['id'])[1], ['ok' => true], 'sent twice: fine');
    eq(workerPoll($app, $key)[1]['task'], null, 'nothing more to do');
    runJobs($app, 1);
    TestKit::clock($app)->advance(31_000);
    runJobs($app, 1);
    eq($app->store()->value("SELECT status FROM jobs WHERE type = 'host' AND ref_id = ?", [$id]), 'done', 'the job finds it ready');

    // Through the program: breaks of a worker host air with the computer's clips.
    ticks($app, 2);
    for ($i = 0; $i < 25; $i++) {
        speakAll($app, $key);
        ticks($app, 1);
    }
    $voiced = array_values(array_filter(publishedHostItems($app), fn($it) => $it['host']['name'] ?? '' === 'Hope'));
    check($voiced !== [] && array_filter($voiced, fn($it) => str_starts_with((string) ($it['audio']['de'] ?? ''), '/media/host/')) !== [], 'moments in the minute files with the computer\'s clips');
});

test('workers: a clip that does not fit its words is spoken again with another seed, then given up — and the moment goes to the next host', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    [, $program] = onAir($app);
    $hope = workerHope($app);
    $joy = makeHost($app, ['name' => 'Joy', 'voices' => ['en' => 'coral']]);
    $app->hosts()->setLineup('program', (int) $program['id'], [['id' => $hope['id'], 'role' => 'main'], ['id' => $joy['id'], 'role' => 'fallback']]);
    $key = newWorker($app);
    workerPoll($app, $key);
    $id = breakNow($app);
    runJobs($app, 2);
    $task = workerPoll($app, $key)[1]['task'];
    $long = (string) file_get_contents(silentMp3(30));
    eq(workerUpload($app, $key, (int) $task['id'], $long), [200, ['ok' => false, 'retake' => true]], '30 s for a short sentence: spoken again');
    $again = workerPoll($app, $key)[1]['task'];
    eq([$again['id'], $again['seed']], [$task['id'], 1], 'the same task, another seed');
    eq(workerUpload($app, $key, (int) $again['id'], $long)[1], ['ok' => false, 'retake' => true], 'wrong again');
    eq($app->workerTasks()->task((int) $task['id'])['state'], 'failed', 'given up after two takes');
    TestKit::clock($app)->advance(31_000);
    runJobs($app, 4);
    $hb = $app->hostBreaks()->get($id);
    eq([$hb['state'], $hb['context']['host_id']], ['ready', $joy['id']], 'the next host spoke it');
    eq($app->store()->value("SELECT COUNT(*) FROM worker_tasks WHERE purpose = 'break' AND ref_id = ? AND state IN ('queued', 'leased')", [$id]), 0, 'and nothing of it is left for the computers');
});

test('workers: a Mac gone quiet or a commit too near hands the moment on; a lease that runs out goes back to the queue', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    [, $program] = onAir($app);
    $hope = workerHope($app);
    $joy = makeHost($app, ['name' => 'Joy', 'voices' => ['en' => 'coral']]);
    $app->hosts()->setLineup('program', (int) $program['id'], [['id' => $hope['id'], 'role' => 'main'], ['id' => $joy['id'], 'role' => 'fallback']]);
    $key = newWorker($app);
    workerPoll($app, $key);

    // Leased, then the Mac fell asleep: back to the queue once its lease runs out.
    $id = breakNow($app);
    runJobs($app, 2);
    $task = workerPoll($app, $key)[1]['task'];
    TestKit::clock($app)->set((int) $task['lease_until'] * 1000 + 1000);
    $app->workerTasks()->maintain();
    $row = $app->workerTasks()->task((int) $task['id']);
    eq([$row['state'], $row['failures'], $row['takes']], ['queued', 1, 0], 'a lease that ran out: queued again, a mishap and not a take');

    // Nobody online and nobody holding a task: the next host at once.
    TestKit::clock($app)->advance((Computers::ONLINE_SECONDS + 1) * 1000);
    $app->hosts()->refresh();
    runJobs($app, 4);
    $hb = $app->hostBreaks()->get($id);
    eq([$hb['state'], $hb['context']['host_id']], ['ready', $joy['id']], 'the Mac quiet: the fallback spoke it');

    // Queued past its claim-by time (the commit near): cancelled, and handed on.
    eq([Tasks::deadlineFor(1_000_000_000_000), Tasks::dueFor(1_000_000_000_000)], [intdiv(1_000_000_000_000 - 300_000, 1000) - 30, intdiv(1_000_000_000_000 - 300_000, 1000)], 'started 30 s before the commit at the latest, useless at the commit');
    workerPoll($app, $key);
    $late = $app->hostBreaks()->create(TestKit::main($app), $program, 'break', ['est_start' => $app->clock->nowMs() + 6 * 60_000, 'block_start' => $app->clock->nowMs(), 'unit' => null]);
    runJobs($app, 2);
    TestKit::clock($app)->advance(5 * 60_000); // no slot in the plan: four minutes at most
    workerPoll($app, $key, ['voices' => [['id' => 'Serena', 'label' => 'Serena']]]); // online, but not for this voice
    $app->workerTasks()->maintain();
    eq(array_unique(array_column($app->workerTasks()->tasksFor('break', $late), 'state')), ['cancelled'], 'too late for its commit: cancelled');
    runJobs($app, 4);
    eq($app->hostBreaks()->get($late)['context']['host_id'], $joy['id'], 'and spoken by the next host');
});

test('workers: a cancelled or forgotten moment\'s tasks are turned away and lose their words; listeners\' words are read by our computers', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    workerHope($app);
    $key = newWorker($app);
    workerPoll($app, $key);
    $id = breakNow($app);
    runJobs($app, 2);
    $task = workerPoll($app, $key)[1]['task'];
    $app->hostBreaks()->cancel($id);
    eq(workerUpload($app, $key, (int) $task['id'])[0], 409, 'a plan change: the upload is turned away');
    eq($app->store()->value("SELECT COUNT(*) FROM worker_tasks WHERE ref_id = ? AND input != '{}'", [$id]), 0, 'and the words are gone from the tasks');

    $read = breakNow($app, 'reading');
    $app->store()->query("UPDATE host_breaks SET texts = ?, source = 'listener' WHERE id = ?", [json_encode(['de' => 'Bitte betet für meine Mutter, sie ist krank.']), $read]);
    $app->store()->query("UPDATE jobs SET phase = 'tts:de' WHERE type = 'host' AND ref_id = ?", [$read]);
    $app->store()->query("UPDATE host_breaks SET context = json_set(context, '$.host_id', ?) WHERE id = ?", [hope($app)['id'], $read]);
    runJobs($app, 1);
    $t = workerPoll($app, $key)[1]['task'];
    eq([$t['lang'], $t['text']], ['de', 'Bitte betet für meine Mutter, sie ist krank.'], 'a prayer request goes to our computer word for word');
    eq($app->workerTasks()->task((int) $t['id'])['privacy'], 'private', 'as private work');
    $app->hostBreaks()->forget([$read], []);
    eq($app->workerTasks()->task((int) $t['id'])['input'], [], 'an erased account\'s words leave the tasks, and what the voice was told about them');
});

test('workers: a worker host\'s Sprechtexte are recorded by the computers, against its monthly recording; one recorded by a lender waits for approval', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $host = workerHope($app);
    libraryMode($app, ['encourage']);
    noRefill($app);
    $app->lines()->setOptions($host['id'], ['live' => true], 'test');
    $line = $app->lines()->add(['host_id' => $host['id'], 'kind' => 'encourage', 'texts' => ['en' => 'Look at the prayer wall and pray for what moves you.', 'de' => 'Schau auf die Gebetswand und bete für das, was dich bewegt.'], 'tags' => ['time' => 'any', 'mood' => 'calm']], 'test');
    $key = newWorker($app);
    workerPoll($app, $key);
    runJobs($app, 3);
    $tasks = $app->workerTasks()->tasksFor('line', (int) $line['id']);
    eq(array_column($tasks, 'lang'), ['en', 'de'], 'both languages asked of the computers');
    check(str_ends_with((string) $tasks[0]['input']['instruct'], 'Calm and unhurried, softly warm.'), 'recorded in the mood it was written in');
    runJobs($app, 3);
    eq(count($app->workerTasks()->tasksFor('line', (int) $line['id'])), 2, 'and not asked twice');
    speakAll($app, $key);
    $row = $app->store()->one('SELECT state, audio, voice FROM host_lines WHERE id = ?', [(int) $line['id']]);
    eq($row['state'], 'active', 'recorded and on air');
    foreach (json_decode((string) $row['audio'], true) as $url) check(str_starts_with($url, '/media/lines/'), 'in the lines folder: ' . $url);
    eq($row['voice'], $app->lines()->signature($app->hosts()->get($host['id'])), 'with the worker voice\'s signature');
    $live = TestKit::app(['AI_MODE' => 'live', 'OPENAI_KEY' => 'sk-test']);
    $live->hosts()->save(hope($live)['id'], ['provider' => 'worker'], 'test');
    eq($row['voice'], $live->lines()->signature(hope($live)), 'the same as live: a computer is never stubbed');
    check($app->lines()->monthChars($host['id']) > 0 && $app->hosts()->usedToday($host['id']) === 0, 'counted as recording, not against the day');

    // Lent: a written line, recorded by a lender — a moderator approves it first.
    [$lender] = joined($app, 'lender');
    poll2($app, $lender);
    $written = $app->store()->insert('host_lines', ['host_id' => $host['id'], 'kind' => 'encourage', 'texts' => json_encode(['en' => 'Pray along.', 'de' => 'Betet mit.']),
        'tags' => '{}', 'state' => 'recording', 'source' => 'model', 'chars' => 21, 'created' => 1, 'updated' => 1]);
    $app->lines()->queue();
    runJobs($app, 3);
    eq(count($app->workerTasks()->tasksFor('line', $written)), 2, 'asked of the computers');
    foreach ($app->workerTasks()->tasksFor('line', $written) as $i => $t) {
        eq(claim2($app, $lender, $t['id'], "lender-line-lease-$i")[0], 200, 'the lender takes it');
        eq(result2($app, $lender, $t['id'], "lender-line-lease-$i")[1], ['ok' => true], 'and records it');
    }
    eq($app->store()->value('SELECT state FROM host_lines WHERE id = ?', [$written]), 'draft', 'a lender\'s recording waits for a moderator, though this host\'s lines go live');
});

test('workers: changing a line\'s words or removing it cancels what the computers were asked for it', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $host = workerHope($app);
    libraryMode($app, ['encourage']);
    noRefill($app);
    $line = $app->lines()->add(['host_id' => $host['id'], 'kind' => 'encourage', 'texts' => ['en' => 'Pray with us.', 'de' => 'Betet mit uns.'], 'tags' => ['time' => 'any', 'mood' => 'calm']], 'test');
    $key = newWorker($app);
    workerPoll($app, $key);
    runJobs($app, 3);
    $old = workerPoll($app, $key)[1]['task'];
    $app->lines()->update((int) $line['id'], ['texts' => ['en' => 'Pray with us tonight.', 'de' => 'Betet heute Abend mit uns.']], 'test');
    eq(workerUpload($app, $key, (int) $old['id'])[0], 409, 'a clip of the old words is turned away (it would have aired as the new ones)');
    runJobs($app, 3);
    check(in_array('Betet heute Abend mit uns.', array_column(array_column($app->workerTasks()->tasksFor('line', (int) $line['id']), 'input'), 'text'), true), 'the new words are asked for');
    $app->lines()->remove((int) $line['id'], 'test');
    eq($app->store()->value("SELECT COUNT(*) FROM worker_tasks WHERE purpose = 'line' AND ref_id = ? AND state IN ('queued', 'leased')", [(int) $line['id']]), 0, 'removed: nothing of it is spoken any more');
});

test('workers: "Try voice" for a worker host is asked of our computers; the editor fetches the clip', function () {
    $app = TestKit::app();
    $admin = adminOf($app);
    $host = workerHope($app);
    $body = ['host_id' => $host['id'], 'lang' => 'de', 'text' => 'Willkommen bei ARCHE.', 'draft' => []];
    eq(call($app, 'POST', '/api/mod/hosts/try', $body, $admin), [409, ['error' => 'no_worker']], 'no computer online');
    [$lender] = joined($app, 'lender', true);
    poll2($app, $lender);
    eq(call($app, 'POST', '/api/mod/hosts/try', $body, $admin)[0], 409, 'a lender does not count: a try may say anything');
    $key = newWorker($app);
    workerPoll($app, $key);
    [$s, $d] = call($app, 'POST', '/api/mod/hosts/try', array_replace($body, ['draft' => ['voices' => ['en' => 'Ryan', 'de' => 'Serena']]]), $admin);
    eq([$s, $d['provider'], $d['voice']], [202, 'worker', 'Serena'], 'asked of a computer, in the editor\'s unsaved voice');
    eq(call($app, 'GET', "/api/mod/hosts/try/{$d['task']}", [], $admin)[1], ['state' => 'queued'], 'not spoken yet');
    $t = workerPoll($app, $key)[1]['task'];
    eq([$t['id'], $t['voice']], [$d['task'], 'Serena'], 'the try is given out');
    workerUpload($app, $key, (int) $t['id']);
    [, $r] = call($app, 'GET', "/api/mod/hosts/try/{$d['task']}", [], $admin);
    eq([$r['state'], base64_decode((string) $r['audio']) === mp3()], ['done', true], 'the clip, for the editor to play');
    $cat = call($app, 'POST', '/api/mod/hosts/catalog', ['provider' => 'worker'], $admin)[1];
    eq([$cat['workers_online'], array_column($cat['voices'], 'id'), array_column($cat['models'], 'id')], [2, ['Ryan', 'Sohee', 'Serena'], [Computers::MODEL]], 'the editor lists what the computers offer, under the model hosts are saved with');
    eq(call($app, 'GET', '/api/mod/status', [], $admin)[1]['workers'], ['online' => 2, 'total' => 2, 'queued' => 0], '/mod Status counts them');
});

test('workers: their routes never run a tick; finished tasks lose their words after a day and go after two', function () {
    $app = TestKit::app();
    $before = $app->store()->get('last_tick');
    $key = newWorker($app);
    workerPoll($app, $key);
    poll2($app, $key);
    eq($app->store()->get('last_tick'), $before, 'a poll ran no tick');
    $host = workerHope($app);
    $id = $app->workerTasks()->request('try', 0, $host, 'en', 'A listener named Anna asks for prayer.');
    $text = $app->store()->insert('worker_tasks', ['kind' => 'text', 'purpose' => 'check', 'state' => 'done', 'input' => '{"messages":[{"role":"user","content":"Anna"}]}', 'result' => '{"content":"Anna"}', 'created' => 1, 'updated' => $app->clock->now()]);
    $app->store()->update('worker_tasks', ['state' => 'done'], 'id = ?', [$id]);
    TestKit::clock($app)->advance(25 * 3_600_000);
    $app->workerTasks()->maintain();
    eq([$app->workerTasks()->task($id)['input'], $app->workerTasks()->task($text)['input'], $app->workerTasks()->task($text)['result']], [[], [], ''], 'words gone after a day, an answer too');
    TestKit::clock($app)->advance(24 * 3_600_000);
    check($app->workerTasks()->purge(intdiv($app->clock->nowMs(), 1000) - 2 * 86400) >= 2, 'the rows after two');
});

test('workers: a protocol the station does not speak is told what it does', function () {
    $app = TestKit::app();
    $key = newWorker($app);
    eq(poll2($app, $key, ['protocol' => 3]), [426, ['error' => 'upgrade_required', 'supported' => ['protocol' => [2], 'kinds' => ['tts' => [1], 'text' => [1]]]]], 'another protocol');
    eq(poll2($app, $key, ['kinds' => ['stt' => [1]]])[0], 426, 'no kind it gives out');
    eq(poll2($app, $key, ['caps_hash' => 'nope'], null, false)[0], 400, 'a hash must look like one');
});

// --- the station's profile (Workers\Profile; herde's PROTOCOL.md, Profiles) -------------------------------

const FAITH_DE = 'Das war Mein Gott ist größer. Schön, dass ihr dabei seid.';

/** A mono 16-bit PCM WAV of $seconds: a soft tone, a recording to clone from. */
function wav(float $seconds = 4.0, int $rate = 16000): string
{
    $data = '';
    for ($i = 0, $n = (int) ($seconds * $rate); $i < $n; $i++) $data .= pack('v', (int) (3000 * sin($i / 8)) & 0xFFFF);
    return 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVE' . 'fmt ' . pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16) . 'data' . pack('V', strlen($data)) . $data;
}

/** A recording of one of our own voices, as an admin uploads it (multipart). @return array{0:int,1:array<string,mixed>} */
function voiceUpload(Arche\App $app, string $voice, string $lang, ?string $bytes = null, string $text = FAITH_DE, ?array $headers = null): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'wv');
    file_put_contents($tmp, $bytes ?? wav());
    $req = new Request('POST', '/api/mod/workers/voices', [], $headers ?? adminOf($app), '', ['voice' => $voice, 'lang' => $lang, 'text' => $text],
        ['audio' => ['tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK]], '10.0.0.9');
    $res = (new Kernel($app))->handle($req);
    return [$res->status, $res->data];
}

/** A GET a computer makes (its profile, a file): the response itself, for its bytes. */
function workerGet(Arche\App $app, string $key, string $path): Arche\Http\Response
{
    return (new Kernel($app))->handle(new Request('GET', "/api/worker/v2/$path", [], ['x-worker-key' => $key], ''));
}

/** The caps of a herde worker that runs the profile with our own voices' recordings. @return array<string,mixed> */
function capsWithOwn(Arche\App $app): array
{
    $voices = [];
    foreach ($app->workerProfile()->document()['tts']['voices'] as $v) foreach ($v['langs'] as $lang => $rec) $voices[$v['id']][$lang] = $rec['file'];
    return herdeCaps(array_merge(QWEN_VOICES, array_map(fn($id) => ['id' => $id, 'label' => "$id (own voice)"], array_keys($voices))), ['en', 'de'], [], herdeSetup($voices));
}

test('workers: every computer gets the station\'s profile — herde\'s code, the checkpoints, every take\'s settings, our own voices — named by its hash, which herde computes alike', function () {
    $app = TestKit::app();
    workerHope($app);
    eq(voiceUpload($app, 'Faith', 'de')[0], 200, 'an admin records Faith in German');
    $key = newWorker($app);
    $res = workerGet($app, $key, 'profile');
    $doc = $res->data;
    eq([$res->status, $doc['profile']], [200, hash('sha256', Profile::canonical(['tts' => $doc['tts']]))], 'the hash is of the rest, as canonical JSON');
    eq([$res->json, Profile::canonical(json_decode((string) $res->json, true))], [Profile::canonical($doc), $res->json], 'sent as the bytes the hash was taken of, canonical');
    check(str_contains((string) $res->json, '"top_p":1.0'), 'a number as herde reads it back: 1.0 stays 1.0');
    $tts = $doc['tts'];
    eq([$tts['model'], $tts['code'], $tts['dtypes'], $tts['speech']], [Computers::TTS, Profile::CODE, ['bf16'], Profile::SPEECH], 'herde\'s speech code, bf16, the settings');
    eq($tts['backends']['mlx'], Profile::BACKENDS['mlx'], 'the checkpoints, pinned');
    $faith = $tts['voices'][0];
    eq([$faith['id'], array_keys($faith['langs']), $faith['langs']['de']['bytes'], $faith['langs']['de']['text']], ['Faith', ['de'], strlen(wav()), FAITH_DE], 'our own voice, by its recording');
    eq($faith['langs']['de']['file'], hash('sha256', wav()), 'named by its SHA-256');
    eq(array_map(fn($t) => $t['voice'] . '/' . $t['lang'], $tts['checks']['takes']), ['Faith/de', 'Ryan/en', 'Sohee/de'], 'checks of our voice and of the presets our hosts speak with');
    $file = workerGet($app, $key, 'files/' . $faith['langs']['de']['file']);
    eq([$file->status, (string) file_get_contents((string) $file->file)], [200, wav()], 'the recording, as uploaded');
    eq(workerGet($app, $key, 'files/' . str_repeat('a', 64))->status, 404, 'no other file');
    eq(poll2($app, $key)[1]['profile'], $doc['profile'], 'every poll answer names it');

    // herde's own example, hashed in Python: the station hashes it alike.
    $f = herdeFixture('profile')['response']['body'];
    eq(hash('sha256', Profile::canonical(['tts' => $f['tts']])), $f['profile'], 'one hash on both sides, byte for byte');

    // A lent computer is in the herd as ours is: the same profile, Faith too (what it may hear is another rule).
    [$lender] = joined($app, 'lender', true);
    $lent = workerGet($app, $lender, 'profile');
    eq([$lent->data['profile'], $lent->json], [$doc['profile'], $res->json], 'a lent computer gets the very same profile');
    eq(workerGet($app, $lender, 'files/' . $faith['langs']['de']['file'])->status, 200, 'and Faith\'s recording');
});

test('workers: speech only from a computer in step — the current profile, verified lately, its checks passed, and a setup the station finds to be the profile\'s', function () {
    $app = TestKit::app();
    workerHope($app);
    voiceUpload($app, 'Faith', 'de');
    $key = newWorker($app);
    $id = (int) $app->store()->value('SELECT id FROM computers');
    $speech = queued($app, 'line', 'private');
    $app->store()->insert('worker_tasks', ['kind' => 'text', 'class' => 'background', 'purpose' => 'check', 'model' => 'gemma4-31b', 'size' => 100,
        'input' => json_encode(['model' => 'gemma4-31b', 'messages' => [['role' => 'user', 'content' => '…']], 'max_tokens' => 64]), 'created' => 1, 'updated' => 1]);
    $text = ['kind' => 'text', 'model' => 'gemma4-31b', 'location' => 'local', 'json_schema' => true, 'context' => 8192, 'max_tokens' => 4096, 'loaded' => true];
    $caps = capsWithOwn($app);
    $caps['engines'][] = $text;
    $standing = function (array $more, ?array $caps2 = null) use ($app, $key, $id, $caps): array {
        $answer = poll2($app, $key, $more, $caps2 ?? $caps)[1];
        $c = $app->computers()->get($id);
        return [$c['profile_state'], $c['profile_detail'], array_map(fn($o) => $o['kind'], $answer['offers'])];
    };
    eq($standing(['profile' => null]), ['old', '', ['text']], 'a herde that knows no profile: no speech (text is no voice)');
    eq($standing(['profile' => profileReport($app, $key, ['state' => 'syncing', 'detail' => 'fetching the Base model'])]), ['syncing', 'fetching the Base model', ['text']], 'still fetching');
    eq($standing(['profile' => profileReport($app, $key, ['hash' => str_repeat('0', 64)])])[0], 'syncing', 'ok with an old profile is behind');
    eq($standing(['profile' => profileReport($app, $key, ['state' => 'unmet', 'detail' => 'this worker runs in fp16'])])[0], 'unmet', 'it cannot run it');
    eq($standing(['profile' => profileReport($app, $key, ['state' => 'failing', 'detail' => '1 of 3 checks failed'])])[1], '1 of 3 checks failed', 'its checks failed, and why');
    eq($standing(['profile' => profileReport($app, $key, ['verified_ago' => Profile::VERIFIED_WITHIN + 1])])[0], 'stale', 'not verified for too long');
    $slow = $caps;
    $slow['engines'][0]['setup']['speech']['chunk_chars'] = 400;
    eq(array_slice($standing([], $slow), 0, 2), ['differs', 'chunk_chars'], 'its word is not taken for it: the station compares the setup');
    $other = $caps;
    $other['engines'][0]['setup']['voices']['Faith']['de'] = str_repeat('b', 64);
    eq(array_slice($standing([], $other), 0, 2), ['differs', 'recordings'], 'another recording of Faith is another Faith');
    eq(array_slice($standing([], herdeCaps(null, ['en', 'de'], [], herdeSetup([], ['backend' => 'mlx', 'model' => Profile::BACKENDS['mlx']['model'], 'clone_model' => '', 'dtype' => 'fp16']))), 0, 2),
        ['differs', 'Base checkpoint none, number format fp16, recordings'], 'its Base model missing, fp16');
    check(!$app->computers()->online('Sohee', Computers::TTS, false, 'de'), 'out of step, no host can speak with it');
    eq($standing([]), ['ok', '', ['tts', 'text']], 'in step: speech too');
    check($app->computers()->online('Faith', Computers::TTS, false, 'de'), 'and a host can speak with Faith');
    $w = call($app, 'GET', '/api/mod/workers', [], adminOf($app))[1];
    eq([$w['workers'][0]['profile']['state'], $w['workers'][0]['setup']->backend ?? null], ['ok', 'stub'], 'admins see where it stands, and what it runs');

    // A new take of Faith: nobody is in step with the old one any more, not even until its next poll.
    voiceUpload($app, 'Faith', 'de', wav(5.0));
    eq($app->computers()->get($id)['profile_state'], 'syncing', 'every computer fetches it first');
    eq(claim2($app, $key, $speech, 'lease-before-it-polled')[0], 409, 'and takes no speech by its id meanwhile');
    eq($standing(['profile' => profileReport($app, $key)], capsWithOwn($app))[0], 'ok', 'until it runs the new one');
    $standing(['profile' => profileReport($app, $key, ['state' => 'failing', 'detail' => 'Faith (de): silent'])], capsWithOwn($app));
    eq(array_column($app->store()->all("SELECT detail FROM audit WHERE event = 'Worker out of step'"), 'detail'), ["$id Studio Mac: failing (Faith (de): silent)"],
        'a computer falling out of step is in the audit, with why');
});

test('workers: our own voices — a recording is checked as herde checks it, kept by its hash, one voice one spelling; admins only', function () {
    $app = TestKit::app();
    eq(voiceUpload($app, 'Faith', 'de', 'not a wav')[1], ['error' => 'voice_file'], 'no WAV');
    eq(voiceUpload($app, 'Faith', 'de', wav(2.0))[1], ['error' => 'voice_length'], 'too short to carry a voice');
    eq(voiceUpload($app, 'Faith', 'de', wav(31.0))[1], ['error' => 'voice_length'], 'too long: every take reads all of it');
    eq(voiceUpload($app, 'Sohee', 'de')[1], ['error' => 'voice_name'], 'a preset\'s name would be two voices behind one id');
    eq(voiceUpload($app, 'Faith Hope', 'de')[1], ['error' => 'voice_name'], 'an id as the protocol allows it');
    eq(voiceUpload($app, 'Faith', 'tlh')[1], ['error' => 'voice_lang'], 'a language Qwen speaks');
    eq(voiceUpload($app, 'Faith', 'de', null, " \n ")[1], ['error' => 'voice_text'], 'exactly what it says');
    eq(voiceUpload($app, 'Faith', 'de', null, FAITH_DE, moderatorHeaders($app))[0], 403, 'admins only');
    [$s, $d] = voiceUpload($app, 'Faith', 'de', null, "Das war Mein Gott ist größer.\n  Schön, dass ihr dabei seid.");
    eq([$s, $d['profile']['voices'][0]['langs'][0]['text'], $d['profile']['voices'][0]['langs'][0]['seconds']], [200, FAITH_DE, 4.0], 'its words on one line, its length');
    $first = hash('sha256', wav());
    $dir = $app->config->dataDir . '/voices';
    check(is_file("$dir/$first.wav"), 'kept by its SHA-256, in the data folder');
    eq(array_column(voiceUpload($app, 'faith', 'en', wav(6.0))[1]['profile']['voices'], 'id'), ['Faith'], 'another language of "faith" is Faith\'s');
    voiceUpload($app, 'Faith', 'de', wav(5.0));
    check(!is_file("$dir/$first.wav") && is_file("$dir/" . hash('sha256', wav(5.0)) . '.wav'), 'a new take replaces the old file');
    $d = call($app, 'DELETE', '/api/mod/workers/voices/Faith/de', [], adminOf($app))[1];
    eq(array_column($d['profile']['voices'][0]['langs'], 'lang'), ['en'], 'one language removed');
    call($app, 'DELETE', '/api/mod/workers/voices/Faith/en', [], adminOf($app));
    eq([call($app, 'GET', '/api/mod/workers', [], adminOf($app))[1]['profile']['voices'], glob("$dir/*.wav")], [[], []], 'the last one takes the voice and its files along');
    eq(call($app, 'DELETE', '/api/mod/workers/voices/Faith/en', [], adminOf($app))[0], 404, 'nothing left to remove');
    eq(array_column($app->store()->all("SELECT event FROM audit WHERE event LIKE 'Worker voice%' ORDER BY id"), 'event'),
        ['Worker voice recorded', 'Worker voice recorded', 'Worker voice recorded', 'Worker voice removed', 'Worker voice removed'], 'every change in the audit');
});

// --- migrations ---------------------------------------------------------------------------------------

test('workers: migration 17 keeps every computer as ours and every task as private, with their ids — and a replay keeps a lender a lender', function () {
    $app = TestKit::app();
    $store = $app->store();
    // The station as migration 16 left it: protocol 1 workers and their voice tasks.
    $store->set('schema', 16);
    $store->db->exec("CREATE TABLE workers (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, key_mac TEXT NOT NULL UNIQUE, key_hint TEXT NOT NULL DEFAULT '',
        active INTEGER NOT NULL DEFAULT 1, voices TEXT NOT NULL DEFAULT '[]', engine TEXT NOT NULL DEFAULT '{}', languages TEXT NOT NULL DEFAULT '[]',
        version TEXT NOT NULL DEFAULT '', last_seen INTEGER NOT NULL DEFAULT 0, created INTEGER NOT NULL, updated INTEGER NOT NULL)");
    $store->db->exec("CREATE TABLE voice_tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, purpose TEXT NOT NULL, ref_id INTEGER NOT NULL DEFAULT 0, host_id INTEGER NOT NULL,
        lang TEXT NOT NULL, text TEXT NOT NULL, request TEXT NOT NULL DEFAULT '{}', state TEXT NOT NULL DEFAULT 'queued', worker_id INTEGER,
        lease_until INTEGER NOT NULL DEFAULT 0, attempts INTEGER NOT NULL DEFAULT 0, priority INTEGER NOT NULL DEFAULT 50, deadline INTEGER NOT NULL DEFAULT 0,
        audio TEXT NOT NULL DEFAULT '', ms INTEGER NOT NULL DEFAULT 0, error TEXT NOT NULL DEFAULT '', created INTEGER NOT NULL, updated INTEGER NOT NULL)");
    $store->query('DELETE FROM computers');
    $store->query('DELETE FROM worker_tasks');
    $store->insert('workers', ['id' => 3, 'name' => 'Studio Mac', 'key_mac' => 'mac3', 'voices' => json_encode(QWEN_VOICES), 'languages' => '["en","de"]', 'version' => 'arche-worker 1.0.0', 'last_seen' => 5, 'created' => 1, 'updated' => 1]);
    $store->insert('workers', ['id' => 7, 'name' => 'Gone', 'key_mac' => 'mac7', 'created' => 1, 'updated' => 1]);
    $store->query('DELETE FROM workers WHERE id = 7');
    $store->insert('voice_tasks', ['id' => 41, 'purpose' => 'break', 'ref_id' => 9, 'host_id' => 1, 'lang' => 'de', 'text' => 'Hallo Anna.',
        'request' => json_encode(['model' => Computers::MODEL, 'voice' => 'Sohee', 'instruct' => 'Warm.', 'temperature' => 0.7, 'seed' => 1, 'signature' => 'x']),
        'state' => 'leased', 'worker_id' => 3, 'lease_until' => 99, 'attempts' => 1, 'priority' => 10, 'deadline' => 1000, 'created' => 1, 'updated' => 1]);
    Schema::migrate($store, $app->clock->nowMs());
    $c = $app->computers()->get(3);
    eq([$c['name'], $c['trust'], $c['protocol'], array_column(Computers::engines($c, 'tts')[0]['voices'], 'id')], ['Studio Mac', 'own', 1, ['Ryan', 'Sohee', 'Serena']], 'a worker became one of our computers, its voices kept');
    $t = $app->workerTasks()->task(41);
    eq([$t['kind'], $t['class'], $t['privacy'], $t['model'], $t['voice'], $t['state'], $t['worker_id'], $t['takes'], $t['due'], $t['input']['text'], $t['input']['seed'], $t['extra']],
        ['tts', 'live', 'private', Computers::TTS, 'Sohee', 'leased', 3, 1, 1030, 'Hallo Anna.', 1, ['signature' => 'x']], 'a waiting moment\'s task kept with its id, as private');
    eq($store->value("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('workers', 'voice_tasks')"), 0, 'the old tables are gone');
    check($app->computers()->create('Next', 'test')['worker']['id'] > 7, 'a removed worker\'s id never comes back');

    [, $lid] = joined($app, 'lender');
    $public = queued($app, 'line', 'public');
    foreach ([14, 16] as $from) {
        $store->set('schema', $from);
        Schema::migrate($store, $app->clock->nowMs());
        eq([$app->computers()->get($lid)['trust'] ?? null, $app->workerTasks()->task($public)['privacy'] ?? null, $app->workerTasks()->task(41)['state'] ?? null],
            ['lender', 'public', 'leased'], "replayed from $from: a lender stays a lender, public work public, nothing lost");
    }
});

test('workers: migration 15 keeps every host, lineup, line and option, and a deleted host\'s id never comes back', function () {
    $app = TestKit::app();
    [, $program] = onAir($app);
    $a = makeHost($app, ['name' => 'Alma', 'voices' => ['en' => 'ash']]);
    $b = makeHost($app, ['name' => 'Ben', 'voices' => ['en' => 'onyx']]);
    $app->hosts()->delete($b['id'], 'test');
    $app->hosts()->setLineup('program', (int) $program['id'], [['id' => $a['id'], 'role' => 'main']]);
    libraryMode($app, ['encourage']);
    $line = recordedLine($app, 'encourage');
    $app->lines()->setOptions($a['id'], ['rest_hours' => 12], 'test');
    $counts = fn() => array_map(fn($t) => (int) $app->store()->value("SELECT COUNT(*) FROM $t"), ['hosts', 'host_lineups', 'host_lines', 'host_line_options']);
    $before = $counts();
    foreach ([13, 14] as $from) {
        $app->store()->set('schema', $from);
        Schema::migrate($app->store(), $app->clock->nowMs());
        eq($counts(), $before, "replayed from $from: nothing lost");
    }
    eq($app->store()->value('SELECT state FROM host_lines WHERE id = ?', [(int) $line['id']]), 'active', 'the line still on air');
    $c = makeHost($app, ['name' => 'Cleo', 'voices' => ['en' => 'sage']]);
    check($c['id'] > $b['id'], 'a new host never gets a deleted one\'s id');
    eq(makeHost($app, ['name' => 'Wren', 'provider' => 'worker'])['provider'], 'worker', 'and a worker host can be saved');
});

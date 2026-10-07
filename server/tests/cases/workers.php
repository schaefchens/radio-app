<?php
declare(strict_types=1);

use Arche\Host\Workers;
use Arche\Http\Kernel;
use Arche\Http\Request;
use Arche\Schema;

/**
 * Voice workers (Host\Workers): Macs of our own that speak for hosts with
 * Qwen3-TTS, pulling voice tasks over HTTPS — their uploads finish the
 * moment, the line, the try; a clip that does not fit its words is spoken
 * again, then the moment goes to the next host; a Mac gone quiet is a host
 * that cannot speak.
 */

const QWEN_VOICES = [['id' => 'Ryan', 'label' => 'Ryan'], ['id' => 'Sohee', 'label' => 'Sohee'], ['id' => 'Serena', 'label' => 'Serena']];

/** What a worker reports with every poll. @return array<string,mixed> */
function qwenReport(array $more = []): array
{
    return $more + ['name' => 'Studio Mac', 'version' => 'arche-worker 1.0.0', 'engine' => ['model' => Workers::MODEL], 'voices' => QWEN_VOICES, 'languages' => ['en', 'de'], 'ready' => true];
}

/** A worker added by an admin: its key. */
function newWorker(Arche\App $app, string $name = 'Studio Mac'): string
{
    return $app->workers()->create($name, 'test')['key'];
}

/** @return array{0:int,1:array<string,mixed>} */
function workerPoll(Arche\App $app, string $key, array $more = []): array
{
    return call($app, 'POST', '/api/worker/poll', qwenReport($more), ['x-arche-worker-key' => $key]);
}

/** A worker's upload, multipart like the real one. @return array{0:int,1:array<string,mixed>} */
function workerUpload(Arche\App $app, string $key, int $taskId, ?string $mp3 = null): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'wk');
    file_put_contents($tmp, $mp3 ?? mp3());
    $req = new Request('POST', "/api/worker/tasks/$taskId/audio", [], ['x-arche-worker-key' => $key], '', ['ms' => '3000'], ['audio' => ['tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK]], '10.0.0.9');
    $res = (new Kernel($app))->handle($req);
    return [$res->status, $res->data];
}

/** The seeded host, speaking with a worker's voice from now on (Ryan in English, Sohee in German). @return array<string,mixed> */
function workerHope(Arche\App $app): array
{
    return $app->hosts()->save(hope($app)['id'], ['provider' => 'worker'], 'test');
}

/** Polls and uploads every task a worker is given, at most $n. @return list<array<string,mixed>> the tasks */
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

test('workers: a worker\'s key is shown once and kept as an HMAC; a wrong key, a switched-off worker and moderators are turned away', function () {
    $app = TestKit::app();
    $admin = modHeaders($app);
    [$s, $d] = call($app, 'POST', '/api/mod/workers', ['name' => 'Studio Mac'], $admin);
    eq($s, 200, 'an admin adds a worker');
    $key = (string) $d['key'];
    check(strlen($key) === 64, 'with a long key, shown now');
    $row = $app->store()->one('SELECT key_mac, key_hint FROM workers');
    check($row['key_mac'] !== $key && !str_contains((string) $row['key_mac'], $key), 'kept only as an HMAC');
    eq($row['key_hint'], substr($key, -4), 'and its last four characters');
    eq(call($app, 'GET', '/api/mod/workers', [], $admin)[1]['workers'][0]['key_hint'] ?? null, substr($key, -4), 'the list never shows more');
    check(!str_contains((string) json_encode(call($app, 'GET', '/api/mod/workers', [], $admin)[1]), $key), 'nor the key');

    eq(workerPoll($app, str_repeat('0', 64)), [401, ['error' => 'bad_worker_key']], 'a wrong key is refused');
    eq(workerPoll($app, $key)[0], 200, 'its own is not');
    $id = (int) $d['worker']['id'];
    call($app, 'PATCH', "/api/mod/workers/$id", ['active' => false], $admin);
    eq(workerPoll($app, $key), [403, ['error' => 'worker_inactive']], 'switched off');
    [, $rotated] = call($app, 'PATCH', "/api/mod/workers/$id", ['active' => true, 'rotate' => true], $admin);
    eq(workerPoll($app, $key)[0], 401, 'a new key: the old one stops working');
    eq(workerPoll($app, (string) $rotated['key'])[0], 200, 'the new one works');
    eq(call($app, 'GET', '/api/mod/workers', [], moderatorHeaders($app))[0], 403, 'workers are for admins');
    eq(call($app, 'DELETE', "/api/mod/workers/$id", [], $admin)[0], 200, 'removed');
    check(!str_contains((string) json_encode($app->store()->all('SELECT detail FROM audit')), $key), 'the audit never names a key');
});

test('workers: a worker host speaks only while a worker offering its voice has polled lately', function () {
    $app = TestKit::app();
    $host = workerHope($app);
    eq([$host['provider'], $host['voices']], ['worker', ['en' => 'Ryan', 'de' => 'Sohee']], 'a worker host with the voices chosen by ear');
    check(!$app->hosts()->canSpeak($host), 'no worker yet: it cannot speak');
    $key = newWorker($app);
    workerPoll($app, $key, ['voices' => [['id' => 'Serena', 'label' => 'Serena']]]);
    check(!$app->hosts()->canSpeak($app->hosts()->get($host['id'])), 'a worker without its voice does not count');
    workerPoll($app, $key);
    check($app->hosts()->canSpeak($app->hosts()->get($host['id'])), 'one with its voice does');
    workerPoll($app, $key, ['ready' => false]);
    TestKit::clock($app)->advance((Workers::ONLINE_SECONDS + 1) * 1000);
    $app->hosts()->refresh();
    check(!$app->hosts()->canSpeak($app->hosts()->get($host['id'])), 'quiet for a while (or only checking, not ready): it cannot');
    $v = array_values(array_filter(call($app, 'GET', '/api/mod/hosts', [], modHeaders($app))[1]['hosts'], fn($h) => $h['id'] === $host['id']))[0];
    eq([$v['worker_online'], $v['key_set'], $v['station_key']], [false, false, false], '/mod says so; no key involved');
});

test('workers: a moment in a worker host\'s voice is asked of the workers for every language at once; the uploads make it ready, and it airs', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    workerHope($app);
    $key = newWorker($app);
    workerPoll($app, $key);
    $id = breakNow($app);
    runJobs($app, 2);
    $hb = $app->hostBreaks()->get($id);
    eq($hb['state'], 'pending', 'written, waiting for its voice');
    $tasks = $app->workers()->tasksFor('break', $id);
    eq(array_column($tasks, 'lang'), ['en', 'de'], 'both languages asked at once');
    eq([$tasks[0]['request']['voice'], $tasks[1]['request']['voice'], $tasks[0]['request']['temperature']], ['Ryan', 'Sohee', 0.7], 'each in its voice');
    $job = $app->store()->one("SELECT status, phase, lease_until FROM jobs WHERE type = 'host' AND ref_id = ?", [$id]);
    check($job['phase'] === 'wait' && (int) $job['lease_until'] > $app->clock->now(), 'the job waits, it does not spin');

    [$s, $first] = workerPoll($app, $key);
    eq([$s, $first['task']['lang'], $first['task']['text'] !== '', $first['task']['voice']], [200, 'en', true, 'Ryan'], 'a worker is given the first');
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
    eq($app->store()->value("SELECT status FROM jobs WHERE type = 'host' AND ref_id = ?", [$id]), 'running', 'the job looks again when its wait is over');
    TestKit::clock($app)->advance(31_000);
    runJobs($app, 1);
    eq($app->store()->value("SELECT status FROM jobs WHERE type = 'host' AND ref_id = ?", [$id]), 'done', 'and finds it ready');

    // Through the program: breaks of a worker host air with the worker's clips.
    ticks($app, 2);
    for ($i = 0; $i < 25; $i++) {
        speakAll($app, $key);
        ticks($app, 1);
    }
    $voiced = array_values(array_filter(publishedHostItems($app), fn($it) => $it['host']['name'] ?? '' === 'Hope'));
    check($voiced !== [] && array_filter($voiced, fn($it) => str_starts_with((string) ($it['audio']['de'] ?? ''), '/media/host/')) !== [], 'moments in the minute files with the worker\'s clips');
});

test('workers: a clip that does not fit its words is spoken again with another seed, then given up — and the moment goes to the next host', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    [$ch, $program] = onAir($app);
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
    eq($app->store()->value('SELECT state FROM voice_tasks WHERE id = ?', [(int) $task['id']]), 'failed', 'given up after two takes');
    TestKit::clock($app)->advance(31_000);
    runJobs($app, 4);
    $hb = $app->hostBreaks()->get($id);
    eq([$hb['state'], $hb['context']['host_id']], ['ready', $joy['id']], 'the next host spoke it');
    eq($app->store()->value("SELECT COUNT(*) FROM voice_tasks WHERE purpose = 'break' AND ref_id = ? AND state IN ('queued', 'leased')", [$id]), 0, 'and nothing of it is left for the worker');
    check($ch['id'] > 0, 'channel');
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
    $app->workers()->maintain();
    $row = $app->store()->one('SELECT state, attempts FROM voice_tasks WHERE id = ?', [(int) $task['id']]);
    eq([$row['state'], (int) $row['attempts']], ['queued', 1], 'a lease that ran out: queued again');
    eq(workerUpload($app, $key, (int) $task['id'])[0], 409, 'its late upload is turned away');

    // Nobody online and nobody holding a task: the next host at once.
    TestKit::clock($app)->advance((Workers::ONLINE_SECONDS + 1) * 1000);
    $app->hosts()->refresh();
    runJobs($app, 4);
    $hb = $app->hostBreaks()->get($id);
    eq([$hb['state'], $hb['context']['host_id']], ['ready', $joy['id']], 'the Mac quiet: the fallback spoke it');

    // Queued past its deadline (the commit near): cancelled, and handed on.
    eq(Workers::deadlineFor(1_000_000_000_000), intdiv(1_000_000_000_000 - 300_000, 1000) - 30, 'due 30 s before the commit comes for it');
    workerPoll($app, $key);
    $late = $app->hostBreaks()->create(TestKit::main($app), $program, 'break', ['est_start' => $app->clock->nowMs() + 6 * 60_000, 'block_start' => $app->clock->nowMs(), 'unit' => null]);
    runJobs($app, 2);
    TestKit::clock($app)->advance(5 * 60_000); // no slot in the plan: four minutes at most
    workerPoll($app, $key, ['voices' => [['id' => 'Serena', 'label' => 'Serena']]]); // online, but not for this voice
    $app->workers()->maintain();
    eq(array_unique(array_column($app->workers()->tasksFor('break', $late), 'state')), ['cancelled'], 'too late for its commit: cancelled');
    runJobs($app, 4);
    eq($app->hostBreaks()->get($late)['context']['host_id'], $joy['id'], 'and spoken by the next host');
});

test('workers: a cancelled or forgotten moment\'s tasks are turned away and lose their words; listeners\' words are read by a worker too', function () {
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
    eq($app->store()->value("SELECT COUNT(*) FROM voice_tasks WHERE ref_id = ? AND text != ''", [$id]), 0, 'and the words are gone from the tasks');
    eq(glob($app->publicPath('media/host') . '/*/' . $id . '-*') ?: [], [], 'no clip of it kept');

    $read = breakNow($app, 'reading');
    $app->store()->query("UPDATE host_breaks SET texts = ? WHERE id = ?", [json_encode(['de' => 'Bitte betet für meine Mutter, sie ist krank.']), $read]);
    $app->store()->query("UPDATE jobs SET phase = 'tts:de' WHERE type = 'host' AND ref_id = ?", [$read]);
    $app->store()->query("UPDATE host_breaks SET context = json_set(context, '$.host_id', ?) WHERE id = ?", [hope($app)['id'], $read]);
    runJobs($app, 1);
    $t = workerPoll($app, $key)[1]['task'];
    eq([$t['lang'], $t['text']], ['de', 'Bitte betet für meine Mutter, sie ist krank.'], 'a prayer request goes to the worker word for word');
    $app->hostBreaks()->forget([$read], []);
    eq($app->store()->value("SELECT text FROM voice_tasks WHERE id = ?", [(int) $t['id']]), '', 'an erased account\'s words leave the tasks');
    $told = fn(int $taskId) => json_decode((string) $app->store()->value('SELECT request FROM voice_tasks WHERE id = ?', [$taskId]), true)['instruct'] ?? null;
    eq([$told((int) $task['id']), $told((int) $t['id'])], ['', ''], 'and so does what the voice was told about them (a moment\'s delivery travels in the instruct)');
});

test('workers: a worker host\'s Sprechtexte are recorded by the workers, against its monthly recording — real recordings in every mode', function () {
    $app = TestKit::app();
    TestKit::songs($app, 12);
    $host = workerHope($app);
    libraryMode($app, ['encourage']);
    noRefill($app);
    $line = $app->lines()->add(['host_id' => $host['id'], 'kind' => 'encourage', 'texts' => ['en' => 'Look at the prayer wall and pray for what moves you.', 'de' => 'Schau auf die Gebetswand und bete für das, was dich bewegt.'], 'tags' => ['time' => 'any', 'mood' => 'calm']], 'test');
    $key = newWorker($app);
    workerPoll($app, $key);
    runJobs($app, 3);
    eq(array_column($app->workers()->tasksFor('line', (int) $line['id']), 'lang'), ['en', 'de'], 'both languages asked of the workers');
    check(str_ends_with((string) $app->workers()->tasksFor('line', (int) $line['id'])[0]['request']['instruct'], 'Calm and unhurried, softly warm.'), 'recorded in the mood it was written in');
    runJobs($app, 3);
    eq(count($app->workers()->tasksFor('line', (int) $line['id'])), 2, 'and not asked twice');
    speakAll($app, $key);
    $row = $app->store()->one('SELECT state, audio, voice FROM host_lines WHERE id = ?', [(int) $line['id']]);
    eq($row['state'], 'active', 'recorded and on air');
    foreach (json_decode((string) $row['audio'], true) as $url) check(str_starts_with($url, '/media/lines/'), 'in the lines folder: ' . $url);
    eq($row['voice'], $app->lines()->signature($app->hosts()->get($host['id'])), 'with the worker voice\'s signature');
    $live = TestKit::app(['AI_MODE' => 'live', 'OPENAI_KEY' => 'sk-test']);
    $live->hosts()->save(hope($live)['id'], ['provider' => 'worker'], 'test');
    eq($row['voice'], $live->lines()->signature(hope($live)), 'the same as live: a worker is never stubbed');
    check($app->lines()->monthChars($host['id']) > 0 && $app->hosts()->usedToday($host['id']) === 0, 'counted as recording, not against the day');
});

test('workers: "Try voice" for a worker host is asked of the workers; the editor fetches the clip', function () {
    $app = TestKit::app();
    $admin = modHeaders($app);
    $host = workerHope($app);
    $body = ['host_id' => $host['id'], 'lang' => 'de', 'text' => 'Willkommen bei ARCHE.', 'draft' => []];
    eq(call($app, 'POST', '/api/mod/hosts/try', $body, $admin), [409, ['error' => 'no_worker']], 'no worker online');
    $key = newWorker($app);
    workerPoll($app, $key);
    [$s, $d] = call($app, 'POST', '/api/mod/hosts/try', array_replace($body, ['draft' => ['voices' => ['en' => 'Ryan', 'de' => 'Serena']]]), $admin);
    eq([$s, $d['provider'], $d['voice']], [202, 'worker', 'Serena'], 'asked of a worker, in the editor\'s unsaved voice');
    eq(call($app, 'GET', "/api/mod/hosts/try/{$d['task']}", [], $admin)[1], ['state' => 'queued'], 'not spoken yet');
    $t = workerPoll($app, $key)[1]['task'];
    eq([$t['id'], $t['voice']], [$d['task'], 'Serena'], 'the try is given out');
    workerUpload($app, $key, (int) $t['id']);
    [, $r] = call($app, 'GET', "/api/mod/hosts/try/{$d['task']}", [], $admin);
    eq([$r['state'], base64_decode((string) $r['audio']) === mp3()], ['done', true], 'the clip, for the editor to play');
    $cat = call($app, 'POST', '/api/mod/hosts/catalog', ['provider' => 'worker'], $admin)[1];
    eq([$cat['workers_online'], array_column($cat['voices'], 'id'), array_column($cat['models'], 'id')], [1, ['Ryan', 'Sohee', 'Serena'], [Workers::MODEL]], 'the editor lists what the workers offer');
    eq(call($app, 'GET', '/api/mod/status', [], $admin)[1]['workers'], ['online' => 1, 'total' => 1, 'queued' => 0], '/mod Status counts them');
});

test('workers: their routes never run a tick; finished tasks lose their words after a day and go after two', function () {
    $app = TestKit::app();
    $before = $app->store()->get('last_tick');
    $key = newWorker($app);
    workerPoll($app, $key);
    eq($app->store()->get('last_tick'), $before, 'a poll ran no tick');
    $host = workerHope($app);
    $id = $app->workers()->request('try', 0, $host, 'en', 'A listener named Anna asks for prayer.');
    $app->store()->update('voice_tasks', ['state' => 'done'], 'id = ?', [$id]);
    TestKit::clock($app)->advance(25 * 3_600_000);
    $app->workers()->maintain();
    eq($app->store()->value('SELECT text FROM voice_tasks WHERE id = ?', [$id]), '', 'words gone after a day');
    TestKit::clock($app)->advance(24 * 3_600_000);
    check($app->workers()->purge(intdiv($app->clock->nowMs(), 1000) - 2 * 86400) >= 1, 'the row after two');
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

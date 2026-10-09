<?php
declare(strict_types=1);

namespace Arche\Workers;

use Arche\ApiError;
use Arche\App;
use Arche\Audio\Mp3;
use Arche\Host\Hosts;
use Arche\Host\Lines;
use Arche\Host\Speech;
use Arche\Program\Timing;
use Arche\Support\Ids;

/**
 * The work the station asks of its computers (Workers\Computers; herde's
 * protocol/PROTOCOL.md). Today speech: a host's moment, a recorded line, a
 * try in /mod. The tick never waits for a computer: a task is queued, a
 * computer that polls is offered what it can and may do — the most urgent
 * first — claims one with a lease token of its own, keeps it while its polls
 * list it, and its result finishes the moment, the line or the try.
 *
 * Qwen writes speech token by token and now and then repeats or skips a
 * word: every clip's length is checked against its text, and one that does
 * not fit is spoken again with another seed (MAX_TAKES), then given up — the
 * moment goes to the next host. Mishaps are counted apart (MAX_FAILURES): a
 * Mac asleep or a model's error says nothing about the words.
 *
 * Privacy is the task's: a moment that names a listener, people's own
 * words, a moderator's typed line and a try are `private` and go to our
 * own computers only; a lender sees public work, and work on air only when
 * an admin lets it. Words leave a task a day after it ended.
 */
final class Tasks
{
    /** Results that do not fit their words, each with a new seed, before a task is given up. */
    private const MAX_TAKES = 2;
    /** Mishaps (a lapsed lease, a model's error) before a task is given up. */
    private const MAX_FAILURES = 3;
    /** A lease of protocol 2: every poll that lists the task renews it once fewer than RENEW_BELOW seconds are left. */
    public const LEASE_SECONDS = 45;
    private const RENEW_BELOW = 20;
    /** Protocol 1 leases (no heartbeat while it speaks): LEASE_FACTOR × the expected length + LEASE_BASE, at most LEASE_MAX (s). */
    private const LEASE_FACTOR = 3;
    private const LEASE_BASE = 60;
    private const LEASE_MAX = 600;
    /** Tasks offered with one poll. */
    public const OFFERS = 10;
    /** A try's clip waits this long for the editor to fetch it (s). */
    private const TRY_KEEP = 600;
    /** Speech runs about 13 characters a second; a clip outside these bounds read something else. */
    private const MIN_CPS = 6.0;
    private const MAX_CPS = 30.0;
    /** Below this many characters a short clip is fine (a pause, a name). */
    private const SHORT_TEXT = 40;
    /**
     * Live moments first: a test in /mod (a try) is two takes of up to 1,100
     * characters, and queued ahead of a break it could make the break miss its
     * deadline and go to a fallback voice.
     */
    private const PRIORITY = ['break' => 10, 'try' => 30, 'line' => 50];
    private const CLASS_OF = ['break' => 'live', 'try' => 'interactive', 'line' => 'background'];
    private const LEASE = '/^[A-Za-z0-9-]{8,64}$/';

    public function __construct(private App $app) {}

    // --- tasks the station asks for ------------------------------------------------------------

    /**
     * A clip asked of the computers in a host's voice.
     *
     * @param array<string,mixed> $host decoded (or a draft from "Try voice")
     * @param int $deadline unix s, 0 = none: nobody should start it after (unclaimed, it is cancelled then)
     * @param array<string,mixed> $extra kept with the task, never sent (a line's voice signature)
     * @param string $delivery how this moment should sound: Qwen's instruct is the host's own direction and this (Speech::direction)
     * @param int $due unix s, 0 = none: its clip is useless after, and a computer still holding it is told to stop
     */
    public function request(string $purpose, int $refId, array $host, string $lang, string $text, int $deadline = 0, array $extra = [],
                            string $delivery = '', string $privacy = 'private', int $due = 0): int
    {
        $settings = Hosts::settings('worker', (array) $host['settings']);
        $now = $this->app->clock->now();
        $voice = Hosts::voiceFor($host, $lang);
        $model = Computers::family((string) ($host['model'] ?: Computers::MODEL));
        return $this->app->store()->insert('worker_tasks', [
            'kind' => 'tts',
            'class' => self::CLASS_OF[$purpose] ?? 'background',
            'purpose' => $purpose,
            'ref_id' => $refId,
            'host_id' => (int) $host['id'],
            'privacy' => $privacy === 'public' ? 'public' : 'private',
            'model' => $model,
            'voice' => $voice,
            'lang' => $lang,
            'size' => mb_strlen($text),
            'input' => json_encode([
                'text' => $text,
                'lang' => $lang,
                'voice' => $voice,
                'model' => $model,
                'instruct' => Speech::direction((string) ($host['instructions'] ?? ''), $delivery),
                'temperature' => (float) $settings['temperature'],
                'seed' => 0,
            ], JSON_UNESCAPED_UNICODE),
            'extra' => json_encode((object) $extra, JSON_UNESCAPED_UNICODE),
            'priority' => self::PRIORITY[$purpose] ?? 50,
            'deadline' => $deadline,
            'due' => $due,
            'created' => $now,
            'updated' => $now,
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function tasksFor(string $purpose, int $refId): array
    {
        return array_map([self::class, 'decode'], $this->app->store()->all('SELECT * FROM worker_tasks WHERE purpose = ? AND ref_id = ? ORDER BY id', [$purpose, $refId]));
    }

    /**
     * Its tasks are not wanted any more (a plan change, a host switched, a
     * line changed or removed, an account deleted): cancelled, their words
     * gone — a computer's result for one is then turned away, and a computer
     * still holding one hears so with its next poll.
     */
    public function cancelFor(string $purpose, int $refId): void
    {
        $this->app->store()->query(
            "UPDATE worker_tasks SET state = 'cancelled', input = '{}', updated = ? WHERE purpose = ? AND ref_id = ? AND state IN ('queued', 'leased')",
            [$this->app->clock->now(), $purpose, $refId],
        );
    }

    /** Words of finished tasks too (an erased account's): nothing of them stays with the tasks. */
    public function forget(string $purpose, int $refId): void
    {
        $this->cancelFor($purpose, $refId);
        $this->app->store()->query("UPDATE worker_tasks SET input = '{}', result = CASE WHEN kind = 'tts' THEN result ELSE '' END WHERE purpose = ? AND ref_id = ?", [$purpose, $refId]);
    }

    /**
     * "Try voice" in /mod: its state, and its clip once spoken.
     *
     * @return array<string,mixed>
     */
    public function tryResult(int $taskId): array
    {
        $t = $this->task($taskId);
        if ($t === null || $t['purpose'] !== 'try') throw new ApiError(404, 'not_found');
        $out = ['state' => $t['state']];
        if ($t['state'] === 'done') {
            $bytes = is_file($t['result']) ? (string) file_get_contents($t['result']) : '';
            if ($bytes === '') return ['state' => 'failed', 'error' => 'gone'];
            $out += ['audio' => base64_encode($bytes), 'ms' => $t['ms']];
        }
        if ($t['state'] === 'failed') $out['error'] = $t['error'];
        return $out;
    }

    // --- what computers call (Api\WorkerApi) ---------------------------------------------------

    /**
     * What a computer could and may do now, most urgent first: metadata only
     * — no words, so a computer learns nothing it does not take.
     *
     * @param array<string,mixed> $c decoded computer
     * @param array<string,int> $free slots per kind it could start now
     * @return list<array<string,mixed>>
     */
    public function offers(array $c, array $free, int $limit = self::OFFERS): array
    {
        $now = $this->app->clock->now();
        [$where, $args] = $this->matching($c, $free, $now);
        if ($where === null) return [];
        $rows = $this->app->store()->all(
            "SELECT * FROM worker_tasks WHERE $where ORDER BY priority, CASE WHEN deadline = 0 THEN 1 ELSE 0 END, deadline, id LIMIT " . max(1, min(self::OFFERS, $limit)),
            $args,
        );
        return array_map(function ($r) use ($now) {
            $t = self::decode($r);
            $offer = ['task' => $t['id'], 'kind' => $t['kind'], 'class' => $t['class'], 'model' => $t['model']];
            if ($t['kind'] === 'tts') $offer += ['voice' => $t['voice'], 'lang' => $t['lang'], 'size' => ['chars' => $t['size']]];
            else $offer += ['size' => ['input_tokens' => $t['size'], 'max_tokens' => (int) ($t['input']['max_tokens'] ?? 0)]];
            return $offer + self::times($t, $now);
        }, $rows);
    }

    /**
     * A computer takes a task with a lease token of its own (PROTOCOL.md,
     * `claim`): one guarded update, every condition of an offer in its WHERE,
     * so two computers racing for it, a task cancelled or too late meanwhile,
     * or private work for a lender all simply find nothing. The same claim
     * sent again gets the same task.
     *
     * @param array<string,mixed> $c decoded computer
     * @return array<string,mixed>|null the task as the computer does it; null: gone
     */
    public function claim(array $c, int $id, string $lease, ?int $leaseSeconds = null): ?array
    {
        if (!preg_match(self::LEASE, $lease)) throw new ApiError(400, 'bad_request');
        $store = $this->app->store();
        $now = $this->app->clock->now();
        return $store->tx(function () use ($store, $c, $id, $lease, $now, $leaseSeconds): ?array {
            $t = $this->task($id);
            if ($t === null) return null;
            if ($t['state'] === 'leased' && $t['lease'] === $lease && $t['worker_id'] === $c['id']) return $this->forWorker($t, $now);
            // As many as it may hold: a lender one at a time.
            if ($this->held($c['id'], $now) >= (Computers::LEASES[$c['trust']] ?? 1)) return null;
            [$where, $args] = $this->matching($c, ['tts' => 1, 'text' => 1, 'stt' => 1], $now);
            if ($where === null) return null;
            $until = $now + ($leaseSeconds ?? self::LEASE_SECONDS);
            // A lease that lapsed without a word from its computer counts as a mishap of the task.
            $n = $store->query(
                "UPDATE worker_tasks SET state = 'leased', worker_id = ?, lease = ?, lease_until = ?, outcome = '',
                   failures = failures + CASE WHEN state = 'leased' THEN 1 ELSE 0 END, updated = ? WHERE id = ? AND $where",
                [$c['id'], $lease, $until, $now, $id, ...$args],
            )->rowCount();
            if ($n !== 1) return null;
            $t = $this->task($id);
            return $t !== null ? $this->forWorker($t, $now) : null;
        });
    }

    /**
     * The running tasks a poll lists (PROTOCOL.md, Leases): each still this
     * computer's is kept — its lease renewed once less than RENEW_BELOW
     * seconds are left (an idle poll writes nothing) — and every other one,
     * cancelled, taken over or past its due time, is named back for the
     * computer to drop.
     *
     * @param array<string,mixed> $c decoded computer
     * @param mixed $running [{task, lease, progress}]
     * @return list<array{task:int,lease:string}> what the computer should stop
     */
    public function heartbeat(array $c, mixed $running): array
    {
        $now = $this->app->clock->now();
        $cancel = [];
        foreach (array_slice(is_array($running) ? $running : [], 0, 20) as $r) {
            $id = is_array($r) ? (int) ($r['task'] ?? 0) : 0;
            $lease = is_array($r) ? (string) ($r['lease'] ?? '') : '';
            if ($id <= 0 || !preg_match(self::LEASE, $lease)) continue;
            $t = $this->task($id);
            $mine = $t !== null && $t['state'] === 'leased' && $t['lease'] === $lease && $t['worker_id'] === $c['id'];
            if (!$mine) {
                $cancel[] = ['task' => $id, 'lease' => $lease];
                continue;
            }
            if ($t['due'] > 0 && $t['due'] <= $now) {
                $this->tooLate($t['id']);
                $cancel[] = ['task' => $id, 'lease' => $lease];
                continue;
            }
            if ($t['lease_until'] - $now < self::RENEW_BELOW) {
                $this->app->store()->update('worker_tasks', ['lease_until' => $now + self::LEASE_SECONDS], "id = ? AND lease = ? AND state = 'leased'", [$id, $lease]);
            }
        }
        return $cancel;
    }

    /**
     * A computer's result (PROTOCOL.md, `result`): speech as an MP3 file,
     * text as JSON. Sent again with the same lease, it gets the answer the
     * first one got: a lost connection never counts a take twice.
     *
     * @param array<string,mixed> $c decoded computer
     * @param array<mixed>|null $output a text task's output
     * @return array<string,mixed>
     */
    public function result(array $c, int $id, string $lease, ?string $file, ?array $output = null): array
    {
        $t = $this->task($id) ?? throw new ApiError(409, 'gone');
        if ($t['lease'] === $lease && $t['worker_id'] === $c['id'] && $t['outcome'] !== '') return self::replay($t['outcome']);
        // A late result whose lease lapsed is still taken while nobody claimed the task since.
        if (!in_array($t['state'], ['leased', 'queued'], true) || $t['lease'] !== $lease || $t['worker_id'] !== $c['id']) throw new ApiError(409, 'gone');
        return match ($t['kind']) {
            'tts' => $this->speech($c, $t, $file),
            'text' => $this->answer($c, $t, $output),
            default => throw new ApiError(409, 'gone'),
        };
    }

    /**
     * A computer could not do it (PROTOCOL.md, `fail`). Stopping or told to
     * stop: back to the queue, nothing counted. A voice or model it lacks:
     * another computer may try. A model's error: a mishap of the task and of
     * the computer. `retry` false: the task cannot succeed anywhere.
     *
     * @param array<string,mixed> $c decoded computer
     * @return array{ok:bool}
     */
    public function fail(array $c, int $id, string $lease, string $reason, bool $retry, string $error): array
    {
        $t = $this->task($id);
        if ($t !== null && $t['lease'] === $lease && $t['worker_id'] === $c['id'] && $t['outcome'] !== '') return self::replay($t['outcome']);
        // Gone meanwhile (cancelled, taken over): nothing left to report.
        if ($t === null || !in_array($t['state'], ['leased', 'queued'], true) || $t['lease'] !== $lease || $t['worker_id'] !== $c['id']) return ['ok' => true];
        $error = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $error)), 0, 200) ?: $reason;
        $ok = json_encode(['status' => 200, 'body' => ['ok' => true]]);
        if (in_array($reason, ['shutdown', 'cancelled'], true)) {
            $this->requeue($t, ['outcome' => $ok]);
            return ['ok' => true];
        }
        $set = ['outcome' => $ok, 'failures' => $t['failures'] + 1, 'error' => $error];
        // This computer cannot, another may: never offered to it again.
        $cannot = in_array($reason, ['invalid', 'too_large'], true);
        if ($cannot) $set['failed_by'] = $c['id'];
        $this->requeue($t, $set, !$retry || $set['failures'] >= self::MAX_FAILURES);
        if (!$cannot) $this->app->computers()->failed($c['id']);
        return ['ok' => true];
    }

    // --- protocol 1 (ARCHE's first voice worker), until every Mac runs herde ----------------------

    /**
     * A protocol 1 poll leases the most urgent task it can speak at once,
     * with a token of the station's and today's long lease (it does not poll
     * while it speaks).
     *
     * @param array<string,mixed> $c decoded computer
     * @return array<string,mixed>|null the task as a protocol 1 worker speaks it
     */
    public function leaseV1(array $c): ?array
    {
        foreach ($this->offers($c, ['tts' => 1], 3) as $offer) {
            $expected = (int) ceil(((int) ($offer['size']['chars'] ?? 0)) / 13 * 1000);
            $seconds = min(self::LEASE_MAX, self::LEASE_FACTOR * intdiv($expected, 1000) + self::LEASE_BASE);
            $task = $this->claim($c, (int) $offer['task'], 'v1-' . Ids::short(20), $seconds);
            if ($task === null) continue;
            $t = $this->task((int) $offer['task']) ?? throw new \LogicException('task vanished');
            $host = $this->app->hosts()->get($t['host_id']);
            return [
                'id' => $t['id'],
                'purpose' => $t['purpose'],
                'text' => (string) ($t['input']['text'] ?? ''),
                'lang' => $t['lang'],
                'voice' => $t['voice'],
                'model' => (string) ($host['model'] ?? '') ?: Computers::MODEL,
                'instruct' => (string) ($t['input']['instruct'] ?? ''),
                'temperature' => (float) ($t['input']['temperature'] ?? 0.7),
                'seed' => (int) ($t['input']['seed'] ?? 0),
                'expected_ms' => $expected,
                'lease_until' => $t['lease_until'],
            ];
        }
        return null;
    }

    /** A protocol 1 upload: the station's own lease of the task. @param array<string,mixed> $c @return array<string,mixed> */
    public function completeV1(array $c, int $id, ?string $file): array
    {
        $t = $this->task($id) ?? throw new ApiError(409, 'task_gone');
        if ($t['state'] === 'done' && $t['worker_id'] === $c['id']) return ['ok' => true]; // sent twice
        try {
            return $this->result($c, $id, $t['lease'], $file);
        } catch (ApiError $e) {
            throw match ($e->error) {
                'gone' => new ApiError(409, 'task_gone'),
                'invalid_result' => new ApiError(422, 'invalid_audio'),
                default => $e,
            };
        }
    }

    /** @param array<string,mixed> $c @return array{ok:bool} */
    public function failV1(array $c, int $id, string $error, bool $retry): array
    {
        $t = $this->task($id);
        if ($t === null || $t['state'] !== 'leased' || $t['worker_id'] !== $c['id']) return ['ok' => true];
        return $this->fail($c, $id, $t['lease'], 'engine', $retry, $error);
    }

    // --- results ------------------------------------------------------------------------------

    /**
     * A clip: checked (an MP3; a length that fits its words), kept where its
     * owner keeps clips, and handed to it — in one transaction with marking
     * the task done, so a host switch or a plan change in between turns it
     * away instead of mixing two voices in one moment.
     *
     * @param array<string,mixed> $c
     * @param array<string,mixed> $t
     * @return array<string,mixed>
     */
    private function speech(array $c, array $t, ?string $file): array
    {
        $check = $file !== null ? Mp3::inspect($file) : ['ok' => false, 'ms' => 0];
        if (!$check['ok']) {
            $this->requeue($t, ['failures' => $t['failures'] + 1, 'error' => 'no playable audio',
                'outcome' => json_encode(['status' => 422, 'body' => ['error' => 'invalid_result']])], $t['failures'] + 1 >= self::MAX_FAILURES);
            $this->app->computers()->failed($c['id']);
            throw new ApiError(422, 'invalid_result');
        }
        $text = (string) ($t['input']['text'] ?? '');
        if (!self::plausible($text, $check['ms'])) {
            $retake = json_encode(['status' => 200, 'body' => ['ok' => false, 'retake' => true]]);
            $this->requeue($t, ['takes' => $t['takes'] + 1, 'outcome' => $retake,
                'error' => 'length ' . round($check['ms'] / 1000, 1) . ' s for ' . mb_strlen($text) . ' characters'], $t['takes'] + 1 >= self::MAX_TAKES);
            // Qwen skips or repeats a stretch now and then: on our own Mac that is the model, not the
            // Mac — resting it would send live moments to other voices. A lender's clips that keep
            // missing their words may be anything: it rests.
            if ($c['trust'] !== 'own') $this->app->computers()->failed($c['id']);
            return ['ok' => false, 'retake' => true];
        }
        $bytes = (string) file_get_contents((string) $file);
        $where = $this->keep($t, $bytes);
        $store = $this->app->store();
        $lent = $c['trust'] !== 'own';
        $handed = $store->tx(function () use ($store, $t, $where, $check, $lent): bool {
            $ok = json_encode(['status' => 200, 'body' => ['ok' => true]]);
            $n = $store->query(
                "UPDATE worker_tasks SET state = 'done', result = ?, ms = ?, error = '', outcome = ?, updated = ? WHERE id = ? AND lease = ? AND state IN ('leased', 'queued')",
                [$where, $check['ms'], $ok, $this->app->clock->now(), $t['id'], $t['lease']],
            )->rowCount();
            if ($n !== 1) return false;
            $taken = match ($t['purpose']) {
                'break' => $this->app->hostBreaks()->voiced($t['ref_id'], $t['lang'], $where, $check['ms'], $t['id']),
                'line' => $this->app->lines()->recorded($t['ref_id'], $t['lang'], $where, $check['ms'], (string) ($t['extra']['signature'] ?? ''), $lent),
                'try' => true,
                default => false,
            };
            if (!$taken) {
                // Its owner no longer wants it (cancelled meanwhile): turned away, nothing kept.
                $store->update('worker_tasks', ['state' => 'cancelled', 'input' => '{}', 'outcome' => ''], 'id = ?', [$t['id']]);
            }
            return $taken;
        });
        if (!$handed) {
            $this->drop($t, $where);
            throw new ApiError(409, 'gone');
        }
        // A try's words are needed by nobody once spoken (a test's may be anything typed in /mod).
        if ($t['purpose'] === 'try') $store->update('worker_tasks', ['input' => '{}'], 'id = ?', [$t['id']]);
        // Its characters: a line's against the host's monthly recording, a moment's against its day.
        $this->app->usage()->record($t['purpose'] === 'line' ? Lines::usageKind($t['host_id']) : Hosts::usageKind($t['host_id']), $t['size'], 0, 0);
        $this->app->computers()->succeeded($c['id']);
        return ['ok' => true];
    }

    /**
     * A text task's answer: kept for its owner (no text task is asked for
     * yet: the checks and the host's words follow in herde's phase 2).
     *
     * @param array<string,mixed> $c
     * @param array<string,mixed> $t
     * @param array<mixed>|null $output
     * @return array<string,mixed>
     */
    private function answer(array $c, array $t, ?array $output): array
    {
        $content = is_array($output) ? ($output['content'] ?? null) : null;
        if (!is_string($content) || strlen($content) > 200_000) {
            $this->requeue($t, ['failures' => $t['failures'] + 1, 'error' => 'no usable answer',
                'outcome' => json_encode(['status' => 422, 'body' => ['error' => 'invalid_result']])], $t['failures'] + 1 >= self::MAX_FAILURES);
            throw new ApiError(422, 'invalid_result');
        }
        $kept = ['content' => $content, 'finish' => ($output['finish'] ?? '') === 'length' ? 'length' : 'stop',
            'usage' => ['input_tokens' => (int) ($output['usage']['input_tokens'] ?? 0), 'output_tokens' => (int) ($output['usage']['output_tokens'] ?? 0)]];
        $this->app->store()->update('worker_tasks', ['state' => 'done', 'result' => json_encode($kept, JSON_UNESCAPED_UNICODE), 'error' => '',
            'outcome' => json_encode(['status' => 200, 'body' => ['ok' => true]]), 'updated' => $this->app->clock->now()],
            "id = ? AND lease = ? AND state IN ('leased', 'queued')", [$t['id'], $t['lease']]);
        $this->app->computers()->succeeded($c['id']);
        return ['ok' => true];
    }

    /** Where a clip is kept: a moment's and a line's where every clip goes, a try's privately. @param array<string,mixed> $t */
    private function keep(array $t, string $bytes): string
    {
        return match ($t['purpose']) {
            'break' => $this->app->media()->put('host/' . gmdate('Ymd', $this->app->clock->now()), sprintf('%d-%s.%s.mp3', $t['ref_id'], Ids::short(6), $t['lang']), $bytes),
            'line' => $this->app->media()->put('lines', sprintf('%d-%s.%s.mp3', $t['ref_id'], substr(hash('sha256', $bytes), 0, 12), $t['lang']), $bytes),
            default => $this->tryFile($t['id'], $bytes),
        };
    }

    /** A clip nobody took. @param array<string,mixed> $t */
    private function drop(array $t, string $where): void
    {
        if (in_array($t['purpose'], ['break', 'line'], true)) $this->app->media()->delete($where);
        else @unlink($where);
    }

    /** A try's clip: in the data folder, never public, for the editor to fetch. */
    private function tryFile(int $id, string $bytes): string
    {
        $dir = $this->app->config->dataDir . '/tries';
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        $path = $dir . '/' . $id . '.mp3';
        file_put_contents($path, $bytes);
        return $path;
    }

    // --- housekeeping (every tick, jobs phase) --------------------------------------------------

    /**
     * Leases that lapsed without a word go back to the queue (a Mac asleep
     * mid-task); a task nobody started before its time, or still held when
     * its result would come too late, is cancelled (its moment moves on to
     * the next host); tries' clips go after TRY_KEEP; and what tasks held of
     * listeners' words goes a day after they ended.
     *
     * @return array{requeued:int,expired:int}
     */
    public function maintain(): array
    {
        $now = $this->app->clock->now();
        $store = $this->app->store();
        $requeued = 0;
        foreach ($store->all("SELECT * FROM worker_tasks WHERE state = 'leased' AND lease_until < ?", [$now]) as $r) {
            $t = self::decode($r);
            // A lapse is no fault of the words: the same seed; and it leaves the lease, so a late result is still taken.
            $this->requeue($t, ['failures' => $t['failures'] + 1, 'error' => 'lease ran out'], $t['failures'] + 1 >= self::MAX_FAILURES, false);
            $requeued++;
        }
        $expired = $store->query(
            "UPDATE worker_tasks SET state = 'cancelled', error = 'too late', input = '{}', updated = ?
             WHERE (state = 'queued' AND deadline > 0 AND deadline <= ?) OR (state IN ('queued', 'leased') AND due > 0 AND due <= ?)",
            [$now, $now, $now],
        )->rowCount();
        foreach ($store->all("SELECT id, result FROM worker_tasks WHERE purpose = 'try' AND result != '' AND updated < ?", [$now - self::TRY_KEEP]) as $r) {
            @unlink((string) $r['result']);
            $store->update('worker_tasks', ['result' => ''], 'id = ?', [(int) $r['id']]);
        }
        // The moment's delivery travels in the instruct: it goes with the words.
        $store->query(
            "UPDATE worker_tasks SET input = '{}', result = CASE WHEN kind = 'tts' THEN result ELSE '' END
             WHERE input != '{}' AND state NOT IN ('queued', 'leased') AND updated < ?",
            [$now - 86400],
        );
        return ['requeued' => $requeued, 'expired' => $expired];
    }

    /** Old tasks' rows (Tick::purge). */
    public function purge(int $beforeTs): int
    {
        return $this->app->store()->query("DELETE FROM worker_tasks WHERE state NOT IN ('queued', 'leased') AND updated < ?", [$beforeTs])->rowCount();
    }

    /** A computer switched off, resting or removed: what it holds goes back to the queue, nothing counted. */
    public function release(int $computerId): void
    {
        $this->app->store()->query(
            "UPDATE worker_tasks SET state = 'queued', worker_id = NULL, lease = '', lease_until = 0, outcome = '', updated = ? WHERE worker_id = ? AND state = 'leased'",
            [$this->app->clock->now(), $computerId],
        );
    }

    /** By when a moment's task must be started: unstarted then, its clip could not be there before the commit. */
    public static function deadlineFor(int $startMs): int
    {
        return self::dueFor($startMs) - 30;
    }

    /** When a moment's clip is useless: the commit fixes its minutes. */
    public static function dueFor(int $startMs): int
    {
        return intdiv($startMs - Timing::COMMIT, 1000);
    }

    /** Whether a clip's length fits its words (a repeated or skipped stretch does not). */
    public static function plausible(string $text, int $ms): bool
    {
        $chars = mb_strlen($text);
        $seconds = $ms / 1000;
        if ($seconds <= 0) return false;
        if ($seconds > $chars / self::MIN_CPS + 3) return false;
        return $chars < self::SHORT_TEXT || $seconds >= $chars / self::MAX_CPS;
    }

    // --- rows ---------------------------------------------------------------------------------

    /**
     * The SQL condition for tasks a computer can and may do now: a kind it
     * has a free slot for, a model it runs, a voice it speaks in a language
     * it speaks it in — matched in SQL, so no number of tasks for voices it
     * lacks can hide one it has — not too late, not given back by it as
     * impossible, and for a lender public, and on air only when an admin let it.
     *
     * @param array<string,mixed> $c
     * @param array<string,int> $free
     * @return array{0:?string,1:list<mixed>}
     */
    private function matching(array $c, array $free, int $now): array
    {
        // Resting after failures in a row, or switched off: nothing.
        if ($c['resting_until'] > $now || $c['active'] !== 1) return [null, []];
        $parts = [];
        $args = [];
        foreach (Computers::engines($c) as $e) {
            if ((int) ($free[$e['kind']] ?? 0) <= 0) continue;
            if ($e['kind'] === 'tts') {
                // Voices that speak the same languages, together.
                $groups = [];
                foreach ($e['voices'] as $v) {
                    $langs = $v['langs'];
                    sort($langs);
                    $groups[implode(',', $langs)][] = strtolower($v['id']);
                }
                foreach ($groups as $langs => $voices) {
                    if ($langs === '') continue;
                    $langs = explode(',', $langs);
                    $parts[] = "(kind = 'tts' AND model = ? AND lower(voice) IN (" . self::marks($voices) . ') AND lang IN (' . self::marks($langs) . '))';
                    array_push($args, Computers::family($e['model']), ...$voices, ...$langs);
                }
            } elseif ($e['kind'] === 'text') {
                $parts[] = "(kind = 'text' AND model = ?)";
                $args[] = $e['model'];
            }
        }
        if (!$parts) return [null, []];
        $where = '(' . implode(' OR ', $parts) . ")
            AND (state = 'queued' OR (state = 'leased' AND lease_until < ?)) AND (deadline = 0 OR deadline > ?) AND (due = 0 OR due > ?) AND failed_by != ?";
        array_push($args, $now, $now, $now, $c['id']);
        if ($c['trust'] !== 'own') $where .= " AND privacy = 'public'" . ($c['live'] === 1 ? '' : " AND class != 'live'");
        return [$where, $args];
    }

    /**
     * Back to the queue with a new seed (or the same: a lapse), or given up.
     *
     * @param array<string,mixed> $t
     * @param array<string,mixed> $set what else changes
     */
    private function requeue(array $t, array $set, bool $giveUp = false, bool $newSeed = true): void
    {
        $input = $t['input'];
        if ($newSeed && !$giveUp && isset($input['seed'])) $input['seed'] = (int) $input['seed'] + 1;
        // The lease and the worker stay with the row: the same result or fail sent again is answered as before.
        $this->app->store()->update('worker_tasks', $set + [
            'state' => $giveUp ? 'failed' : 'queued',
            'input' => json_encode($giveUp ? new \stdClass() : ($input ?: new \stdClass()), JSON_UNESCAPED_UNICODE),
            'lease_until' => 0,
            'updated' => $this->app->clock->now(),
        ], 'id = ?', [$t['id']]);
    }

    private function tooLate(int $id): void
    {
        $this->app->store()->query(
            "UPDATE worker_tasks SET state = 'cancelled', error = 'too late', input = '{}', updated = ? WHERE id = ? AND state IN ('queued', 'leased')",
            [$this->app->clock->now(), $id],
        );
    }

    private function held(int $computerId, int $now): int
    {
        return (int) $this->app->store()->value("SELECT COUNT(*) FROM worker_tasks WHERE worker_id = ? AND state = 'leased' AND lease_until >= ?", [$computerId, $now]);
    }

    /** @param array<string,mixed> $t @return array<string,mixed> */
    private function forWorker(array $t, int $now): array
    {
        return ['id' => $t['id'], 'kind' => $t['kind'], 'class' => $t['class'], 'lease_in' => max(0, $t['lease_until'] - $now)]
            + self::times($t, $now) + ['input' => (object) $t['input']];
    }

    /** @param array<string,mixed> $t @return array<string,int> */
    private static function times(array $t, int $now): array
    {
        $out = [];
        if ($t['deadline'] > 0) $out['claim_in'] = max(0, $t['deadline'] - $now);
        if ($t['due'] > 0) $out['due_in'] = max(0, $t['due'] - $now);
        return $out;
    }

    /** @return array<string,mixed> */
    private static function replay(string $outcome): array
    {
        $o = json_decode($outcome, true);
        $status = (int) ($o['status'] ?? 200);
        $body = is_array($o['body'] ?? null) ? $o['body'] : ['ok' => true];
        if ($status !== 200) throw new ApiError($status, (string) ($body['error'] ?? 'gone'));
        return $body;
    }

    /** @param list<mixed> $values */
    private static function marks(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }

    /** @return array<string,mixed>|null */
    public function task(int $id): ?array
    {
        $row = $this->app->store()->one('SELECT * FROM worker_tasks WHERE id = ?', [$id]);
        return $row !== null ? self::decode($row) : null;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function decode(array $row): array
    {
        foreach (['input', 'extra'] as $k) {
            $v = json_decode((string) $row[$k], true);
            $row[$k] = is_array($v) ? $v : [];
        }
        foreach (['id', 'ref_id', 'host_id', 'size', 'lease_until', 'takes', 'failures', 'failed_by', 'priority', 'deadline', 'due', 'ms', 'created', 'updated'] as $k) $row[$k] = (int) $row[$k];
        $row['worker_id'] = $row['worker_id'] !== null ? (int) $row['worker_id'] : null;
        foreach (['kind', 'class', 'purpose', 'privacy', 'model', 'voice', 'lang', 'state', 'lease', 'outcome', 'result', 'error'] as $k) $row[$k] = (string) $row[$k];
        return $row;
    }
}

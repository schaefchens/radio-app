<?php
declare(strict_types=1);

namespace Arche\Api;

use Arche\ApiError;
use Arche\Http\Context;
use Arche\Workers\Computers;

/**
 * What the station's computers call (Workers\Computers, Workers\Tasks): a
 * computer of ours or a lent one, with no public address, joins with an
 * invite, polls for work, claims a task, hands back its result or says why
 * it could not. Protocol 2 is herde's (its protocol/PROTOCOL.md); protocol
 * 1, ARCHE's first voice worker's, stays until every Mac runs herde. The
 * key comes in X-Worker-Key (X-Arche-Worker-Key before). None of these
 * routes runs a tick: computers poll every few seconds, and the listeners'
 * requests and the cron keep the program going.
 */
final class WorkerApi
{
    /** The kinds of work, and their versions, this station gives out. */
    private const KINDS = ['tts' => [1], 'text' => [1]];

    public function __construct(private Context $c) {}

    /** @return array<string,mixed> */
    private function computer(): array
    {
        return $this->c->app->computers()->authenticate($this->c->req->headers, $this->c->req->ip);
    }

    // --- protocol 2 ------------------------------------------------------------------------------

    /** A one-time invite becomes a key (no key yet: the code is the credential). */
    public function join(): array
    {
        return $this->c->app->computers()->join($this->c->req->json(), $this->c->req->ip);
    }

    /**
     * The heartbeat and the market: what the computer says about itself is
     * noted (written only where it changed), the tasks it holds are kept or
     * named back to stop, and it is offered what it could and may do now.
     */
    public function poll2(): array
    {
        $app = $this->c->app;
        $c = $this->computer();
        $in = $this->c->req->json();
        $kinds = $this->kinds($in);
        if ((int) ($in['protocol'] ?? 0) !== 2 || !$kinds) {
            throw new ApiError(426, 'upgrade_required', ['supported' => ['protocol' => [2], 'kinds' => self::KINDS]]);
        }
        $hash = (string) ($in['caps_hash'] ?? '');
        if (!preg_match('/^[0-9a-f]{64}$/', $hash)) throw new ApiError(400, 'bad_request');
        $now = $app->clock->now();
        $state = in_array($in['state'] ?? null, ['ready', 'paused', 'loading'], true) ? (string) $in['state'] : 'ready';
        $resumeIn = $in['resume_in'] ?? null;
        $said = [
            'protocol' => 2,
            'version' => mb_substr((string) ($in['version'] ?? ''), 0, 60),
            'state' => $state,
            'resume_at' => $state === 'paused' && is_int($resumeIn) && $resumeIn > 0 ? $now + min($resumeIn, 30 * 86400) : 0,
        ];
        if (isset($in['caps'])) $said += ['caps' => Computers::caps($in['caps']), 'caps_hash' => $hash];
        $c = $app->computers()->polled($c, $said);
        $needCaps = !isset($in['caps']) && $hash !== $c['caps_hash'];
        $running = is_array($in['running'] ?? null) ? $in['running'] : [];
        $cancel = $app->workerTasks()->heartbeat($c, $running);
        $free = [];
        foreach ($kinds as $kind) $free[$kind] = max(0, (int) ($in['free'][$kind] ?? 0));
        $offers = $state === 'ready' && !$needCaps && $c['resting_until'] <= $now ? $app->workerTasks()->offers($c, $free) : [];
        return ['now' => $now, 'offers' => $offers, 'cancel' => $cancel, 'need_caps' => $needCaps,
            'retry_after' => $this->retryAfter($c, $state, $offers !== [], count($running) > count($cancel))];
    }

    /** @param array<string,string> $a */
    public function claim(array $a): array
    {
        $c = $this->computer();
        $task = $this->c->app->workerTasks()->claim($c, $this->taskId($a), (string) ($this->c->req->json()['lease'] ?? ''));
        if ($task === null) throw new ApiError(409, 'gone');
        return ['task' => $task];
    }

    /** Speech as multipart (a JSON body is cut at 1 MiB, a long clip is more), text as JSON. @param array<string,string> $a */
    public function result(array $a): array
    {
        $c = $this->computer();
        $in = $this->c->req->json();
        $output = is_array($in['output'] ?? null) ? $in['output'] : null;
        return $this->c->app->workerTasks()->result($c, $this->taskId($a), (string) ($in['lease'] ?? ''), $this->c->req->file('audio'), $output);
    }

    /** @param array<string,string> $a */
    public function fail2(array $a): array
    {
        $c = $this->computer();
        $in = $this->c->req->json();
        $reason = in_array($in['reason'] ?? null, ['shutdown', 'cancelled', 'invalid', 'too_large', 'engine', 'timeout'], true) ? (string) $in['reason'] : 'engine';
        return $this->c->app->workerTasks()->fail($c, $this->taskId($a), (string) ($in['lease'] ?? ''), $reason, ($in['retry'] ?? true) !== false, (string) ($in['error'] ?? ''));
    }

    // --- protocol 1 --------------------------------------------------------------------------------

    /** A protocol 1 poll: its report noted, and the most urgent task it can speak leased at once. */
    public function poll(): array
    {
        $app = $this->c->app;
        $c = $this->computer();
        // A lent computer speaks protocol 2 only: this one leases blindly, and its report says nothing of trust.
        if ($c['trust'] !== 'own') throw new ApiError(403, 'worker_inactive');
        $report = $this->c->req->json();
        $ready = ($report['ready'] ?? true) !== false;
        $caps = Computers::capsOfReport($report);
        $c = $app->computers()->polled($c, [
            'protocol' => 1,
            'version' => mb_substr((string) ($report['version'] ?? ''), 0, 60),
            // "Not ready" (a check, a model still loading) leases nothing and does not count as online.
            'state' => $ready ? 'ready' : 'loading',
            'caps' => $caps,
            'caps_hash' => hash('sha256', (string) json_encode($caps)),
        ]);
        if (!$ready) return ['task' => null, 'retry_after' => 15];
        $task = $c['resting_until'] <= $app->clock->now() ? $app->workerTasks()->leaseV1($c) : null;
        if ($task === null) return ['task' => null, 'retry_after' => $this->workerHostsInUse() ? 4 : 15];
        return ['task' => $task, 'retry_after' => 2];
    }

    /** @param array<string,string> $a */
    public function audio(array $a): array
    {
        $c = $this->computer();
        return $this->c->app->workerTasks()->completeV1($c, $this->taskId($a), $this->c->req->file('audio'));
    }

    /** @param array<string,string> $a */
    public function fail(array $a): array
    {
        $c = $this->computer();
        $in = $this->c->req->json();
        return $this->c->app->workerTasks()->failV1($c, $this->taskId($a), (string) ($in['error'] ?? ''), ($in['retry'] ?? true) !== false);
    }

    // --- helpers ---------------------------------------------------------------------------------

    /**
     * The kinds a computer speaks in a version this station gives out.
     *
     * @param array<mixed> $in
     * @return list<string>
     */
    private function kinds(array $in): array
    {
        $out = [];
        foreach (self::KINDS as $kind => $versions) {
            $theirs = $in['kinds'][$kind] ?? null;
            if (is_array($theirs) && array_intersect($versions, array_map('intval', $theirs))) $out[] = $kind;
        }
        return $out;
    }

    /**
     * When to poll again: soon while there is work or it holds a task (its
     * leases must stay alive: at most 15 s, PROTOCOL.md), seldom while it
     * is paused or nothing could come for it.
     *
     * @param array<string,mixed> $c
     */
    private function retryAfter(array $c, string $state, bool $offered, bool $holding): int
    {
        if ($offered) return 2;
        if ($holding) return 5;
        if ($state === 'loading') return 10;
        if ($state === 'paused') return 30;
        if ($c['trust'] !== 'own') return 15;
        return $this->workerHostsInUse() ? 4 : 15;
    }

    /** Whether some host may speak with a computer: then idle computers poll more often. */
    private function workerHostsInUse(): bool
    {
        return $this->c->app->store()->value("SELECT 1 FROM hosts WHERE provider = 'worker' AND active = 1 LIMIT 1") !== null;
    }

    /** @param array<string,string> $a */
    private function taskId(array $a): int
    {
        $id = (int) ($a['id'] ?? 0);
        if ($id <= 0) throw new ApiError(404, 'not_found');
        return $id;
    }
}

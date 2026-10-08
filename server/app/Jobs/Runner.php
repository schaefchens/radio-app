<?php
declare(strict_types=1);

namespace Arche\Jobs;

use Arche\App;
use Arche\Support\BudgetExceeded;

/**
 * Runs job phases while the tick's budget lasts. Each phase is one network
 * call at most, so stopping early only ever defers work to the next tick.
 */
final class Runner
{
    /** Seconds a phase is assumed to need at most; below this, stop. */
    private const PHASE_RESERVE = 8.0;
    /** A phase named `wait…` waits for something outside (a voice worker's upload): looked at again after this (s). */
    public const WAIT_SECONDS = 30;

    public function __construct(private App $app) {}

    public function runUntilBudget(int $maxJobs = 12): int
    {
        $ran = 0;
        while ($ran < $maxJobs && $this->app->budget->left() > self::PHASE_RESERVE) {
            $job = $this->app->jobs()->lease();
            if ($job === null) break;
            $this->runOne($job);
            $ran++;
        }
        return $ran;
    }

    /** @param array<string,mixed> $job */
    public function runOne(array $job): void
    {
        $jobs = $this->app->jobs();
        try {
            $next = match ((string) $job['type']) {
                'host' => $this->app->hostBreaks()->runPhase($job),
                'moderate' => $this->app->moderator()->runPhase($job),
                'highlights' => $this->app->moderator()->runHighlights($job),
                'opendoors' => $this->app->openDoors()->runPhase($job),
                'channels' => $this->app->library()->runChannels($job),
                'lines' => $this->app->lines()->runPhase($job),
                'knowledge' => $this->app->knowledge()->runPhase($job),
                default => null,
            };
            if ($next !== null && str_starts_with($next, 'wait')) {
                $jobs->defer($job, $next, self::WAIT_SECONDS);
            } else {
                $jobs->advance($job, $next);
            }
        } catch (BudgetExceeded $e) {
            // Not the job's fault: the tick ran out of time mid-phase.
            $jobs->retry($job, $e->getMessage(), false);
        } catch (\Throwable $e) {
            $final = $jobs->retry($job, $e::class . ': ' . $e->getMessage());
            if ($final) $this->giveUp($job);
        }
    }

    /** A job that failed for good must leave its subject in a terminal state. @param array<string,mixed> $job */
    private function giveUp(array $job): void
    {
        $id = (int) $job['ref_id'];
        match ((string) $job['type']) {
            'host' => $this->app->store()->query("UPDATE host_breaks SET state = 'failed', source = 'error' WHERE id = ? AND state = 'pending'", [$id]),
            // Fail closed: a submission we could not check is not accepted.
            'moderate' => $this->app->submissions()->rejectAfterError($id),
            // A look-up that could not start: failed, said so; a check waiting on it stops waiting.
            'knowledge' => $this->app->knowledge()->giveUp($id, (string) ($job['last_error'] ?? 'gave up')),
            default => null,
        };
    }
}

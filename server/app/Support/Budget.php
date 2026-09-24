<?php
declare(strict_types=1);

namespace Arche\Support;

/**
 * Wall-clock budget for one request or tick. Every network call asks it for
 * its timeout, so a tick cannot outlive what the host allows (measured by the
 * probe and configured as TICK_BUDGET).
 */
final class Budget
{
    private float $deadline;

    public function __construct(int $seconds)
    {
        $this->deadline = microtime(true) + $seconds;
    }

    public function restart(int $seconds): void
    {
        $this->deadline = microtime(true) + $seconds;
    }

    public function left(): float
    {
        return $this->deadline - microtime(true);
    }

    /** Seconds a call may take: at most $max, never past the deadline. */
    public function remaining(int $max): int
    {
        $left = (int) floor($this->left());
        if ($left < 1) throw new BudgetExceeded('Time budget used up; the next tick continues.');
        return min($max, $left);
    }
}

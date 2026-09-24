<?php
declare(strict_types=1);

namespace Arche\Support;

/** A clock that only moves when told to. Tests only. */
final class FixedClock extends Clock
{
    public function __construct(private int $ms) {}

    public function nowMs(): int
    {
        return $this->ms;
    }

    public function set(int $ms): void
    {
        $this->ms = $ms;
    }

    public function advance(int $ms): void
    {
        $this->ms += $ms;
    }
}

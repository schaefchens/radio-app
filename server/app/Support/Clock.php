<?php
declare(strict_types=1);

namespace Arche\Support;

/** Time, injectable so the generator and its tests agree on "now". */
class Clock
{
    public function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    public function now(): int
    {
        return intdiv($this->nowMs(), 1000);
    }
}

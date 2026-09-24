<?php
declare(strict_types=1);

namespace Arche\Program;

/**
 * The generator's clock arithmetic, in milliseconds. WINDOW, LEAD and COMMIT
 * restate shared/src/constants.ts; shared/fixtures and the contract test keep
 * the two sides in step.
 *
 *   now ──LEAD──► last published minute file
 *   now ──COMMIT (= WINDOW + LEAD)──► end of the fixed timeline
 *   now ──DRAFT──► end of the drafts (host scripts are written in this gap)
 */
final class Timing
{
    public const MINUTE = 60_000;
    public const WINDOW = 600_000;
    public const LEAD = 300_000;
    public const COMMIT = 900_000;
    public const DRAFT = 2_700_000;

    /** After an outage the timeline restarts this far past the next minute. */
    public const REANCHOR_LEAD = 60_000;
    /** How far an item may run past its program block. */
    public const SOFT_OVERRUN = 90_000;
    /** Remaining block time below which no new song is started. */
    public const MIN_SONG = 120_000;
    public const HOST_ESTIMATE = 25_000;
    /** Air after a host clip, so the next item does not clip its last word. */
    public const HOST_PAD = 800;
    /** An announced request waits at most this long for its host break. */
    public const UNIT_TIMEOUT = 1_200_000;
    public const STAGE_ITEM = 300_000;
    public const FILLER_SILENCE_MAX = 60_000;

    public static function floorMinute(int $ms): int
    {
        return intdiv($ms, self::MINUTE) * self::MINUTE;
    }

    /** program/<channel>/slots/YYYYMMDD/HHMM.json — UTC, like shared/src/paths.ts. */
    public static function slotPath(string $channel, int $ms): string
    {
        $t = intdiv(self::floorMinute($ms), 1000);
        return sprintf('program/%s/slots/%s/%s.json', $channel, gmdate('Ymd', $t), gmdate('Hi', $t));
    }
}

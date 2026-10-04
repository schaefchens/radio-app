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
 *
 * COMMIT is how long the program outlives a stopped generator (a deploy, a
 * host outage) before clients fall back to the evergreen loop, and also the
 * least time between a decision and its airing: a request approved now goes
 * to the end of the drafts, about DRAFT from now. The gap DRAFT − COMMIT is
 * what a host break has to be written and voiced (one or two ticks).
 */
final class Timing
{
    public const MINUTE = 60_000;
    public const WINDOW = 180_000;
    public const LEAD = 120_000;
    public const COMMIT = 300_000;
    public const DRAFT = 480_000;

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
    public const UNIT_TIMEOUT = 600_000;
    /** Listener requests presented together by the host, at most. */
    public const BLOCK_MAX = 3;
    /** Regular songs between two blocks of requests, at least. */
    public const BLOCK_GAP_SONGS = 2;
    /**
     * Quiet after a listener's words read out (or a recording played) — inside
     * the item, so the stage keeps showing them, no silence item shorter than
     * MIN_CHUNK is needed, and no hole lets the fallback loop in.
     */
    public const PRAYER_GAP = 4_000;
    /** Requests read out together, at most this many characters (always at least one). */
    public const READ_CHARS = 600;
    /**
     * A prayer hour's reading waits at most this long for its voice behind
     * short fillers, counted from when it was first due (not from its
     * drafting, minutes earlier); then it gives its request back.
     */
    public const READING_WAIT = 120_000;
    public const STAGE_ITEM = 300_000;
    public const FILLER_SILENCE_MAX = 60_000;

    // --- the prayer hour (PrayerHour) ---------------------------------------------
    /**
     * From its presentation on, a prayer hour's plan reaches only this far
     * ahead, not DRAFT: a reading takes what was approved when it is drafted,
     * so drafting it later brings newer words on air sooner. It still leaves
     * two ticks to voice it before the commit.
     */
    public const PRAYER_LEAD = 420_000;
    /** Silence (and the quiet collection) is planned in pieces of at most this. */
    public const SILENT_CHUNK = 60_000;
    /** No piece of silence or music is planned shorter (except the last before the outro). */
    public const MIN_CHUNK = 15_000;
    /** What a late reading waits behind, at most: a short piece, so it follows soon once voiced. */
    public const PRAYER_FILLER = 20_000;
    /**
     * However short the hour, its prayer time lasts at least this: the
     * presentation, the announcement, and still minutes in which prayers are
     * taken (PRAYER_CLOSING) before the outro.
     */
    public const MIN_PRAYER = 1_500_000;
    /**
     * Prayers are taken until this long before the outro ("last chance" from
     * PRAYER_CLOSING): one sent then is checked, planned PRAYER_LEAD ahead and
     * read out before C.
     */
    public const PRAYER_CLOSED = 720_000;
    public const PRAYER_CLOSING = 1_020_000;
    /** A song after the outro, as the outro time reserves it: an estimate, so that time follows from the plan alone. */
    public const AFTER_SONG = 240_000;
    public const OUTRO_ESTIMATE = 45_000;
    public const OPENING_ESTIMATE = 35_000;
    /** The opening prayer belongs to the first minutes of the hour. */
    public const OPENING_WITHIN = 300_000;
    /** An outro that has not come this long after its time is not tried any more. */
    public const OUTRO_LATE = 120_000;

    /**
     * How long a text read word for word is planned (its voice decides at
     * commit): a short lead-in, about 14 characters a second, the gap after
     * it — at least 8 s, so a step still moves the plan on.
     */
    public static function readingEstimate(int $chars): int
    {
        return max(8_000, 2_000 + intdiv($chars * 1000, 14) + self::PRAYER_GAP);
    }

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

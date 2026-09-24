<?php
declare(strict_types=1);

namespace Arche\Program;

use Arche\App;

/**
 * open / closing / closed for each submission type, as a minute file tells the
 * PWA which buttons to offer. A submission can only air inside its program's
 * block, and only after moderation plus the plan ahead (Timing::DRAFT), so
 * intake closes `closed_min` before the block ends (and when the approved
 * queue already holds `max_queue_min` of airtime).
 */
final class SubmissionWindow
{
    /**
     * @param array<string,mixed> $channel
     * @param array<string,mixed> $program decoded program on air at $t
     * @param array{start:int,end:int,program_id:int} $block
     * @return array<string,string>|\stdClass
     */
    public static function states(App $app, array $channel, array $program, array $block, int $t): array|\stdClass
    {
        $allowed = array_values(array_filter($program['allowed'], fn($type) => self::featureOn($app, $type)));
        if (!$allowed) return new \stdClass();

        $s = $program['settings'];
        $remainingMin = intdiv(max(0, $block['end'] - $t), 60_000);
        $queueMin = intdiv($app->submissions()->queuedAirtime((int) $channel['id'], (int) $program['id']), 60_000);
        $maxQueue = max(1, (int) $s['max_queue_min']);

        if ($remainingMin < (int) $s['closed_min'] || $queueMin >= $maxQueue) {
            $state = 'closed';
        } elseif ($remainingMin < (int) $s['closing_min'] || $queueMin * 10 >= $maxQueue * 7) {
            $state = 'closing';
        } else {
            $state = 'open';
        }
        return array_fill_keys($allowed, $state);
    }

    /**
     * Every submission is judged by the text model (Claude or OpenAI); song
     * requests also need the YouTube check, recordings OpenAI's transcription.
     * Without them a submission could only fail closed, so it is not offered.
     */
    public static function featureOn(App $app, string $type): bool
    {
        $c = $app->config;
        if ($c->stubAi()) return true;
        if ($c->textProvider() === '') return false;
        return match ($type) {
            'song' => $app->youtube()->configured(),
            'prayer' => true,
            default => $c->openaiKey() !== '',
        };
    }
}

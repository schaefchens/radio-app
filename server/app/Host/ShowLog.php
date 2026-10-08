<?php
declare(strict_types=1);

namespace Arche\Host;

use Arche\App;
use Arche\Program\Drafter;
use Arche\Program\Timeline;

/**
 * The show so far, as the writer is given it: what played and what the
 * host said since this program's run began (Hosts::showStart), oldest
 * first, plus the last items before it. Without it every moment started
 * from zero: on 2026-10-07 the prayer hour was welcomed three times in
 * eleven minutes.
 *
 * Built when a script is written and passed to HostWriter::write() on its
 * own — never stored with the moment's context, which is saved.
 *
 * It names no listener. A host moment whose context named one, or whose
 * words are people's own, is summarized, not quoted. Erasure finds every
 * text that names a deleted listener through the context keys the writer was
 * given (Identity\Erasure); a later script that copied a name out of the
 * memory would hold it under none of them.
 *
 * Times are absolute ("16:24", the channel's time), so the memory written
 * for one moment is the start of the next one's: the model provider's
 * prompt cache serves it again. Too long, it is cut at a half hour, so the
 * cut moves only every thirty minutes.
 */
final class ShowLog
{
    /** Characters of memory at most; a two-hour show fits whole. */
    public const MAX_CHARS = 16_000;
    /** Cut, when too long: what began before the half hour this long before the moment. */
    private const KEEP_MS = 7_200_000;
    private const CUT_STEP_MS = 1_800_000;
    /** Items before the show: an intro knows the outro and the song just before it. */
    private const BEFORE = 2;
    /** People's own words read out, and a moderator's prayer: never quoted, never imitated. */
    private const NOT_QUOTED = ['reading', 'intercession', 'opening'];
    /** Context keys that tie a moment to a listener (the ones Identity\Erasure looks for, and what the writer was shown). */
    private const NAMING = ['submission_id', 'again_id', 'prayer_ids', 'previous_id', 'request', 'contribution', 'previous_request', 'community'];

    public function __construct(private App $app) {}

    /**
     * The memory for a host break, or [] when it has no place in a timeline
     * (a script tried by hand).
     *
     * @param array<string,mixed> $hb decoded host break
     * @param string $hostName who speaks it: moments another host spoke say so
     * @return array<string,mixed>
     */
    public function forBreak(array $hb, string $hostName = ''): array
    {
        $store = $this->app->store();
        $row = $store->one('SELECT * FROM timeline_items WHERE host_break_id = ? ORDER BY id DESC LIMIT 1', [(int) ($hb['id'] ?? 0)]);
        if ($row === null || ($hb['program_id'] ?? null) === null) return [];
        $item = Timeline::decode($row);
        $channel = $this->app->catalog()->channel((int) $hb['channel_id']);
        if ($channel === null) return [];
        $show = (int) ($hb['context']['show'] ?? $this->app->hosts()->showStart($channel, (int) $hb['program_id'], $item['block_start']));
        $select = 'SELECT t.*, h.kind AS h_kind, h.state AS h_state, h.context AS h_context, h.texts AS h_texts, h.source AS h_source
                   FROM timeline_items t LEFT JOIN host_breaks h ON h.id = t.host_break_id';
        // By the program's blocks and the sequence, not by times: an estimate
        // moves, and a program running over lands after the next one's start.
        $rows = $store->all(
            "$select WHERE t.channel_id = ? AND t.program_id = ? AND t.block_start >= ? AND t.seq < ?
               AND t.state != 'dropped' AND t.blocked = 0 AND t.type NOT IN ('gap', 'stage', 'jingle') ORDER BY t.seq",
            [(int) $hb['channel_id'], (int) $hb['program_id'], $show, $item['seq']],
        );
        $first = $rows ? (float) $rows[0]['seq'] : $item['seq'];
        $before = array_reverse($store->all(
            "$select WHERE t.channel_id = ? AND t.seq < ? AND t.state != 'dropped' AND t.blocked = 0 AND t.type IN ('song', 'host')
               ORDER BY t.seq DESC LIMIT " . self::BEFORE,
            [(int) $hb['channel_id'], $first],
        ));
        return $this->memory(array_map([self::class, 'item'], $before), array_map([self::class, 'item'], $rows), $show, $item['est_start'], $this->app->resolver()->zone($channel), $hostName);
    }

    /**
     * The memory from items: decoded timeline items, a host item with its
     * break's kind, state, context, texts and source under `break`. Public
     * for bin/replay-show, which rebuilds items from minute files.
     *
     * @param list<array<string,mixed>> $before the items just before the show
     * @param list<array<string,mixed>> $items the show's items before the moment, in order
     * @return array<string,mixed>
     */
    public function memory(array $before, array $items, int $showMs, int $momentMs, \DateTimeZone $zone, string $hostName = ''): array
    {
        $so = $this->entries($items, $zone, $hostName);
        // Too long: from a half hour about two hours back (so the cut moves only every half hour), then from the newest back.
        $memory = ['started' => self::clock($showMs, $zone), 'before' => $this->entries($before, $zone, $hostName), 'so_far' => $so];
        if (self::size($memory) > self::MAX_CHARS) {
            $cut = intdiv($momentMs - self::KEEP_MS, self::CUT_STEP_MS) * self::CUT_STEP_MS;
            $so = array_values(array_filter($so, fn($e) => $e['_ms'] >= $cut));
            $memory = ['started' => $memory['started'], 'left_out_before' => self::clock($cut, $zone), 'so_far' => $so];
            while (count($memory['so_far']) > 1 && self::size($memory) > self::MAX_CHARS) array_shift($memory['so_far']);
        }
        foreach (['before', 'so_far'] as $k) {
            if (isset($memory[$k])) $memory[$k] = array_map(fn($e) => array_diff_key($e, ['_ms' => 1]), $memory[$k]);
        }
        return $memory;
    }

    /**
     * Whether a moment's words may be quoted to the writer: model-written
     * words of a moment whose context named no listener.
     *
     * @param array<string,mixed> $context
     */
    public static function quotable(string $kind, string $source, array $context): bool
    {
        if (in_array($kind, self::NOT_QUOTED, true) || in_array($source, ['listener', 'moderator'], true)) return false;
        foreach (self::NAMING as $k) {
            if (!empty($context[$k])) return false;
        }
        // The prayer hour's outro keeps a count under `prayers`; a list is people's prayers.
        return !(is_array($context['prayers'] ?? null) && $context['prayers'] !== []);
    }

    /**
     * A joined row as an item: the timeline item, its host break under `break`.
     *
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private static function item(array $r): array
    {
        $item = Timeline::decode($r);
        if ($r['h_kind'] !== null) {
            $item['break'] = [
                'kind' => (string) $r['h_kind'],
                'state' => (string) $r['h_state'],
                'context' => json_decode((string) $r['h_context'], true) ?: [],
                'texts' => json_decode((string) $r['h_texts'], true) ?: [],
                'source' => (string) $r['h_source'],
            ];
        }
        return $item;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>> entries, each with its time in ms under `_ms` (taken off before the writer sees them)
     */
    private function entries(array $items, \DateTimeZone $zone, string $hostName): array
    {
        $out = [];
        foreach ($items as $item) {
            $at = (int) ($item['start_ms'] ?? 0) ?: (int) ($item['est_start'] ?? 0);
            $planned = ($item['state'] ?? 'committed') === 'draft';
            $entry = match ((string) $item['type']) {
                'song' => self::song($item),
                'host' => $this->host($item, $hostName),
                'contrib' => !empty($item['payload']['opening']) ? ['opening_prayer' => 'recorded'] : ['recording' => (string) ($item['payload']['kind'] ?? 'recording')],
                'silence' => ['silence_min' => (int) $item['dur_ms']],
                'bed' => ['prayer_music_min' => (int) $item['dur_ms']],
                default => null,
            };
            if ($entry === null) continue;
            // Runs of the same — readings, silence, prayer music — as one entry.
            $last = $out ? $out[array_key_last($out)] : null;
            $run = null;
            foreach (['read_out', 'silence_min', 'prayer_music_min'] as $k) {
                if (isset($entry[$k], $last[$k]) && ($k !== 'read_out' || $entry['what'] === $last['what'])) $run = $k;
            }
            if ($run !== null) {
                $out[array_key_last($out)][$run] += $entry[$run];
                continue;
            }
            $out[] = ['at' => self::clock($at, $zone)] + $entry + ($planned ? ['planned' => true] : []) + ['_ms' => $at];
        }
        // Silence and prayer music were added up in milliseconds: minutes, at least one.
        foreach ($out as &$e) {
            foreach (['silence_min', 'prayer_music_min'] as $k) {
                if (isset($e[$k])) $e[$k] = max(1, (int) round($e[$k] / 60_000));
            }
        }
        unset($e);
        return $out;
    }

    /**
     * A song or video, titled as the writer is given titles (Speech::title).
     * A listener's request is a flag — never who asked.
     *
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    private static function song(array $item): array
    {
        $p = $item['payload'];
        $title = Speech::title((string) ($p['title'] ?? ''));
        $by = Speech::title((string) ($p['artist'] ?? ''));
        $requested = !empty($p['request']) || ($item['submission_id'] ?? null) !== null;
        $entry = Drafter::isVideo($item) ? ['video' => (string) $p['kind'], 'title' => $title, 'by' => $by] : ['song' => $title, 'by' => $by];
        return $entry + ($requested ? ['requested' => true] : []);
    }

    /**
     * A host moment: what was said, when it may be quoted; else what it was.
     * What aired is the committed item's own text (exactly what was voiced);
     * a planned one's is its break's script so far.
     *
     * @param array<string,mixed> $item
     * @return array<string,mixed>|null
     */
    private function host(array $item, string $hostName): ?array
    {
        $b = (array) ($item['break'] ?? []);
        $kind = (string) ($b['kind'] ?? $item['payload']['kind'] ?? '');
        if ($kind === '') return null;
        if (in_array($kind, HostWriter::READINGS, true)) return ['read_out' => 1, 'what' => $kind === 'reading' ? 'prayer requests' : 'prayers'];
        if ($kind === 'opening') return ['opening_prayer' => 'spoken'];
        $committed = ($item['state'] ?? 'committed') !== 'draft';
        // A planned moment that will not air (failed, cancelled): its draft goes too.
        if (!$committed && in_array((string) ($b['state'] ?? ''), ['failed', 'cancelled'], true)) return null;
        $context = (array) ($b['context'] ?? []);
        $texts = $committed ? (array) ($item['payload']['text'] ?? []) : (array) ($b['texts'] ?? []);
        $texts = array_filter(array_map(fn($t) => trim((string) $t), $texts), fn($t) => $t !== '');
        $entry = ['host' => $kind];
        $by = $committed ? (string) ($item['payload']['host']['name'] ?? '') : (string) ($this->app->hosts()->get((int) ($context['host_id'] ?? 0))['name'] ?? '');
        if ($by !== '' && $hostName !== '' && mb_strtolower($by) !== mb_strtolower($hostName)) $entry['spoken_by'] = $by;
        if (!$texts) return $entry + ['summary' => $committed ? 'nothing kept' : 'not written yet'];
        if (!self::quotable($kind, (string) ($b['source'] ?? ''), $context)) {
            // Its words are not quoted, so the next moment learns this way which song's fact was told: the song, never a name.
            $side = (string) ($context['fact_told'] ?? '');
            $told = $side !== '' && isset($context[$side]['title']) ? ['told_fact_of' => (string) $context[$side]['title']] : [];
            return $entry + ['summary' => self::summary($kind, $context)] + $told;
        }
        return $entry + ['said' => $texts];
    }

    /**
     * What a moment that named a listener did, without them.
     *
     * @param array<string,mixed> $c
     */
    private static function summary(string $kind, array $c): string
    {
        return match (true) {
            $kind === 'announce' => !empty($c['request']['type']) ? 'presented a video a listener suggested' : "presented a listener's song request",
            $kind === 'contrib' => "introduced a listener's recording",
            !empty($c['previous_request']) => "reacted to a listener's request, then went on",
            !empty($c['community']) => 'passed on words from the community chat',
            default => "spoke about listeners' requests",
        };
    }

    /** @param array<string,mixed> $memory */
    private static function size(array $memory): int
    {
        return strlen((string) json_encode($memory, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function clock(int $ms, \DateTimeZone $zone): string
    {
        return (new \DateTimeImmutable('@' . intdiv($ms, 1000)))->setTimezone($zone)->format('H:i');
    }
}

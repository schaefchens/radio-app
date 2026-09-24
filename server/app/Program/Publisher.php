<?php
declare(strict_types=1);

namespace Arche\Program;

use Arche\App;
use Arche\Support\Files;

/**
 * Writes the static program files under /program (see shared/src/program.ts
 * for their shape and shared/fixtures for examples):
 *
 *   channels.json                  on change
 *   <ch>/slots/YYYYMMDD/HHMM.json  one per minute, immutable, up to now + LEAD
 *   <ch>/live.json                 every tick (listeners, voices, blocked, pulse)
 *   <ch>/days/YYYY-MM-DD.json      structure + what already played
 *   <ch>/evergreen-<hash>.json     the fallback loop, versioned by content
 *
 * Only committed items are published, and a minute file is only written once
 * every item overlapping its window is committed. The files are the only
 * thing listeners fetch from here, which is what lets a CDN serve millions.
 */
final class Publisher
{
    /** Epoch of the evergreen loop; any fixed instant works as long as it never changes. */
    public const EVERGREEN_EPOCH = 1767225600000; // 2026-01-01T00:00:00Z

    public function __construct(private App $app) {}

    // --- minute files ------------------------------------------------------------

    /** @param array<string,mixed> $channel @return int files written */
    public function publishSlots(array $channel): int
    {
        $cid = (int) $channel['id'];
        $store = $this->app->store();
        $now = $this->app->clock->nowMs();
        $first = Timing::floorMinute($now);
        $last = Timing::floorMinute($now + Timing::LEAD);
        $done = $store->get("published:$cid");
        $from = $done === null ? $first : max($first, (int) $done + Timing::MINUTE);
        $frontier = $this->app->committer()->frontier($cid);
        $written = 0;
        // The one public level no file is written into directly (a day's
        // folder, program/<ch> and days/ are repaired by their own writes).
        Files::ensureDir($this->app->publicPath('program/' . $channel['slug'] . '/slots'));

        for ($m = $from; $m <= $last; $m += Timing::MINUTE) {
            if ($frontier === null || $m + Timing::WINDOW > $frontier) break;
            $path = $this->app->publicPath(Timing::slotPath((string) $channel['slug'], $m));
            if (!is_file($path)) {
                if (Files::write($path, Files::json($this->slot($channel, $m)), false)) $written++;
            }
            $store->set("published:$cid", $m);
        }
        return $written;
    }

    /** @param array<string,mixed> $channel @return array<string,mixed> */
    public function slot(array $channel, int $minute): array
    {
        $cid = (int) $channel['id'];
        $catalog = $this->app->catalog();
        $block = $this->app->resolver()->blockAt($channel, $minute);
        $current = $catalog->program($block['program_id']);

        $items = [];
        $programIds = [$block['program_id'] => true];
        foreach ($this->app->timeline()->committedOverlapping($cid, $minute, $minute + Timing::WINDOW) as $it) {
            $items[] = $this->item($it);
            if ($it['program_id'] !== null) $programIds[$it['program_id']] = true;
        }

        $next = null;
        $nextBlock = $this->app->resolver()->blockAt($channel, $block['end']);
        if ($nextBlock['program_id'] !== $block['program_id']) {
            $programIds[$nextBlock['program_id']] = true;
            $nextProgram = $catalog->program($nextBlock['program_id']);
            if ($nextProgram !== null) $next = ['p' => (string) $nextProgram['slug'], 'start' => $block['end']];
        }

        $programs = [];
        foreach (array_keys($programIds) as $pid) {
            $p = $catalog->program($pid);
            if ($p !== null) $programs[(string) $p['slug']] = $catalog->programRef($p);
        }

        return [
            'v' => 1,
            'channel' => (string) $channel['slug'],
            't' => $minute,
            'gen' => $this->app->clock->nowMs(),
            'current' => $current !== null ? (string) $current['slug'] : null,
            'next' => $next,
            'submissions' => $current !== null ? SubmissionWindow::states($this->app, $channel, $current, $block, $minute) : new \stdClass(),
            'programs' => $programs ?: new \stdClass(),
            'items' => $items,
        ];
    }

    /** @param array<string,mixed> $it committed timeline row @return array<string,mixed> */
    public function item(array $it): array
    {
        $program = $it['program_id'] !== null ? $this->app->catalog()->program($it['program_id']) : null;
        $base = [
            'id' => (string) $it['uid'],
            'type' => (string) $it['type'],
            'start' => (int) $it['start_ms'],
            'dur' => (int) $it['dur_ms'],
            'p' => $program !== null ? (string) $program['slug'] : '',
        ];
        $p = $it['payload'];
        return match ($it['type']) {
            'song' => $base + [
                'yt' => (string) ($p['yt'] ?? ''),
                'title' => (string) ($p['title'] ?? ''),
                'artist' => (string) ($p['artist'] ?? ''),
                'thumb' => $p['thumb'] ?? null,
                'request' => $p['request'] ?? null,
                'fallback' => $p['fallback'] ?? null,
            ],
            'host' => $base + [
                'kind' => (string) ($p['kind'] ?? 'break'),
                'audio' => (object) ($p['audio'] ?? []),
                'text' => (object) ($p['text'] ?? []),
                'voices' => array_values((array) ($p['voices'] ?? [])),
            ],
            'jingle' => $base + ['audio' => (string) ($p['audio'] ?? '')],
            'silence', 'stage' => $base + ['label' => $p['label'] ?? ['en' => '', 'de' => '']],
            'contrib' => $base + [
                'kind' => (string) ($p['kind'] ?? 'story'),
                'audio' => (string) ($p['audio'] ?? ''),
                'caption' => (object) ($p['caption'] ?? []),
                'name' => (string) ($p['name'] ?? ''),
                'place' => (string) ($p['place'] ?? ''),
            ],
            default => $base,
        };
    }

    // --- live.json -----------------------------------------------------------------

    /** @param array<string,mixed> $channel */
    public function publishLive(array $channel): void
    {
        $slug = (string) $channel['slug'];
        $live = [
            'v' => 1,
            'channel' => $slug,
            'gen' => $this->app->clock->nowMs(),
            'listeners' => $this->app->presence()->listeners($slug),
            'voices' => $this->app->presence()->voices($slug),
            'blocked' => $this->blocked((int) $channel['id']),
            'pulse' => $this->app->config->int('PULSE_SECONDS', 120),
        ];
        Files::write($this->app->publicPath("program/$slug/live.json"), Files::json($live));
    }

    /** @return list<string> uids pulled from air that are still ahead or on air */
    private function blocked(int $channelId): array
    {
        $rows = $this->app->store()->all(
            "SELECT uid FROM timeline_items WHERE channel_id = ? AND state = 'committed' AND start_ms + dur_ms > ?
             AND blocked = 1",
            [$channelId, $this->app->clock->nowMs()],
        );
        return array_map(fn($r) => (string) $r['uid'], $rows);
    }

    // --- days ------------------------------------------------------------------------

    /** @param array<string,mixed> $channel @return array<string,mixed> */
    public function day(array $channel, string $date): array
    {
        $catalog = $this->app->catalog();
        $blocks = $this->app->resolver()->blocksForDate($channel, $date);
        $programs = [];
        $outBlocks = [];
        foreach ($blocks as $b) {
            $p = $catalog->program($b['program_id']);
            if ($p === null) continue;
            $programs[(string) $p['slug']] = $catalog->programRef($p, true);
            $outBlocks[] = ['start' => $b['start'], 'end' => $b['end'], 'p' => (string) $p['slug']];
        }
        $played = [];
        if ($blocks) {
            $to = min(end($blocks)['end'], $this->app->clock->nowMs() + 1);
            foreach ($this->app->timeline()->played((int) $channel['id'], $blocks[0]['start'], $to) as $it) {
                $program = $it['program_id'] !== null ? $catalog->program($it['program_id']) : null;
                $p = $it['payload'];
                $played[] = [
                    'start' => (int) $it['start_ms'],
                    'type' => (string) $it['type'],
                    'title' => $it['type'] === 'song'
                        ? (string) ($p['title'] ?? '')
                        : trim(($p['name'] ?? '') . (($p['place'] ?? '') !== '' ? ', ' . $p['place'] : '')),
                    'artist' => $it['type'] === 'song' ? (string) ($p['artist'] ?? '') : '',
                    'thumb' => $it['type'] === 'song' ? ($p['thumb'] ?? null) : null,
                    'p' => $program !== null ? (string) $program['slug'] : '',
                ];
            }
        }
        return [
            'v' => 1,
            'channel' => (string) $channel['slug'],
            'date' => $date,
            'tz' => (string) $channel['timezone'],
            'gen' => $this->app->clock->nowMs(),
            'blocks' => $outBlocks,
            'programs' => $programs ?: new \stdClass(),
            'played' => $played,
        ];
    }

    /**
     * Yesterday through six days ahead. Past and current days change as
     * things air; future days only when the plan does, but rewriting eight
     * small files is cheaper than tracking which ones changed.
     *
     * @param array<string,mixed> $channel
     */
    public function publishDays(array $channel): int
    {
        $tz = $this->app->resolver()->zone($channel);
        $today = (new \DateTimeImmutable('@' . $this->app->clock->now()))->setTimezone($tz);
        $n = 0;
        for ($d = -1; $d <= 6; $d++) {
            $date = $today->modify(sprintf('%+d days', $d))->format('Y-m-d');
            Files::write($this->app->publicPath("program/{$channel['slug']}/days/$date.json"), Files::json($this->day($channel, $date)));
            $n++;
        }
        return $n;
    }

    // --- channels.json + evergreen ------------------------------------------------------

    /** @return bool whether the file changed */
    public function publishChannels(): bool
    {
        $channels = [];
        foreach ($this->app->catalog()->channels() as $c) {
            $channels[] = [
                'id' => (string) $c['slug'],
                'name' => ['en' => (string) $c['name_en'], 'de' => (string) $c['name_de']],
                'main' => (bool) $c['is_main'],
                'tz' => (string) $c['timezone'],
                'color' => (string) $c['color'],
                'host' => ['name' => (string) $c['host_name'], 'avatar' => $c['host_avatar'] ?: null],
                'evergreen' => $this->app->store()->get('evergreen:' . $c['id']),
            ];
        }
        $features = [
            'songRequests' => SubmissionWindow::featureOn($this->app, 'song'),
            'contributions' => SubmissionWindow::featureOn($this->app, 'story'),
            'realtime' => $this->app->config->get('REALTIME_DRIVER') !== 'off',
        ];
        $body = ['v' => 1, 'minClient' => 1, 'channels' => $channels, 'features' => $features];
        $hash = hash('sha256', Files::json($body));
        $path = $this->app->publicPath('program/channels.json');
        if ($this->app->store()->get('channels_hash') === $hash && is_file($path)) return false;
        Files::write($path, Files::json(['v' => 1, 'gen' => $this->app->clock->nowMs()] + $body));
        $this->app->store()->set('channels_hash', $hash);
        return true;
    }

    /** @param array<string,mixed> $channel */
    public function publishEvergreen(array $channel): ?string
    {
        $tracks = [];
        foreach ($this->app->library()->evergreen((int) $channel['id']) as $s) {
            $tracks[] = ['yt' => (string) $s['yt_id'], 'title' => (string) $s['title'], 'artist' => (string) $s['artist'],
                'dur' => (int) $s['duration_ms'], 'thumb' => $s['thumb'] ?: null];
        }
        $key = 'evergreen:' . $channel['id'];
        if (!$tracks) {
            $this->app->store()->set($key, null);
            return null;
        }
        $body = ['v' => 1, 'channel' => (string) $channel['slug'], 'epoch' => self::EVERGREEN_EPOCH,
            'total' => array_sum(array_column($tracks, 'dur')), 'items' => $tracks];
        $json = Files::json($body);
        $url = sprintf('/program/%s/evergreen-%s.json', $channel['slug'], substr(hash('sha256', $json), 0, 10));
        Files::write($this->app->publicPath(ltrim($url, '/')), $json, false);
        $this->app->store()->set($key, $url);
        return $url;
    }
}

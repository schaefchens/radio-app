<?php
declare(strict_types=1);

namespace Arche\Plan;

use Arche\ApiError;
use Arche\App;

/**
 * Channels, programs and plans — the Plan layer. Only moderators write here;
 * everything the Generation layer may do inside a slot is read from a
 * program's row (allowed submission types, themes, host density).
 *
 * Every write bumps `plan_version`, which tells the Drafter to throw away its
 * unpublished drafts and the PlanResolver to forget its cache.
 */
final class Catalog
{
    public const SUBMISSION_TYPES = ['song', 'story', 'testimony', 'greeting', 'prayer', 'preaching', 'testimony_video', 'mission', 'film', 'intercession'];
    public const STAGE_MODES = ['image', 'ambient', 'flyins'];
    public const FORMATS = ['music', 'prayer', 'preaching', 'testimony', 'mission', 'film'];
    /**
     * The video programs: each plays videos of the library kind of its name
     * (on air a song item of that kind, introduced by the host moment of that
     * name), and listeners suggest them with the submission type here. Not
     * `testimony` for a testimony: that type is a listener's own recording.
     */
    public const VIDEO_FORMATS = ['preaching' => 'preaching', 'testimony' => 'testimony_video', 'mission' => 'mission', 'film' => 'film'];

    /** @var array<string,mixed> */
    public const PROGRAM_DEFAULTS = [
        'host' => ['enabled' => true, 'every_songs' => 3, 'intro' => true, 'outro' => true],
        'jingle_every_songs' => 4,
        'silence' => ['every_min' => 0, 'dur_s' => 60],
        // Submission windows, minutes before the block ends. `closed` must
        // leave room for the check (a minute), the plan ahead (Timing::DRAFT)
        // and one song; see cleanSettings() for the floor.
        'closing_min' => 25,
        'closed_min' => 15,
        // Approved-but-unaired queue beyond this much airtime closes intake.
        'max_queue_min' => 30,
        'replay_contrib' => false,
        // 'prayer': the prayer hour's running order (Program\PrayerHour); a
        // video format (VIDEO_FORMATS): its videos with songs between them
        // (Drafter) — instead of music with the host between songs.
        'format' => 'music',
        'prayer' => [
            // The collection, while listeners send their requests: N songs,
            // then prayer music (a `bed` from the library) until it has
            // lasted N minutes.
            'collect' => ['songs' => 0, 'minutes' => 10, 'bed_id' => 0],
            // Quiet minutes in the prayer time before the host encourages listeners again.
            'quiet_min' => 4,
            'after_songs' => 0,
            // Open Doors' daily prayer request for persecuted Christians, read first.
            'opendoors' => true,
        ],
        // Every video format's, under the name of the first one: renamed, a
        // moderator's value would be lost to a /mod tab opened before the
        // deploy, or to a request served while the deploy uploads.
        'preaching' => [
            // Regular songs after a video before the next one may start.
            'songs_between' => 2,
        ],
    ];

    /** @var array<int,array<string,mixed>> */
    private array $programCache = [];

    public function __construct(private App $app) {}

    /** A program format that plays videos (VIDEO_FORMATS). */
    public static function isVideoFormat(mixed $format): bool
    {
        return is_string($format) && isset(self::VIDEO_FORMATS[$format]);
    }

    public function version(): int
    {
        return (int) ($this->app->store()->get('plan_version') ?? 0);
    }

    private function bump(string $actor, string $what): void
    {
        $this->app->store()->set('plan_version', $this->version() + 1);
        $this->programCache = [];
        $this->app->store()->audit($actor, 'Plan changed', $what);
    }

    // --- channels ---------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function channels(bool $activeOnly = true): array
    {
        return $this->app->store()->all(
            'SELECT * FROM channels' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY is_main DESC, sort, id',
        );
    }

    /** @return array<string,mixed>|null */
    public function channel(int $id): ?array
    {
        return $this->app->store()->one('SELECT * FROM channels WHERE id = ?', [$id]);
    }

    /** @return array<string,mixed>|null */
    public function channelBySlug(string $slug): ?array
    {
        return $this->app->store()->one('SELECT * FROM channels WHERE slug = ?', [$slug]);
    }

    /** @return array<string,mixed> */
    public function mainChannel(): array
    {
        $c = $this->app->store()->one('SELECT * FROM channels WHERE active = 1 ORDER BY is_main DESC, sort, id LIMIT 1');
        if ($c === null) throw new \RuntimeException('No active channel');
        return $c;
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function saveChannel(?int $id, array $data, string $actor): array
    {
        $row = [];
        foreach (['name_en', 'name_de', 'color', 'host_name', 'host_voice_en', 'host_voice_de', 'host_style', 'host_avatar'] as $k) {
            if (array_key_exists($k, $data)) $row[$k] = trim((string) $data[$k]);
        }
        if (isset($data['slug'])) {
            $slug = strtolower(trim((string) $data['slug']));
            if (!preg_match('/^[a-z][a-z0-9-]{1,23}$/', $slug)) throw new ApiError(422, 'invalid_slug');
            $row['slug'] = $slug;
        }
        if (isset($data['timezone'])) {
            $tz = (string) $data['timezone'];
            if (!in_array($tz, \DateTimeZone::listIdentifiers(), true)) throw new ApiError(422, 'invalid_timezone');
            $row['timezone'] = $tz;
        }
        foreach (['active', 'is_main'] as $k) {
            if (array_key_exists($k, $data)) $row[$k] = $data[$k] ? 1 : 0;
        }
        if (array_key_exists('sort', $data)) $row['sort'] = (int) $data['sort'];
        foreach (['default_day_plan_id', 'fallback_program_id'] as $k) {
            if (array_key_exists($k, $data)) $row[$k] = $data[$k] === null ? null : (int) $data[$k];
        }
        // A prayer hour's running order needs an end; a fallback runs all day.
        if (isset($row['fallback_program_id']) && ($this->program($row['fallback_program_id'])['settings']['format'] ?? '') === 'prayer') {
            throw new ApiError(422, 'prayer_fallback');
        }
        $now = $this->app->clock->now();
        $store = $this->app->store();
        // Its hosts (Host\Hosts): only when sent — an older /mod tab sends none.
        $hosts = is_array($data['hosts'] ?? null) ? $data['hosts'] : null;
        $id = $store->tx(function () use ($store, $id, $row, $now, $hosts) {
            if (($row['is_main'] ?? 0) === 1) $store->query('UPDATE channels SET is_main = 0');
            if ($id === null) {
                foreach (['slug', 'name_en', 'name_de'] as $req) {
                    if (($row[$req] ?? '') === '') throw new ApiError(422, 'missing_' . $req);
                }
                if ($store->one('SELECT id FROM channels WHERE slug = ?', [$row['slug']])) throw new ApiError(409, 'slug_taken');
                $id = $store->insert('channels', $row + ['created' => $now, 'updated' => $now]);
            } elseif ($row) {
                $store->update('channels', $row + ['updated' => $now], 'id = ?', [$id]);
            }
            if ($hosts !== null) $this->app->hosts()->setLineup('channel', $id, $hosts);
            return $id;
        });
        $this->bump($actor, 'channel ' . $id);
        return $this->channel($id) ?? throw new ApiError(404, 'not_found');
    }

    // --- programs -----------------------------------------------------------------

    /** @return array<string,mixed>|null program row with JSON columns decoded and settings defaulted */
    public function program(int $id): ?array
    {
        if (isset($this->programCache[$id])) return $this->programCache[$id];
        $row = $this->app->store()->one('SELECT * FROM programs WHERE id = ?', [$id]);
        if ($row === null) return null;
        return $this->programCache[$id] = $this->decodeProgram($row);
    }

    /** @return list<array<string,mixed>> */
    public function programs(int $channelId, bool $activeOnly = false): array
    {
        $rows = $this->app->store()->all(
            'SELECT * FROM programs WHERE channel_id = ?' . ($activeOnly ? ' AND active = 1' : '') . ' ORDER BY title_en',
            [$channelId],
        );
        return array_map(fn($r) => $this->decodeProgram($r), $rows);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function decodeProgram(array $row): array
    {
        foreach (['allowed', 'themes', 'moods'] as $k) {
            $v = json_decode((string) $row[$k], true);
            $row[$k] = is_array($v) ? array_values($v) : [];
        }
        $settings = json_decode((string) $row['settings'], true);
        $row['settings'] = array_replace_recursive(self::PROGRAM_DEFAULTS, is_array($settings) ? $settings : []);
        return $row;
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function saveProgram(?int $id, int $channelId, array $data, string $actor): array
    {
        $row = [];
        foreach (['title_en', 'title_de', 'subtitle_en', 'subtitle_de', 'description_en', 'description_de', 'tagline_en', 'tagline_de', 'color'] as $k) {
            if (array_key_exists($k, $data)) $row[$k] = mb_substr(trim((string) $data[$k]), 0, $k === 'description_en' || $k === 'description_de' ? 600 : 120);
        }
        if (isset($row['color']) && !preg_match('/^#[0-9a-fA-F]{6}$/', $row['color'])) throw new ApiError(422, 'invalid_color');
        if (isset($data['slug'])) {
            $slug = strtolower(trim((string) $data['slug']));
            if (!preg_match('/^[a-z][a-z0-9-]{1,31}$/', $slug)) throw new ApiError(422, 'invalid_slug');
            $row['slug'] = $slug;
        }
        if (isset($data['stage_mode'])) {
            if (!in_array($data['stage_mode'], self::STAGE_MODES, true)) throw new ApiError(422, 'invalid_stage_mode');
            $row['stage_mode'] = $data['stage_mode'];
        }
        if (array_key_exists('image', $data)) $row['image'] = $data['image'] === null ? null : (string) $data['image'];
        if (isset($data['allowed'])) {
            $allowed = array_values(array_intersect(self::SUBMISSION_TYPES, (array) $data['allowed']));
            $row['allowed'] = json_encode($allowed);
        }
        foreach (['themes', 'moods'] as $k) {
            if (isset($data[$k])) $row[$k] = json_encode(self::tags((array) $data[$k]));
        }
        if (isset($data['settings']) && is_array($data['settings'])) {
            $row['settings'] = json_encode(self::cleanSettings($data['settings']), JSON_THROW_ON_ERROR);
        }
        if (array_key_exists('active', $data)) $row['active'] = $data['active'] ? 1 : 0;
        $format = isset($row['settings'])
            ? (string) json_decode($row['settings'], true)['format']
            : (string) ($id !== null ? ($this->program($id)['settings']['format'] ?? 'music') : 'music');
        if ($format === 'prayer') {
            // A prayer hour takes prayer requests (typed or recorded) and, in
            // its prayer time, listeners' prayers — nothing else …
            $row['allowed'] = json_encode(['prayer', 'intercession']);
            // … and cannot fill a plan's gaps: its running order needs an end.
            if ($id !== null && $this->app->store()->value('SELECT COUNT(*) FROM channels WHERE fallback_program_id = ?', [$id]) > 0) {
                throw new ApiError(422, 'prayer_fallback');
            }
        } else {
            // Only a video program plays suggested videos (any of the four
            // kinds it allows), only a prayer hour has a prayer time: sent to
            // any other, they would wait for a moment that never comes.
            $allowed = isset($row['allowed']) ? (array) json_decode($row['allowed'], true) : ($id !== null ? ($this->program($id)['allowed'] ?? []) : []);
            $drop = self::isVideoFormat($format) ? ['intercession'] : [...array_values(self::VIDEO_FORMATS), 'intercession'];
            if (array_intersect($allowed, $drop)) $row['allowed'] = json_encode(array_values(array_diff($allowed, $drop)));
        }

        $now = $this->app->clock->now();
        $store = $this->app->store();
        // Its hosts (Host\Hosts) live outside `settings`: cleanSettings() would
        // reset them from an older /mod tab, which sends none — written only when sent.
        $hosts = is_array($data['hosts'] ?? null) ? $data['hosts'] : null;
        $id = $store->tx(function () use ($store, $id, $channelId, $row, $now, $hosts) {
            if ($id === null) {
                foreach (['slug', 'title_en', 'title_de'] as $req) {
                    if (($row[$req] ?? '') === '') throw new ApiError(422, 'missing_' . $req);
                }
                if ($store->one('SELECT id FROM programs WHERE channel_id = ? AND slug = ?', [$channelId, $row['slug']])) {
                    throw new ApiError(409, 'slug_taken');
                }
                $id = $store->insert('programs', $row + ['channel_id' => $channelId, 'created' => $now, 'updated' => $now]);
            } elseif ($row) {
                $store->update('programs', $row + ['updated' => $now], 'id = ?', [$id]);
            }
            if ($hosts !== null) $this->app->hosts()->setLineup('program', $id, $hosts);
            return $id;
        });
        $this->bump($actor, 'program ' . $id);
        return $this->program($id) ?? throw new ApiError(404, 'not_found');
    }

    public function deleteProgram(int $id, string $actor): void
    {
        $store = $this->app->store();
        $inUse = (int) $store->value('SELECT COUNT(*) FROM day_plan_blocks WHERE program_id = ?', [$id])
            + (int) $store->value('SELECT COUNT(*) FROM channels WHERE fallback_program_id = ?', [$id]);
        if ($inUse > 0) throw new ApiError(409, 'program_in_use');
        // Its prepared opening prayers go along, recordings included; its
        // lineup with the row (ON DELETE CASCADE), its shows' host picks here.
        $this->app->openingPrayers()->purge(0, $id);
        $channelId = (int) $store->value('SELECT channel_id FROM programs WHERE id = ?', [$id]);
        $store->query('DELETE FROM programs WHERE id = ?', [$id]);
        $this->app->hosts()->forgetProgram($channelId, $id);
        $this->bump($actor, 'program deleted ' . $id);
    }

    /** @param array<mixed> $settings @return array<string,mixed> */
    private static function cleanSettings(array $settings): array
    {
        $s = array_replace_recursive(self::PROGRAM_DEFAULTS, $settings);
        $host = is_array($s['host']) ? $s['host'] : [];
        $silence = is_array($s['silence']) ? $s['silence'] : [];
        $prayer = is_array($s['prayer']) ? $s['prayer'] : [];
        $collect = is_array($prayer['collect'] ?? null) ? $prayer['collect'] : [];
        $preaching = is_array($s['preaching']) ? $s['preaching'] : [];
        return [
            'host' => [
                'enabled' => (bool) ($host['enabled'] ?? true),
                'every_songs' => max(1, min(12, (int) ($host['every_songs'] ?? 3))),
                'intro' => (bool) ($host['intro'] ?? true),
                'outro' => (bool) ($host['outro'] ?? true),
            ],
            'jingle_every_songs' => max(0, min(20, (int) $s['jingle_every_songs'])),
            'silence' => [
                'every_min' => max(0, min(240, (int) ($silence['every_min'] ?? 0))),
                'dur_s' => max(10, min(300, (int) ($silence['dur_s'] ?? 60))),
            ],
            'closing_min' => max(0, min(120, (int) $s['closing_min'])),
            // Below this a request sent at the last moment could not air in time.
            'closed_min' => max(intdiv(\Arche\Program\Timing::DRAFT + \Arche\Program\Timing::MIN_SONG, 60_000) + 1, min(120, (int) $s['closed_min'])),
            'max_queue_min' => max(5, min(180, (int) $s['max_queue_min'])),
            'replay_contrib' => (bool) $s['replay_contrib'],
            'format' => in_array($s['format'], self::FORMATS, true) ? $s['format'] : 'music',
            'prayer' => [
                'collect' => [
                    'songs' => max(0, min(3, (int) ($collect['songs'] ?? 0))),
                    'minutes' => max(3, min(20, (int) ($collect['minutes'] ?? 10))),
                    'bed_id' => max(0, (int) ($collect['bed_id'] ?? 0)),
                ],
                'quiet_min' => max(2, min(15, (int) ($prayer['quiet_min'] ?? 4))),
                'after_songs' => max(0, min(5, (int) ($prayer['after_songs'] ?? 0))),
                'opendoors' => (bool) ($prayer['opendoors'] ?? true),
            ],
            'preaching' => [
                'songs_between' => max(0, min(10, (int) ($preaching['songs_between'] ?? 2))),
            ],
        ];
    }

    /** @param array<mixed> $tags @return list<string> */
    public static function tags(array $tags): array
    {
        $out = [];
        foreach ($tags as $t) {
            $t = strtolower(trim((string) $t));
            if ($t !== '' && preg_match('/^[a-z][a-z0-9 -]{0,23}$/', $t)) $out[$t] = true;
        }
        return array_slice(array_keys($out), 0, 12);
    }

    /**
     * The public, per-program shape inside the program files.
     *
     * @param array<string,mixed> $p decoded program
     * @return array<string,mixed>
     */
    public function programRef(array $p, bool $withDescription = false): array
    {
        $ref = [
            'id' => (string) $p['slug'],
            'title' => ['en' => (string) $p['title_en'], 'de' => (string) $p['title_de']],
            'subtitle' => ['en' => (string) $p['subtitle_en'], 'de' => (string) $p['subtitle_de']],
            'color' => (string) $p['color'],
            'stage' => [
                'mode' => (string) $p['stage_mode'],
                'image' => $p['image'] ?: null,
                'tagline' => ['en' => (string) $p['tagline_en'], 'de' => (string) $p['tagline_de']],
            ],
            'allowed' => $p['allowed'],
            'format' => in_array($p['settings']['format'], self::FORMATS, true) ? $p['settings']['format'] : 'music',
        ];
        if ($withDescription) {
            $ref['description'] = ['en' => (string) $p['description_en'], 'de' => (string) $p['description_de']];
        }
        return $ref;
    }

    // --- plans ----------------------------------------------------------------------

    /** @return list<array<string,mixed>> day plans with their blocks */
    public function dayPlans(int $channelId): array
    {
        $plans = $this->app->store()->all('SELECT * FROM day_plans WHERE channel_id = ? ORDER BY name', [$channelId]);
        foreach ($plans as &$plan) $plan['blocks'] = $this->blocks((int) $plan['id']);
        return $plans;
    }

    /** @return list<array{start_min:int,end_min:int,program_id:int}> */
    public function blocks(int $dayPlanId): array
    {
        $rows = $this->app->store()->all(
            'SELECT start_min, end_min, program_id FROM day_plan_blocks WHERE day_plan_id = ? ORDER BY start_min',
            [$dayPlanId],
        );
        return array_map(fn($r) => [
            'start_min' => (int) $r['start_min'],
            'end_min' => (int) $r['end_min'],
            'program_id' => (int) $r['program_id'],
        ], $rows);
    }

    /**
     * Create or replace a day plan with its blocks. Blocks may leave gaps (the
     * channel's fallback program fills them) but must not overlap.
     *
     * @param list<array<string,mixed>> $blocks
     */
    public function saveDayPlan(?int $id, int $channelId, string $name, array $blocks, string $actor): int
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 60) throw new ApiError(422, 'invalid_name');
        $clean = [];
        foreach ($blocks as $b) {
            $start = (int) ($b['start_min'] ?? -1);
            $end = (int) ($b['end_min'] ?? -1);
            $pid = (int) ($b['program_id'] ?? 0);
            if ($start < 0 || $start >= 1440 || $end <= $start || $end > 1440) throw new ApiError(422, 'invalid_block');
            $program = $this->program($pid);
            if ($program === null || (int) $program['channel_id'] !== $channelId) throw new ApiError(422, 'invalid_program');
            $clean[] = ['start_min' => $start, 'end_min' => $end, 'program_id' => $pid];
        }
        usort($clean, fn($a, $b) => $a['start_min'] <=> $b['start_min']);
        for ($i = 1; $i < count($clean); $i++) {
            if ($clean[$i]['start_min'] < $clean[$i - 1]['end_min']) throw new ApiError(422, 'overlapping_blocks');
        }
        $store = $this->app->store();
        $now = $this->app->clock->now();
        $id = $store->tx(function () use ($store, $id, $channelId, $name, $clean, $now) {
            if ($id === null) {
                $id = $store->insert('day_plans', ['channel_id' => $channelId, 'name' => $name, 'created' => $now, 'updated' => $now]);
            } else {
                $store->update('day_plans', ['name' => $name, 'updated' => $now], 'id = ? AND channel_id = ?', [$id, $channelId]);
                $store->query('DELETE FROM day_plan_blocks WHERE day_plan_id = ?', [$id]);
            }
            foreach ($clean as $b) $store->insert('day_plan_blocks', $b + ['day_plan_id' => $id]);
            return $id;
        });
        $this->bump($actor, 'day plan ' . $id);
        return $id;
    }

    public function deleteDayPlan(int $id, string $actor): void
    {
        $store = $this->app->store();
        $used = (int) $store->value('SELECT COUNT(*) FROM week_plan WHERE day_plan_id = ?', [$id])
            + (int) $store->value('SELECT COUNT(*) FROM special_days WHERE day_plan_id = ?', [$id])
            + (int) $store->value('SELECT COUNT(*) FROM channels WHERE default_day_plan_id = ?', [$id]);
        if ($used > 0) throw new ApiError(409, 'day_plan_in_use');
        $store->query('DELETE FROM day_plans WHERE id = ?', [$id]);
        $this->bump($actor, 'day plan deleted ' . $id);
    }

    /** @return array<int,int> ISO weekday (1 = Monday) → day plan id */
    public function weekPlan(int $channelId): array
    {
        $out = [];
        foreach ($this->app->store()->all('SELECT weekday, day_plan_id FROM week_plan WHERE channel_id = ?', [$channelId]) as $r) {
            $out[(int) $r['weekday']] = (int) $r['day_plan_id'];
        }
        return $out;
    }

    /** @param array<int|string,int|null> $map weekday → day plan id (null removes) */
    public function setWeekPlan(int $channelId, array $map, string $actor): void
    {
        $store = $this->app->store();
        $store->tx(function () use ($store, $channelId, $map) {
            foreach ($map as $weekday => $planId) {
                $weekday = (int) $weekday;
                if ($weekday < 1 || $weekday > 7) throw new ApiError(422, 'invalid_weekday');
                $store->query('DELETE FROM week_plan WHERE channel_id = ? AND weekday = ?', [$channelId, $weekday]);
                if ($planId === null) continue;
                if (!$store->one('SELECT id FROM day_plans WHERE id = ? AND channel_id = ?', [(int) $planId, $channelId])) {
                    throw new ApiError(422, 'invalid_day_plan');
                }
                $store->insert('week_plan', ['channel_id' => $channelId, 'weekday' => $weekday, 'day_plan_id' => (int) $planId]);
            }
        });
        $this->bump($actor, 'week plan ' . $channelId);
    }

    /** @return list<array<string,mixed>> */
    public function specialDays(int $channelId): array
    {
        return $this->app->store()->all('SELECT * FROM special_days WHERE channel_id = ? ORDER BY kind, month, day, easter_offset', [$channelId]);
    }

    /** @param array<string,mixed> $d */
    public function addSpecialDay(int $channelId, array $d, string $actor): int
    {
        $name = trim((string) ($d['name'] ?? ''));
        $kind = (string) ($d['kind'] ?? '');
        $planId = (int) ($d['day_plan_id'] ?? 0);
        if ($name === '') throw new ApiError(422, 'missing_name');
        if (!$this->app->store()->one('SELECT id FROM day_plans WHERE id = ? AND channel_id = ?', [$planId, $channelId])) {
            throw new ApiError(422, 'invalid_day_plan');
        }
        $row = ['channel_id' => $channelId, 'name' => mb_substr($name, 0, 60), 'kind' => $kind, 'day_plan_id' => $planId, 'created' => $this->app->clock->now()];
        if ($kind === 'date') {
            $month = (int) ($d['month'] ?? 0);
            $day = (int) ($d['day'] ?? 0);
            $year = isset($d['year']) && $d['year'] !== null && $d['year'] !== '' ? (int) $d['year'] : null;
            if (!checkdate($month, $day, $year ?? 2024)) throw new ApiError(422, 'invalid_date');
            $row += ['month' => $month, 'day' => $day, 'year' => $year];
        } elseif ($kind === 'easter') {
            $offset = (int) ($d['easter_offset'] ?? 0);
            if ($offset < -70 || $offset > 70) throw new ApiError(422, 'invalid_offset');
            $row += ['easter_offset' => $offset];
        } else {
            throw new ApiError(422, 'invalid_kind');
        }
        $id = $this->app->store()->insert('special_days', $row);
        $this->bump($actor, 'special day ' . $id);
        return $id;
    }

    public function deleteSpecialDay(int $channelId, int $id, string $actor): void
    {
        $this->app->store()->query('DELETE FROM special_days WHERE id = ? AND channel_id = ?', [$id, $channelId]);
        $this->bump($actor, 'special day deleted ' . $id);
    }
}

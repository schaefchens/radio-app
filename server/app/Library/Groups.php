<?php
declare(strict_types=1);

namespace Arche\Library;

use Arche\ApiError;
use Arche\App;

/**
 * Groups of library items a moderator makes: a preacher, a church, a ministry,
 * an artist — with a few words about them and links to their channel or
 * website. Two switches:
 *
 *   notice   after one of its items, the host's next word points to more
 *            from them, and the stage shows their links meanwhile
 *            (HostWriter::context(), HostBreaks::payload()) — no host, no
 *            notice: the host is what makes it a moment;
 *   blocked  they asked not to be on our platform: nothing of theirs is
 *            accepted from listeners or moderators, and their items in the
 *            library never play (selection, fallback loop, requests waiting).
 *
 * A video is theirs by its YouTube channel (one channel belongs to one group),
 * by a moderator putting it in the group, or — for blocking only, which must
 * also catch the re-uploads of their songs on other channels — by the artist
 * its title names, exactly as the group lists the names.
 */
final class Groups
{
    private const MAX_LINKS = 4;
    private const LINK_KINDS = ['youtube', 'website', 'other'];

    /** @var array<int,array<string,mixed>>|null */
    private ?array $cache = null;

    public function __construct(private App $app) {}

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function decode(array $row): array
    {
        foreach (['links', 'channels', 'names'] as $k) {
            $v = json_decode((string) $row[$k], true);
            $row[$k] = is_array($v) ? array_values($v) : [];
        }
        foreach (['id', 'notice', 'blocked'] as $k) $row[$k] = (int) $row[$k];
        return $row;
    }

    /** @return array<int,array<string,mixed>> every group by id */
    private function byId(): array
    {
        if ($this->cache !== null) return $this->cache;
        $out = [];
        foreach ($this->app->store()->all('SELECT * FROM library_groups ORDER BY name, id') as $r) {
            $g = self::decode($r);
            $out[$g['id']] = $g;
        }
        return $this->cache = $out;
    }

    /** @return list<array<string,mixed>> for /mod, with how many library items each holds */
    public function all(): array
    {
        $counts = [];
        foreach ($this->app->store()->all('SELECT group_id, COUNT(*) AS n FROM library_items WHERE group_id IS NOT NULL GROUP BY group_id') as $r) {
            $counts[(int) $r['group_id']] = (int) $r['n'];
        }
        return array_values(array_map(fn(array $g): array => $g + ['items' => $counts[$g['id']] ?? 0], $this->byId()));
    }

    /** @return array<string,mixed>|null */
    public function get(int $id): ?array
    {
        return $this->byId()[$id] ?? null;
    }

    public function isBlocked(?int $id): bool
    {
        return $id !== null && (bool) ($this->get($id)['blocked'] ?? false);
    }

    /** A name as titles and channels are compared: lower case, letters and digits only ("Olaf Latzel" = "olaf-latzel"). */
    public static function norm(string $s): string
    {
        return mb_strtolower((string) preg_replace('/[^\p{L}\p{N}]+/u', '', $s));
    }

    /** @param array<string,mixed> $g @return list<string> the names a blocked group is recognised by */
    private static function matchNames(array $g): array
    {
        return array_values(array_filter(array_map([self::class, 'norm'], $g['names'] ?: [(string) $g['name']])));
    }

    /**
     * @param array<string,mixed> $data name, about_en, about_de, links, channels, names, notice, blocked, note
     * @return array<string,mixed>
     */
    public function save(?int $id, array $data, string $actor): array
    {
        $before = $id !== null ? ($this->get($id) ?? throw new ApiError(404, 'not_found')) : null;
        $row = [];
        if (array_key_exists('name', $data) || $before === null) {
            $name = trim((string) ($data['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 60) throw new ApiError(422, 'invalid_name');
            $row['name'] = $name;
        }
        foreach (['about_en', 'about_de'] as $k) {
            if (!array_key_exists($k, $data)) continue;
            $about = trim((string) preg_replace('/\s+/u', ' ', (string) $data[$k]));
            // Shown on the stage while the host speaks: a line or two, no essay.
            if (mb_strlen($about) > 200) throw new ApiError(422, 'about_too_long');
            $row[$k] = $about;
        }
        if (array_key_exists('links', $data)) $row['links'] = json_encode(self::links((array) $data['links']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (array_key_exists('channels', $data)) $row['channels'] = json_encode(self::channels((array) $data['channels']), JSON_UNESCAPED_UNICODE);
        if (array_key_exists('names', $data)) {
            $names = array_values(array_unique(array_filter(array_map(fn($n) => mb_substr(trim((string) $n), 0, 60), (array) $data['names']))));
            if (count($names) > 10) throw new ApiError(422, 'too_many_names');
            $row['names'] = json_encode($names, JSON_UNESCAPED_UNICODE);
        }
        foreach (['notice', 'blocked'] as $k) {
            if (array_key_exists($k, $data)) $row[$k] = $data[$k] ? 1 : 0;
        }
        if (array_key_exists('note', $data)) $row['note'] = mb_substr(trim((string) $data['note']), 0, 500);

        // One channel, one group: a video must not be two groups' — the one
        // that wants a notice and the one that asked to be blocked.
        $channels = isset($row['channels']) ? array_column((array) json_decode($row['channels'], true), 'id') : array_column($before['channels'] ?? [], 'id');
        foreach ($this->byId() as $g) {
            if ($g['id'] === $id) continue;
            if (array_intersect($channels, array_column($g['channels'], 'id'))) throw new ApiError(409, 'channel_in_group', ['group' => $g['name']]);
        }

        $store = $this->app->store();
        $now = $this->app->clock->now();
        if ($id === null) {
            $id = $store->insert('library_groups', $row + ['created' => $now, 'updated' => $now]);
        } elseif ($row) {
            $store->update('library_groups', $row + ['updated' => $now], 'id = ?', [$id]);
        }
        $this->cache = null;
        $group = $this->get($id) ?? throw new \LogicException('group vanished');
        $this->assign($group);
        // Blocked now — or what it is recognised by changed while blocked:
        // what is planned or on air of theirs leaves at once.
        $recognise = fn(?array $g): array => $g === null ? [] : [array_column($g['channels'], 'id'), self::matchNames($g)];
        if ($group['blocked'] && (!($before['blocked'] ?? false) || $recognise($before) !== $recognise($group))) $this->pull($group);
        $store->audit($actor, $before === null ? 'Group added' : 'Group changed', $id . ' ' . $group['name'] . ($group['blocked'] ? ' (blocked)' : ''));
        return $group + ['items' => (int) $store->value('SELECT COUNT(*) FROM library_items WHERE group_id = ?', [$id])];
    }

    /** Its items stay in the library, without a group. */
    public function delete(int $id, string $actor): void
    {
        $g = $this->get($id) ?? throw new ApiError(404, 'not_found');
        $store = $this->app->store();
        $store->tx(function () use ($store, $id): void {
            $store->query('UPDATE library_items SET group_id = NULL WHERE group_id = ?', [$id]);
            $store->query('DELETE FROM library_groups WHERE id = ?', [$id]);
        });
        $this->cache = null;
        $store->audit($actor, 'Group deleted', $id . ' ' . $g['name']);
    }

    /**
     * Links shown on the stage: https only (a `javascript:` link would run in
     * every listener's app), at most MAX_LINKS.
     *
     * @param array<mixed> $links
     * @return list<array{kind:string,url:string}>
     */
    private static function links(array $links): array
    {
        $out = [];
        foreach ($links as $l) {
            if (!is_array($l)) continue;
            $url = trim((string) ($l['url'] ?? ''));
            if ($url === '') continue;
            $host = parse_url($url, PHP_URL_HOST);
            if (!str_starts_with($url, 'https://') || !is_string($host) || $host === '' || mb_strlen($url) > 300) throw new ApiError(422, 'invalid_link');
            $kind = in_array($l['kind'] ?? '', self::LINK_KINDS, true) ? (string) $l['kind'] : 'other';
            $out[] = ['kind' => $kind, 'url' => $url];
        }
        if (count($out) > self::MAX_LINKS) throw new ApiError(422, 'too_many_links');
        return $out;
    }

    /** @param array<mixed> $channels @return list<array{id:string,title:string}> */
    private static function channels(array $channels): array
    {
        $out = [];
        foreach ($channels as $c) {
            $cid = trim((string) (is_array($c) ? ($c['id'] ?? '') : $c));
            if (!preg_match('/^UC[A-Za-z0-9_-]{22}$/', $cid)) throw new ApiError(422, 'invalid_channel');
            $out[$cid] = ['id' => $cid, 'title' => mb_substr(trim((string) (is_array($c) ? ($c['title'] ?? '') : '')), 0, 100)];
        }
        if (count($out) > 10) throw new ApiError(422, 'too_many_channels');
        return array_values($out);
    }

    /**
     * A YouTube channel from what a moderator pastes: its /channel/ address,
     * or a link to any of its videos (asked of the Data API). A handle
     * (@name) cannot be looked up without another API: a video link instead.
     *
     * @return array{id:string,title:string}
     */
    public function resolveChannel(string $input): array
    {
        $input = trim($input);
        if (preg_match('~youtube\.com/channel/(UC[A-Za-z0-9_-]{22})~', $input, $m)) return ['id' => $m[1], 'title' => ''];
        if (preg_match('~youtube\.com/@~', $input)) throw new ApiError(422, 'channel_handle');
        $id = YouTube::parseId($input) ?? throw new ApiError(422, 'invalid_youtube_url');
        $yt = $this->app->youtube();
        if (!$yt->configured()) throw new ApiError(503, 'youtube_not_configured');
        $v = $yt->video($id);
        if (!$v['ok']) throw new ApiError(422, 'video_' . $v['error']);
        if ($v['channel_id'] === '') throw new ApiError(422, 'channel_unknown');
        return ['id' => $v['channel_id'], 'title' => $v['channel']];
    }

    /** The group a YouTube channel belongs to (an item added or found from it joins). */
    public function ofChannel(string $channelId): ?int
    {
        if ($channelId === '') return null;
        foreach ($this->byId() as $g) {
            if (in_array($channelId, array_column($g['channels'], 'id'), true)) return $g['id'];
        }
        return null;
    }

    /**
     * Library items without a group join this one: by their channel, and —
     * a blocked group, which must also catch the re-uploads of their songs —
     * by an artist its names list. An item a moderator put in another group
     * stays there.
     *
     * @param array<string,mixed> $group
     */
    public function assign(array $group): int
    {
        $store = $this->app->store();
        $n = 0;
        $channels = array_column($group['channels'], 'id');
        if ($channels) {
            $n += $store->query(
                'UPDATE library_items SET group_id = ? WHERE group_id IS NULL AND yt_channel IN (' . implode(', ', array_fill(0, count($channels), '?')) . ')',
                [(int) $group['id'], ...$channels],
            )->rowCount();
        }
        if ($group['blocked']) {
            $names = self::matchNames($group);
            // YouTube's artist too: a clean name from a look-up (Library\Knowledge) must not let a blocked artist slip back in.
            $rows = $store->all(
                'SELECT l.id, l.artist, k.yt_artist FROM library_items l LEFT JOIN video_knowledge k ON k.yt_id = l.yt_id
                 WHERE l.group_id IS NULL AND l.yt_id IS NOT NULL',
            );
            foreach ($rows as $r) {
                if (array_intersect([self::norm((string) $r['artist']), self::norm((string) ($r['yt_artist'] ?? ''))], $names)) {
                    $n += $store->update('library_items', ['group_id' => (int) $group['id']], 'id = ?', [(int) $r['id']]);
                }
            }
        }
        return $n;
    }

    /** Every group's assign(), after channels became known (the backfill). */
    public function assignAll(): int
    {
        $n = 0;
        foreach ($this->byId() as $g) $n += $this->assign($g);
        return $n;
    }

    /**
     * The blocked group a video belongs to, if any: by its library item's
     * group, its channel, or the artist its title names (or its channel's
     * title) exactly as the group lists them.
     *
     * @param array<string,mixed> $video YouTube::video() (title, channel, channel_id) or Library::lookup() (artist instead of the raw title)
     * @param array<string,mixed>|null $item the video's library item, when it is in the library
     * @return array<string,mixed>|null
     */
    public function blocking(array $video, ?array $item = null): ?array
    {
        $blocked = array_filter($this->byId(), fn(array $g): bool => (bool) $g['blocked']);
        if (!$blocked) return null;
        if ($item !== null && isset($blocked[(int) ($item['group_id'] ?? 0)])) return $blocked[(int) $item['group_id']];
        $channelId = (string) ($video['channel_id'] ?? '');
        $channel = (string) ($video['channel'] ?? '');
        // lookup() has split the title already; a raw YouTube::video() has not.
        $artist = isset($video['artist']) ? (string) $video['artist'] : YouTube::splitTitle((string) ($video['title'] ?? ''), $channel)[0];
        $names = array_values(array_filter([self::norm($artist), self::norm($channel)]));
        foreach ($blocked as $g) {
            if ($channelId !== '' && in_array($channelId, array_column($g['channels'], 'id'), true)) return $g;
            if (array_intersect($names, self::matchNames($g))) return $g;
        }
        return null;
    }

    /**
     * What the stage shows after one of the group's items while the host
     * speaks: who, a few words, the links — when the group wants it and is
     * not blocked.
     *
     * @return array{name:string,text:array{en:string,de:string},links:list<array{kind:string,url:string}>}|null
     */
    public function notice(int $id): ?array
    {
        $g = $this->get($id);
        if ($g === null || !$g['notice'] || $g['blocked']) return null;
        return ['name' => (string) $g['name'], 'text' => ['en' => (string) $g['about_en'], 'de' => (string) $g['about_de']], 'links' => $g['links']];
    }

    /**
     * A blocked group off the air: like "Pull from air" for each of its items
     * (ModApi::libraryPull), but without switching them off — unblocked, they
     * play again. Committed airings are blocked through live.json (minute
     * files cannot change), drafts dropped with their announcements.
     *
     * @param array<string,mixed> $group
     */
    public function pull(array $group): int
    {
        $store = $this->app->store();
        $now = $this->app->clock->nowMs();
        $ids = array_map('intval', array_column($store->all('SELECT id FROM library_items WHERE group_id = ?', [(int) $group['id']]), 'id'));
        $n = 0;
        foreach ($ids as $lid) {
            $n += $store->query(
                "UPDATE timeline_items SET blocked = 1 WHERE library_id = ? AND state = 'committed' AND start_ms + dur_ms > ?",
                [$lid, $now],
            )->rowCount();
            $this->app->timeline()->dropDraftsOf($lid);
        }
        if ($ids) {
            foreach ($this->app->catalog()->channels() as $ch) $this->app->publisher()->publishLive($ch);
        }
        return $n;
    }
}

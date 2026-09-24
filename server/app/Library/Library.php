<?php
declare(strict_types=1);

namespace Arche\Library;

use Arche\ApiError;
use Arche\App;
use Arche\Audio\Mp3;
use Arche\Plan\Catalog;

/**
 * The content pool the generator fills airtime from: curated songs (added by
 * moderators in /mod), graduated submissions (approved requests enter here
 * automatically) and jingles. Only tags carry over from a submission — a
 * dedication belongs to one airing, never to the song.
 */
final class Library
{
    private const JSON_COLS = ['languages', 'themes', 'moods', 'program_ids', 'channel_ids', 'meta'];

    public function __construct(private App $app) {}

    /** @param array<string,mixed> $row @return array<string,mixed> */
    public static function decode(array $row): array
    {
        foreach (self::JSON_COLS as $k) {
            $v = json_decode((string) ($row[$k] ?? ''), true);
            $row[$k] = is_array($v) ? $v : [];
        }
        foreach (['id', 'duration_ms', 'active', 'plays'] as $k) $row[$k] = (int) $row[$k];
        $row['trend_score'] = (float) $row['trend_score'];
        return $row;
    }

    /** @return array<string,mixed>|null */
    public function get(int $id): ?array
    {
        $row = $this->app->store()->one('SELECT * FROM library_items WHERE id = ?', [$id]);
        return $row ? self::decode($row) : null;
    }

    /** @return array<string,mixed>|null */
    public function byYouTube(string $ytId): ?array
    {
        $row = $this->app->store()->one('SELECT * FROM library_items WHERE yt_id = ?', [$ytId]);
        return $row ? self::decode($row) : null;
    }

    public function count(string $kind = 'song', bool $activeOnly = true): int
    {
        return (int) $this->app->store()->value(
            'SELECT COUNT(*) FROM library_items WHERE kind = ?' . ($activeOnly ? ' AND active = 1' : ''),
            [$kind],
        );
    }

    /**
     * Changes when the set of playable songs does (added, disabled, enabled
     * again) — not with plays or trend scores.
     */
    public function fingerprint(): string
    {
        $r = $this->app->store()->one("SELECT COUNT(*) AS n, COALESCE(SUM(id), 0) AS s FROM library_items WHERE kind = 'song' AND active = 1");
        return ($r['n'] ?? 0) . ':' . ($r['s'] ?? 0);
    }

    /** @return list<array<string,mixed>> for /mod */
    public function search(string $q = '', string $kind = '', int $limit = 200, int $offset = 0): array
    {
        $where = [];
        $args = [];
        if ($q !== '') {
            $where[] = '(title LIKE ? OR artist LIKE ? OR yt_id = ?)';
            array_push($args, "%$q%", "%$q%", $q);
        }
        if ($kind !== '') {
            $where[] = 'kind = ?';
            $args[] = $kind;
        }
        $sql = 'SELECT * FROM library_items' . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY active DESC, updated DESC LIMIT ? OFFSET ?';
        array_push($args, max(1, min(500, $limit)), max(0, $offset));
        return array_map([self::class, 'decode'], $this->app->store()->all($sql, $args));
    }

    /**
     * Look a video up for the "add song" form: YouTube metadata plus whether
     * it is already in the pool.
     *
     * @return array<string,mixed>
     */
    public function lookup(string $input): array
    {
        $id = YouTube::parseId($input);
        if ($id === null) throw new ApiError(422, 'invalid_youtube_url');
        $yt = $this->app->youtube();
        if (!$yt->configured()) throw new ApiError(503, 'youtube_not_configured');
        $v = $yt->video($id);
        if (!$v['ok']) throw new ApiError(422, 'video_' . $v['error']);
        [$artist, $title] = YouTube::splitTitle($v['title'], $v['channel']);
        return [
            'id' => $id,
            'title' => $title,
            'artist' => $artist,
            'channel' => $v['channel'],
            'duration_ms' => $v['duration_ms'],
            'embeddable' => $v['embeddable'],
            'public' => $v['public'],
            'live' => $v['live'],
            'age_restricted' => $v['age_restricted'],
            'playable' => YouTube::playableIn($v, $this->app->config->list('SUBMISSION_MARKETS')),
            'existing' => $this->byYouTube($id)['id'] ?? null,
        ];
    }

    /**
     * Add a curated song. The server re-checks YouTube itself; nothing from the
     * form is trusted beyond title/artist/tags.
     *
     * @param array<string,mixed> $attrs
     * @return array<string,mixed>
     */
    public function addSong(string $input, array $attrs, string $actor): array
    {
        $info = $this->lookup($input);
        if ($info['existing'] !== null) throw new ApiError(409, 'already_in_library', ['id' => $info['existing']]);
        if (!$info['embeddable'] || !$info['public'] || $info['live'] || $info['age_restricted']) {
            throw new ApiError(422, 'video_not_embeddable');
        }
        if ($info['duration_ms'] < 30_000 || $info['duration_ms'] > 30 * 60_000) throw new ApiError(422, 'video_duration');
        $now = $this->app->clock->now();
        $id = $this->app->store()->insert('library_items', [
            'kind' => 'song',
            'yt_id' => $info['id'],
            'title' => mb_substr(trim((string) ($attrs['title'] ?? $info['title'])) ?: $info['title'], 0, 120),
            'artist' => mb_substr(trim((string) ($attrs['artist'] ?? $info['artist'])), 0, 120),
            'thumb' => $this->app->media()->cacheThumb($info['id']),
            'duration_ms' => $info['duration_ms'],
            'languages' => json_encode(self::langs((array) ($attrs['languages'] ?? []))),
            'themes' => json_encode(Catalog::tags((array) ($attrs['themes'] ?? []))),
            'moods' => json_encode(Catalog::tags((array) ($attrs['moods'] ?? []))),
            'program_ids' => json_encode(self::ints((array) ($attrs['program_ids'] ?? []))),
            'channel_ids' => json_encode(self::ints((array) ($attrs['channel_ids'] ?? []))),
            'source' => 'curated',
            'meta' => json_encode(['channel' => $info['channel']], JSON_UNESCAPED_UNICODE),
            'created' => $now,
            'updated' => $now,
        ]);
        $this->app->store()->audit($actor, 'Library add', $info['id'] . ' ' . $info['title']);
        return $this->get($id) ?? throw new \LogicException('insert vanished');
    }

    /** @param array<string,mixed> $attrs @return array<string,mixed> */
    public function update(int $id, array $attrs, string $actor): array
    {
        $item = $this->get($id) ?? throw new ApiError(404, 'not_found');
        $row = [];
        foreach (['title', 'artist'] as $k) {
            if (array_key_exists($k, $attrs)) $row[$k] = mb_substr(trim((string) $attrs[$k]), 0, 120);
        }
        if (isset($attrs['languages'])) $row['languages'] = json_encode(self::langs((array) $attrs['languages']));
        foreach (['themes', 'moods'] as $k) {
            if (isset($attrs[$k])) $row[$k] = json_encode(Catalog::tags((array) $attrs[$k]));
        }
        foreach (['program_ids', 'channel_ids'] as $k) {
            if (isset($attrs[$k])) $row[$k] = json_encode(self::ints((array) $attrs[$k]));
        }
        if (array_key_exists('active', $attrs)) {
            $row['active'] = $attrs['active'] ? 1 : 0;
            // Re-enabling clears the error reports that may have disabled it.
            if ($attrs['active']) $this->app->store()->query('DELETE FROM playback_errors WHERE library_id = ?', [$id]);
        }
        if ($row) $this->app->store()->update('library_items', $row + ['updated' => $this->app->clock->now()], 'id = ?', [$id]);
        $this->app->store()->audit($actor, 'Library edit', (string) $id . ' ' . $item['title']);
        return $this->get($id) ?? throw new ApiError(404, 'not_found');
    }

    /** A jingle / station ident from an uploaded MP3. @return array<string,mixed> */
    public function addJingle(string $tmpFile, string $title, string $actor): array
    {
        $check = Mp3::inspect($tmpFile);
        if (!$check['ok'] || $check['ms'] > 60_000) throw new ApiError(422, 'invalid_jingle');
        $bytes = (string) file_get_contents($tmpFile);
        $url = $this->app->media()->put('jingles', substr(hash('sha256', $bytes), 0, 16) . '.mp3', $bytes);
        return $this->insertJingle($url, $check['ms'], $title, $actor);
    }

    /** A spoken station ident rendered with the host voice. @return array<string,mixed> */
    public function addJingleTts(string $text, string $voice, string $actor): array
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 200) throw new ApiError(422, 'invalid_text');
        $bytes = $this->app->openai()->tts($text, $voice, 'A warm, short radio station identification.');
        $tmp = tempnam($this->app->config->dataDir, 'jingle');
        file_put_contents($tmp, $bytes);
        try {
            $check = Mp3::inspect($tmp);
        } finally {
            @unlink($tmp);
        }
        if (!$check['ok']) throw new ApiError(502, 'tts_failed');
        $url = $this->app->media()->put('jingles', substr(hash('sha256', $bytes), 0, 16) . '.mp3', $bytes);
        return $this->insertJingle($url, $check['ms'], mb_substr($text, 0, 60), $actor);
    }

    /** @return array<string,mixed> */
    private function insertJingle(string $url, int $ms, string $title, string $actor): array
    {
        $now = $this->app->clock->now();
        $id = $this->app->store()->insert('library_items', [
            'kind' => 'jingle',
            'audio' => $url,
            'title' => mb_substr(trim($title) ?: 'Jingle', 0, 120),
            'duration_ms' => $ms,
            'created' => $now,
            'updated' => $now,
        ]);
        $this->app->store()->audit($actor, 'Jingle add', $url);
        return $this->get($id) ?? throw new \LogicException('insert vanished');
    }

    /**
     * Songs the Selector may consider for a program.
     *
     * @return list<array<string,mixed>>
     */
    public function candidates(int $channelId, int $programId, int $maxMs): array
    {
        $rows = $this->app->store()->all(
            "SELECT * FROM library_items WHERE kind = 'song' AND active = 1 AND duration_ms <= ?",
            [$maxMs],
        );
        $out = [];
        foreach ($rows as $r) {
            $r = self::decode($r);
            if ($r['channel_ids'] && !in_array($channelId, $r['channel_ids'], true)) continue;
            if ($r['program_ids'] && !in_array($programId, $r['program_ids'], true)) continue;
            $out[] = $r;
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function jingles(): array
    {
        return array_map(
            [self::class, 'decode'],
            $this->app->store()->all("SELECT * FROM library_items WHERE kind = 'jingle' AND active = 1 ORDER BY id"),
        );
    }

    public function recordPlay(int $id, int $startMs): void
    {
        $this->app->store()->query(
            'UPDATE library_items SET plays = plays + 1, last_played = ? WHERE id = ?',
            [intdiv($startMs, 1000), $id],
        );
    }

    /**
     * A listener's player could not play this video. Only "gone" codes count
     * (100 removed/private, 101/150 embedding refused — never 2/5/153, which
     * are our own or the browser's fault), each identity once, and the item is
     * only disabled when YouTube itself confirms it is no longer embeddable:
     * a coordinated few must not be able to empty the library.
     */
    public function reportPlaybackError(int $id, string $reporter, int $code): bool
    {
        if (!in_array($code, [100, 101, 150], true)) return false;
        $store = $this->app->store();
        $store->query(
            'INSERT INTO playback_errors(library_id, reporter, code, time) VALUES(?, ?, ?, ?)
             ON CONFLICT(library_id, reporter) DO UPDATE SET code = excluded.code, time = excluded.time',
            [$id, $reporter, $code, $this->app->clock->now()],
        );
        $reports = (int) $store->value('SELECT COUNT(*) FROM playback_errors WHERE library_id = ?', [$id]);
        if ($reports < 3) return false;
        $item = $this->get($id);
        if ($item === null || !$item['active'] || $item['yt_id'] === null) return false;
        if (!$this->app->youtube()->configured()) return false;
        $v = $this->app->youtube()->video((string) $item['yt_id']);
        $gone = ($v['ok'] === false && $v['error'] === 'not_found') || ($v['ok'] && (!$v['embeddable'] || !$v['public']));
        if (!$gone) return false;
        $store->update('library_items', ['active' => 0, 'updated' => $this->app->clock->now()], 'id = ?', [$id]);
        $store->audit('system', 'Library auto-disabled', $item['yt_id'] . ' after ' . $reports . ' playback reports');
        return true;
    }

    /**
     * The evergreen loop: the best-liked active songs, stable in order so the
     * file only changes when the ranking does.
     *
     * @return list<array<string,mixed>>
     */
    public function evergreen(int $channelId, int $limit = 20): array
    {
        $rows = array_map([self::class, 'decode'], $this->app->store()->all(
            "SELECT * FROM library_items WHERE kind = 'song' AND active = 1 ORDER BY trend_score DESC, plays DESC, id LIMIT 200",
        ));
        $rows = array_values(array_filter($rows, fn($r) => !$r['channel_ids'] || in_array($channelId, $r['channel_ids'], true)));
        $top = array_slice($rows, 0, $limit);
        usort($top, fn($a, $b) => $a['id'] <=> $b['id']);
        return $top;
    }

    /** @param array<mixed> $v @return list<string> */
    private static function langs(array $v): array
    {
        return array_values(array_intersect(['en', 'de'], array_map('strval', $v)));
    }

    /** @param array<mixed> $v @return list<int> */
    private static function ints(array $v): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $v), fn($i) => $i > 0)));
    }
}

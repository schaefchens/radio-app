<?php
declare(strict_types=1);

namespace Arche\Library;

use Arche\ApiError;
use Arche\App;
use Arche\Audio\Mp3;
use Arche\Host\Speech;
use Arche\Plan\Catalog;

/**
 * The content pool the generator fills airtime from: curated songs and
 * videos — preachings, testimonies, mission videos, films (added by
 * moderators in /mod) —, graduated submissions (approved requests and video
 * suggestions enter here automatically) and jingles.
 * Only tags carry over from a submission — a dedication belongs to one
 * airing, never to the song.
 */
final class Library
{
    private const JSON_COLS = ['languages', 'themes', 'moods', 'program_ids', 'channel_ids', 'meta'];
    /** Background music: long enough to carry a moment, short enough for the 8 MB upload at ~128 kbps. */
    public const BED_MIN_MS = 20_000;
    public const BED_MAX_MS = 600_000;
    /** What a moderator adds from YouTube, and how long it may be (ms): a film runs two hours and more. */
    public const VIDEO_KINDS = [
        'song' => [30_000, 30 * 60_000],
        'preaching' => [60_000, 3 * 3_600_000],
        'testimony' => [60_000, 3 * 3_600_000],
        'mission' => [60_000, 3 * 3_600_000],
        'film' => [60_000, 4 * 3_600_000],
    ];

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
        $row['group_id'] = isset($row['group_id']) ? (int) $row['group_id'] : null;
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

    /** SQL: not in a blocked group (Groups) — their items never play. */
    private const PLAYABLE = '(group_id IS NULL OR group_id NOT IN (SELECT id FROM library_groups WHERE blocked = 1))';

    /**
     * Changes when the set of playable songs does (added, disabled, enabled
     * again, their group blocked or not) — not with plays or trend scores.
     */
    public function fingerprint(): string
    {
        $r = $this->app->store()->one("SELECT COUNT(*) AS n, COALESCE(SUM(id), 0) AS s FROM library_items WHERE kind = 'song' AND active = 1 AND " . self::PLAYABLE);
        return ($r['n'] ?? 0) . ':' . ($r['s'] ?? 0);
    }

    /** @return list<array<string,mixed>> for /mod */
    public function search(string $q = '', string $kind = '', int $limit = 200, int $offset = 0, int $groupId = 0): array
    {
        $where = [];
        $args = [];
        if ($groupId > 0) {
            $where[] = 'group_id = ?';
            $args[] = $groupId;
        }
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
     * Look a video up for the "add to library" form: YouTube metadata plus
     * whether it is already in the pool — this upload, or with `$kind`, what
     * is likely the same song or video (duplicates()).
     *
     * @return array<string,mixed>
     */
    public function lookup(string $input, string $kind = ''): array
    {
        $id = YouTube::parseId($input);
        if ($id === null) throw new ApiError(422, 'invalid_youtube_url');
        $yt = $this->app->youtube();
        if (!$yt->configured()) throw new ApiError(503, 'youtube_not_configured');
        $v = $yt->video($id);
        if (!$v['ok']) throw new ApiError(422, 'video_' . $v['error']);
        [$artist, $title] = YouTube::splitTitle($v['title'], $v['channel']);
        $info = [
            'id' => $id,
            'title' => $title,
            'artist' => $artist,
            'full_title' => $v['title'],
            'channel' => $v['channel'],
            'channel_id' => $v['channel_id'],
            'duration_ms' => $v['duration_ms'],
            'embeddable' => $v['embeddable'],
            'public' => $v['public'],
            'live' => $v['live'],
            'age_restricted' => $v['age_restricted'],
            'playable' => YouTube::playableIn($v, $this->app->config->list('SUBMISSION_MARKETS')),
            'existing' => $this->byYouTube($id)['id'] ?? null,
        ];
        return $info + ['duplicates' => $kind !== '' ? $this->duplicates($info, $kind) : []];
    }

    /** Words that say nothing about which song or video it is: YouTube's labels, formats and filler. */
    private const NOISE = [
        'the', 'and', 'with', 'feat', 'featuring', 'official', 'video', 'videos', 'music', 'musik', 'lyric', 'lyrics', 'audio', 'live',
        'version', 'full', 'movie', 'film', 'films', 'filme', 'kinofilm', 'spielfilm', 'christian', 'christlich', 'christliche',
        'christlicher', 'christlichen', 'drama', 'deutsch', 'german', 'english', 'englisch', 'subtitles', 'untertitel', 'song', 'lied',
        'worship', 'lobpreis', 'mit', 'und', 'der', 'die', 'das', 'den', 'dem', 'des', 'ein', 'eine', 'einen', 'einem', 'einer', 'von',
        'vom', 'zum', 'zur', 'fur', 'fuer', 'auf', 'aus', 'bei', 'uber', 'ueber', 'new', 'neu', 'neue', 'you', 'your', 'are', 'for',
        'our', 'his', 'ist', 'bist', 'sind', 'mein', 'meine', 'dein', 'deine', 'unser', 'unsere', 'wir', 'ihr', 'sie', 'not', 'nicht',
        'all', 'alle', 'was', 'wie', 'what', 'how', 'who', 'wer', 'this', 'that', 'dies', 'diese', 'dieser', 'part', 'teil',
    ];
    private const FOLD = ['ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss', 'à' => 'a', 'á' => 'a', 'â' => 'a', 'å' => 'a', 'é' => 'e',
        'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ø' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ñ' => 'n', 'ç' => 'c', 'æ' => 'ae', 'œ' => 'oe', 'ý' => 'y', 'ÿ' => 'y'];

    /** Roman part numbers, as a sequel's title writes them. */
    private const ROMAN = ['ii' => '2', 'iii' => '3', 'iv' => '4', 'vi' => '6'];

    /** @return list<string> a name's telling words, once each — numbers too (a sequel's part, a hymn's), never a year */
    private static function words(string $name): array
    {
        $s = strtr(mb_strtolower(Speech::title($name)), self::FOLD);
        $out = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', $s) ?: [] as $w) {
            $w = self::ROMAN[$w] ?? $w;
            if (ctype_digit($w) ? preg_match('/^(19|20)\d\d$/', $w) === 1
                : mb_strlen($w) < 3 || in_array($w, self::NOISE, true) || preg_match('/^\d+p$/', $w) === 1) continue;
            $out[$w] = true;
        }
        // Keys that look like numbers come back as ints ("3" → 3): the part numbers must stay words.
        return array_map('strval', array_keys($out));
    }

    /**
     * The numbers in any of a song's or video's names: "Gott ist nicht tot"
     * and its parts 2, 3 and 4 share every other word.
     *
     * @param list<list<string>> $sets
     * @return list<string>
     */
    private static function numbers(array $sets): array
    {
        $n = array_values(array_unique(array_filter(array_merge(...($sets ?: [[]])), 'ctype_digit')));
        sort($n);
        return $n;
    }

    /**
     * Each name a song or video goes by, as words without who it is by: a
     * title split from YouTube's "Artist - Title" and one kept whole compare
     * alike, and two songs by one artist do not match by the artist.
     *
     * @param list<string> $names
     * @return list<list<string>>
     */
    private static function nameSets(array $names, string $by): array
    {
        $byWords = self::words($by);
        $sets = [];
        foreach ($names as $name) {
            $w = array_values(array_diff(self::words($name), $byWords));
            if ($w) $sets[] = $w;
        }
        return $sets;
    }

    /**
     * Whether two songs, or two videos, are likely the same — another upload,
     * another language, a re-release: their names share three quarters or
     * more of the shorter one's words, two at least, and their lengths are
     * near (a song within 20 %, a video within 10 %: a song in a longer
     * version is another recording); or the shorter name is one word of the
     * other's and the lengths agree to the seconds (a song within 5 s or 3 %,
     * a video within 15 s or 0.5 %: two films of one word and one length are
     * rare, two of one word common). Different numbers are different parts,
     * and a song is never a film. Tried on the station's library (2026-10-08):
     * one shared word and a similar length took "Saved by Grace" for "Amish
     * Grace", and sequels for each other.
     *
     * @param array{sets:list<list<string>>,nums:list<string>,ms:int} $a
     * @param array{sets:list<list<string>>,nums:list<string>,ms:int} $b
     */
    private static function same(array $a, array $b, bool $songs): bool
    {
        if ($a['nums'] !== $b['nums']) return false;
        $diff = abs($a['ms'] - $b['ms']);
        $longer = max($a['ms'], $b['ms']);
        $near = $diff <= ($songs ? 0.2 : 0.1) * $longer;
        $tight = $diff <= max($songs ? 5_000 : 15_000, ($songs ? 0.03 : 0.005) * $longer);
        foreach ($a['sets'] as $x) {
            foreach ($b['sets'] as $y) {
                $shared = array_values(array_intersect($x, $y));
                if (!$shared) continue;
                $overlap = count($shared) / min(count($x), count($y));
                if ($overlap >= 0.75 && count($shared) >= 2 && $near) return true;
                if ($overlap >= 0.999 && $tight && max(array_map('mb_strlen', $shared)) >= 4) return true;
            }
        }
        return false;
    }

    /** The original's title, without who wrote it ("Forgiven; Drehbuch von …", "Goodness of God, written by …"). */
    private static function originalTitle(string $original): string
    {
        return trim((string) preg_split('/[;,]/u', $original)[0]);
    }

    /**
     * The YouTube items of the library with every name they go by — their
     * own and, when looked up (Library\Knowledge), the real title and the
     * original of a translation.
     *
     * @return list<array<string,mixed>>
     */
    private function named(): array
    {
        $rows = $this->app->store()->all(
            'SELECT l.id, l.kind, l.yt_id, l.title, l.artist, l.duration_ms, l.active, l.thumb, json_extract(l.meta, \'$.channel\') AS channel,
                    k.title AS k_title, k.artist AS k_artist,
                    json_extract(k.research, \'$.identity.original\') AS k_original
             FROM library_items l LEFT JOIN video_knowledge k ON k.yt_id = l.yt_id AND k.state = \'ready\'
             WHERE l.yt_id IS NOT NULL AND l.kind IN (' . implode(',', array_fill(0, count(self::VIDEO_KINDS), '?')) . ')',
            array_keys(self::VIDEO_KINDS),
        );
        foreach ($rows as &$r) {
            $r['sets'] = [...self::nameSets([(string) $r['title']], (string) $r['artist']),
                // A video's title split like a song's ("Vergeben - Forgiven …" → by "Vergeben"): whole again, without its channel.
                ...($r['kind'] !== 'song' ? self::nameSets([$r['artist'] . ' ' . $r['title']], (string) ($r['channel'] ?? $r['artist'])) : []),
                ...self::nameSets(array_filter([(string) $r['k_title'], self::originalTitle((string) $r['k_original'])]), (string) $r['k_artist'])];
            $r['nums'] = self::numbers($r['sets']);
            $r['ms'] = (int) $r['duration_ms'];
        }
        unset($r);
        return $rows;
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private static function brief(array $r): array
    {
        return ['id' => (int) $r['id'], 'kind' => (string) $r['kind'], 'yt_id' => (string) $r['yt_id'], 'title' => (string) $r['title'],
            'artist' => (string) $r['artist'], 'duration_ms' => (int) $r['duration_ms'], 'active' => (int) $r['active'], 'thumb' => $r['thumb']];
    }

    /**
     * What in the library is likely the same song or video as one about to
     * be added (another upload of a film once came in beside the one there):
     * a moderator sees them and adds it anyway only on purpose.
     *
     * @param array<string,mixed> $video a lookup: id, title, full_title, artist, channel, duration_ms
     * @return list<array<string,mixed>>
     */
    public function duplicates(array $video, string $kind): array
    {
        $songs = $kind === 'song';
        $known = $this->app->knowledge()->get((string) ($video['id'] ?? ''));
        $sets = [...self::nameSets([(string) ($video['title'] ?? ''), (string) ($video['full_title'] ?? '')], (string) ($video['artist'] ?? '')),
            ...self::nameSets([(string) ($video['full_title'] ?? '')], (string) ($video['channel'] ?? '')),
            ...($known !== null && $known['state'] === 'ready' ? self::nameSets(array_filter([(string) $known['title'],
                self::originalTitle((string) ($known['research']['identity']['original'] ?? ''))]), (string) $known['artist']) : [])];
        $new = ['sets' => $sets, 'nums' => self::numbers($sets), 'ms' => (int) ($video['duration_ms'] ?? 0)];
        $out = [];
        foreach ($this->named() as $r) {
            if ($r['yt_id'] === ($video['id'] ?? null) || ($r['kind'] === 'song') !== $songs) continue;
            if (self::same($new, $r, $songs)) $out[] = self::brief($r);
        }
        return array_slice($out, 0, 5);
    }

    /**
     * Every pair in the library that is likely the same song or video, for
     * /mod's list: id → the others.
     *
     * @return array<int,list<array<string,mixed>>>
     */
    public function duplicatePairs(): array
    {
        $rows = $this->named();
        $out = [];
        foreach ($rows as $i => $a) {
            for ($j = $i + 1; $j < count($rows); $j++) {
                $b = $rows[$j];
                $songs = $a['kind'] === 'song';
                if (($b['kind'] === 'song') !== $songs || !self::same($a, $b, $songs)) continue;
                $out[(int) $a['id']][] = self::brief($b);
                $out[(int) $b['id']][] = self::brief($a);
            }
        }
        return $out;
    }

    /**
     * Add a curated song or video (for a preaching, `artist` is the preacher;
     * for a testimony, a mission video or a film, who it is from). The server
     * re-checks YouTube itself; nothing from the form is trusted beyond
     * title/artist/tags.
     *
     * @param string $kind a key of VIDEO_KINDS
     * @param array<string,mixed> $attrs
     * @return array<string,mixed>
     */
    public function addVideo(string $kind, string $input, array $attrs, string $actor): array
    {
        [$min, $max] = self::VIDEO_KINDS[$kind] ?? throw new ApiError(422, 'bad_kind');
        $info = $this->lookup($input, $kind);
        if ($info['existing'] !== null) throw new ApiError(409, 'already_in_library', ['id' => $info['existing']]);
        // Its creator asked not to be on our platform.
        $blocked = $this->app->groups()->blocking($info);
        if ($blocked !== null) throw new ApiError(409, 'group_blocked', ['group' => $blocked['name']]);
        if (!$info['embeddable'] || !$info['public'] || $info['live'] || $info['age_restricted']) {
            throw new ApiError(422, 'video_not_embeddable');
        }
        if ($info['duration_ms'] < $min || $info['duration_ms'] > $max) throw new ApiError(422, 'video_duration');
        // Another upload of what is there already: only when the moderator says so.
        if ($info['duplicates'] && empty($attrs['allow_duplicate'])) throw new ApiError(409, 'possible_duplicate', ['duplicates' => $info['duplicates']]);
        $now = $this->app->clock->now();
        $id = $this->app->store()->insert('library_items', [
            'kind' => $kind,
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
            'yt_channel' => $info['channel_id'] !== '' ? $info['channel_id'] : null,
            'group_id' => $this->app->groups()->ofChannel($info['channel_id']),
            'created' => $now,
            'updated' => $now,
        ]);
        $this->app->store()->audit($actor, 'Library add' . ($kind === 'song' ? '' : " $kind"), $info['id'] . ' ' . $info['title']);
        // What it really is and says, looked up once (Library\Knowledge).
        $this->app->knowledge()->ensure($info['id'], $kind, 60);
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
        if (array_key_exists('group_id', $attrs)) {
            $gid = $attrs['group_id'] === null || $attrs['group_id'] === '' ? null : (int) $attrs['group_id'];
            if ($gid !== null && $this->app->groups()->get($gid) === null) throw new ApiError(422, 'invalid_group');
            $row['group_id'] = $gid;
        }
        if ($row) $this->app->store()->update('library_items', $row + ['updated' => $this->app->clock->now()], 'id = ?', [$id]);
        // Put in a blocked group: what is planned or on air of it goes at once.
        if (isset($row['group_id']) && $this->app->groups()->isBlocked($row['group_id'])) {
            $this->app->groups()->pull($this->app->groups()->get($row['group_id']) ?? []);
        }
        $this->app->store()->audit($actor, 'Library edit', (string) $id . ' ' . $item['title']);
        return $this->get($id) ?? throw new ApiError(404, 'not_found');
    }

    /**
     * Delete a song or video for good — only one switched off (Deactivate is
     * the step that takes it off air; this one also takes it off the list,
     * e.g. another upload of a film already there). What is planned with it
     * goes (Timeline::dropDraftsOf, a request with its whole unit); what aired
     * keeps its own title and link. SQLite may give the id to the next item
     * added, so nothing may point at it any more: the timeline's and the
     * requests' links are cleared, its reactions and playback errors go. Its
     * look-up stays for 30 days (Tick::purge), so adding it again costs nothing.
     */
    public function delete(int $id, string $actor): void
    {
        $item = $this->get($id) ?? throw new ApiError(404, 'not_found');
        if (!isset(self::VIDEO_KINDS[$item['kind']]) || $item['yt_id'] === null) throw new ApiError(422, 'not_deletable');
        if ($item['active']) throw new ApiError(409, 'still_active');
        $this->app->timeline()->dropDraftsOf($id);
        $store = $this->app->store();
        $store->tx(function () use ($store, $id): void {
            $store->query('UPDATE timeline_items SET library_id = NULL WHERE library_id = ?', [$id]);
            $store->query('UPDATE submissions SET library_id = NULL WHERE library_id = ?', [$id]);
            $store->query('DELETE FROM reactions WHERE library_id = ?', [$id]);
            $store->query('DELETE FROM playback_errors WHERE library_id = ?', [$id]);
            $store->query('DELETE FROM library_items WHERE id = ?', [$id]);
        });
        $store->audit($actor, 'Library delete' . ($item['kind'] === 'song' ? '' : " {$item['kind']}"), $item['yt_id'] . ' ' . $item['title']);
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

    /**
     * Background music from an uploaded MP3 — the prayer hour's prayer music,
     * played on its own, never under the host. The plan never makes a piece
     * of it longer than the file, so the app never loops it.
     *
     * @return array<string,mixed>
     */
    public function addBed(string $tmpFile, string $title, string $actor): array
    {
        $check = Mp3::inspect($tmpFile);
        if (!$check['ok'] || $check['ms'] < self::BED_MIN_MS || $check['ms'] > self::BED_MAX_MS) throw new ApiError(422, 'invalid_bed');
        $bytes = (string) file_get_contents($tmpFile);
        $url = $this->app->media()->put('beds', substr(hash('sha256', $bytes), 0, 16) . '.mp3', $bytes);
        $now = $this->app->clock->now();
        $id = $this->app->store()->insert('library_items', [
            'kind' => 'bed',
            'audio' => $url,
            'title' => mb_substr(trim($title) ?: 'Background music', 0, 120),
            'duration_ms' => $check['ms'],
            'created' => $now,
            'updated' => $now,
        ]);
        $this->app->store()->audit($actor, 'Background music add', $url);
        return $this->get($id) ?? throw new \LogicException('insert vanished');
    }

    /** @return list<array<string,mixed>> background music that can play, for the program settings */
    public function beds(): array
    {
        return array_map(
            [self::class, 'decode'],
            $this->app->store()->all("SELECT * FROM library_items WHERE kind = 'bed' AND active = 1 ORDER BY title, id"),
        );
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
     * Songs (or a video program's videos) the Selector may consider for a program.
     *
     * @param string $kind 'song' or a video format
     * @return list<array<string,mixed>>
     */
    public function candidates(int $channelId, int $programId, int $maxMs, string $kind = 'song'): array
    {
        $rows = $this->app->store()->all(
            'SELECT * FROM library_items WHERE kind = ? AND active = 1 AND duration_ms <= ? AND ' . self::PLAYABLE,
            [$kind, $maxMs],
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
            "SELECT * FROM library_items WHERE kind = 'song' AND active = 1 AND " . self::PLAYABLE . ' ORDER BY trend_score DESC, plays DESC, id LIMIT 200',
        ));
        $rows = array_values(array_filter($rows, fn($r) => !$r['channel_ids'] || in_array($channelId, $r['channel_ids'], true)));
        $top = array_slice($rows, 0, $limit);
        usort($top, fn($a, $b) => $a['id'] <=> $b['id']);
        return $top;
    }

    /**
     * The `channels` job, hourly while some videos do not know their YouTube
     * channel (added before groups existed): in the runner's budget, never in
     * the publish phase — it asks YouTube.
     */
    public function queueChannels(): void
    {
        if (!$this->app->youtube()->configured()) return;
        if ($this->app->store()->value('SELECT id FROM library_items WHERE yt_id IS NOT NULL AND yt_channel IS NULL LIMIT 1') === null) return;
        $this->app->jobs()->enqueue('channels', 0, 90, $this->app->clock->nowMs());
    }

    /**
     * Fifty videos' channels per call; '' for a video YouTube no longer has,
     * so it is not asked again. Their items join their groups.
     *
     * @param array<string,mixed> $job
     */
    public function runChannels(array $job): ?string
    {
        $store = $this->app->store();
        $rows = $store->all('SELECT id, yt_id FROM library_items WHERE yt_id IS NOT NULL AND yt_channel IS NULL ORDER BY id LIMIT 50');
        if (!$rows) return null;
        $channels = $this->app->youtube()->channelsOf(array_map(fn($r) => (string) $r['yt_id'], $rows));
        if ($channels === null) throw new \RuntimeException('YouTube channels unreachable');
        foreach ($rows as $r) $store->update('library_items', ['yt_channel' => $channels[(string) $r['yt_id']] ?? ''], 'id = ?', [(int) $r['id']]);
        $this->app->groups()->assignAll();
        return count($rows) === 50 ? 'start' : null;
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

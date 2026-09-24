<?php
declare(strict_types=1);

namespace Arche\Library;

use Arche\App;

/**
 * YouTube Data API v3 (`videos.list`, one quota unit per call). oEmbed cannot
 * tell duration, and the server must not trust a duration the client sends,
 * so every song — curated or requested — is checked here.
 */
class YouTube
{
    public function __construct(protected App $app) {}

    public static function parseId(string $input): ?string
    {
        $input = trim($input);
        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $input)) return $input;
        $patterns = [
            '~(?:youtube\.com|youtube-nocookie\.com)/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/|v/)([A-Za-z0-9_-]{11})~',
            '~youtu\.be/([A-Za-z0-9_-]{11})~',
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $input, $m)) return $m[1];
        }
        return null;
    }

    public function configured(): bool
    {
        return $this->app->config->has('YOUTUBE_API_KEY');
    }

    /**
     * @return array{ok:bool,error:string,id:string,title:string,channel:string,description:string,tags:list<string>,
     *   duration_ms:int,embeddable:bool,public:bool,live:bool,age_restricted:bool,blocked:list<string>,allowed:list<string>|null}
     */
    public function video(string $id): array
    {
        $empty = ['ok' => false, 'error' => '', 'id' => $id, 'title' => '', 'channel' => '', 'description' => '', 'tags' => [],
            'duration_ms' => 0, 'embeddable' => false, 'public' => false, 'live' => false, 'age_restricted' => false,
            'blocked' => [], 'allowed' => null];
        if (!$this->configured()) return ['error' => 'not_configured'] + $empty;
        $url = rtrim($this->app->config->get('YOUTUBE_API_BASE'), '/') . '/videos?' . http_build_query([
            'part' => 'snippet,contentDetails,status',
            'id' => $id,
            'key' => $this->app->config->get('YOUTUBE_API_KEY'),
        ]);
        try {
            $r = $this->app->http()->get($url, [], 10);
        } catch (\Throwable) {
            return ['error' => 'unreachable'] + $empty;
        }
        $data = $r->json();
        if ($r->status !== 200 || !is_array($data)) return ['error' => 'api_error'] + $empty;
        $item = $data['items'][0] ?? null;
        if (!is_array($item)) return ['error' => 'not_found'] + $empty;

        $snippet = $item['snippet'] ?? [];
        $details = $item['contentDetails'] ?? [];
        $status = $item['status'] ?? [];
        $region = $details['regionRestriction'] ?? [];
        return [
            'ok' => true,
            'error' => '',
            'id' => $id,
            'title' => (string) ($snippet['title'] ?? ''),
            'channel' => (string) ($snippet['channelTitle'] ?? ''),
            'description' => mb_substr((string) ($snippet['description'] ?? ''), 0, 1500),
            'tags' => array_slice(array_map('strval', (array) ($snippet['tags'] ?? [])), 0, 20),
            'duration_ms' => self::isoDurationMs((string) ($details['duration'] ?? '')),
            'embeddable' => (bool) ($status['embeddable'] ?? false),
            'public' => ($status['privacyStatus'] ?? '') === 'public' && ($status['uploadStatus'] ?? '') === 'processed',
            'live' => in_array($snippet['liveBroadcastContent'] ?? 'none', ['live', 'upcoming'], true),
            'age_restricted' => (($details['contentRating']['ytRating'] ?? '') === 'ytAgeRestricted'),
            'blocked' => array_map('strval', (array) ($region['blocked'] ?? [])),
            'allowed' => isset($region['allowed']) ? array_map('strval', (array) $region['allowed']) : null,
        ];
    }

    /** Whether the video plays in every market we care about. @param array<string,mixed> $v @param list<string> $markets */
    public static function playableIn(array $v, array $markets): bool
    {
        foreach ($markets as $m) {
            if (in_array($m, $v['blocked'], true)) return false;
            if (is_array($v['allowed']) && !in_array($m, $v['allowed'], true)) return false;
        }
        return true;
    }

    /** ISO 8601 duration (PT4M58S, P1DT2H) → milliseconds. */
    public static function isoDurationMs(string $iso): int
    {
        if (!preg_match('/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', $iso, $m)) return 0;
        $s = ((int) ($m[1] ?? 0)) * 86400 + ((int) ($m[2] ?? 0)) * 3600 + ((int) ($m[3] ?? 0)) * 60 + (int) ($m[4] ?? 0);
        return $s * 1000;
    }

    /**
     * Split "Artist - Title (Official Video)" into artist and a cleaned title.
     * Channels write it both ways round ("What A Beautiful Name - Hillsong
     * Worship"), so the half that matches the channel name is the artist.
     * Moderators can correct either field afterwards.
     *
     * @return array{0:string,1:string}
     */
    public static function splitTitle(string $title, string $channel): array
    {
        $clean = trim((string) preg_replace('/\s*[\(\[][^\)\]]*(official|video|lyric|audio|live|hd|4k)[^\)\]]*[\)\]]/i', '', $title));
        $artist = trim((string) preg_replace('/\s*-\s*Topic$|VEVO$/i', '', $channel));
        $norm = static fn(string $s): string => strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $s));
        $parts = preg_split('/\s+[-–|]\s+/u', $clean) ?: [$clean];
        if (count($parts) >= 2) {
            $a = trim((string) $parts[0]);
            $b = trim((string) end($parts));
            $ch = $norm($artist);
            if ($ch !== '' && str_contains($norm($b), $ch) && !str_contains($norm($a), $ch)) return [$b, $a];
            return [$a, trim(implode(' - ', array_slice($parts, 1)))];
        }
        return [$artist, $clean];
    }
}

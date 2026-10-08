<?php
declare(strict_types=1);

namespace Arche\Submission;

use Arche\ApiError;
use Arche\App;
use Arche\Audio\Mp3;
use Arche\Library\YouTube;
use Arche\Plan\Catalog;
use Arche\Program\PrayerHour;
use Arche\Program\SubmissionWindow;
use Arche\Program\Timing;
use Arche\Support\Files;
use Arche\Support\Ids;

/**
 * Listener submissions: song requests, video suggestions (a YouTube link, like
 * a song request — a preaching, a testimony, a mission video or a film; only
 * a video program takes them), recorded stories/testimonies/greetings/
 * prayers, and typed prayer requests.
 *
 *   received → checking → approved → scheduled → aired
 *                       ↘ review (human, only if enabled) ↘ rejected
 *                                  ↘ library (a song or video too late for its program)
 *                                  ↘ missed (anything else too late for it)
 *
 * A listener can only submit to the program on air now, and only while the
 * minute file says that type is open — the same rule the PWA shows, checked
 * again here because the client is not trusted. Everything that needs an
 * outside service (the Claude verdict, transcription) happens in a background
 * job, so the request itself answers in milliseconds.
 *
 * Rejections carry one of three deliberately vague reasons; what the
 * classifier actually objected to stays in `verdict`, which only the
 * moderators' API returns (they can overrule it, see overruleBlocker()).
 */
final class Submissions
{
    public const REASONS = ['not_program_fit', 'not_suitable', 'not_accepted'];
    /** Handed in as a YouTube link; approved, they join the library as the kind libraryKind() names. */
    public const VIDEO_TYPES = ['song', 'preaching', 'testimony_video', 'mission', 'film'];
    /** A video program's suggestions (the video sheet): Catalog::VIDEO_FORMATS' types. */
    public const SUGGESTION_TYPES = ['preaching', 'testimony_video', 'mission', 'film'];
    private const AUDIO_TYPES = ['story', 'testimony', 'greeting', 'prayer', 'intercession'];
    /** Prayers a listener sends in a prayer hour's prayer time, written or spoken, aired as they are. */
    public const INTERCESSION = 'intercession';

    public function __construct(private App $app) {}

    /** The library kind a video submission joins as, and airs as: 'song', or its video format. */
    public static function libraryKind(string $type): string
    {
        $format = array_search($type, Catalog::VIDEO_FORMATS, true);
        return is_string($format) ? $format : 'song';
    }

    /** `?, ?, …` for an IN list of $values, bound as text. @param list<string> $values */
    private static function marks(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }

    /** @return array<string,mixed>|null */
    public function get(int $id): ?array
    {
        return $this->app->store()->one('SELECT * FROM submissions WHERE id = ?', [$id]);
    }

    /** @return array<string,mixed>|null */
    public function byPublicId(string $publicId): ?array
    {
        return $this->app->store()->one('SELECT * FROM submissions WHERE public_id = ?', [$publicId]);
    }

    // --- intake -------------------------------------------------------------------

    /**
     * The program on air now and whether $type is open for it.
     *
     * @param array<string,mixed> $channel
     * @return array{program:array<string,mixed>,block:array{start:int,end:int,program_id:int}}
     */
    private function openProgram(array $channel, string $type): array
    {
        $now = $this->app->clock->nowMs();
        $block = $this->app->resolver()->blockAt($channel, $now);
        $program = $this->app->catalog()->program($block['program_id']);
        if ($program === null) throw new ApiError(409, 'no_program');
        $states = SubmissionWindow::states($this->app, $channel, $program, $block, $now);
        $state = is_array($states) ? ($states[$type] ?? null) : null;
        if ($state === null) throw new ApiError(409, 'not_accepted_now');
        if ($state === 'closed') throw new ApiError(409, 'closed');
        return ['program' => $program, 'block' => $block];
    }

    /** @param array<string,mixed> $identity */
    private function limit(array $identity, string $bucket, int $perHour, int $perDay): void
    {
        $rl = $this->app->rateLimit();
        $who = 'sub:' . $bucket . ':' . $identity['id'];
        if (!$rl->hit($who . ':h', $perHour, 3600) || !$rl->hit($who . ':d', $perDay, 86400)
            // Per address too, but generously: a church group on one Wi-Fi
            // shares it (SUBMISSIONS_PER_IP_HOUR).
            || !$rl->hit('sub:' . $rl->ipKey(), $this->app->config->int('SUBMISSIONS_PER_IP_HOUR', 60), 3600)) {
            throw new ApiError(429, 'rate_limited');
        }
    }

    private static function text(mixed $v, int $max): string
    {
        $s = trim((string) preg_replace('/[\p{Cc}\p{Cf}]/u', ' ', (string) $v));
        $s = (string) preg_replace('/\s+/u', ' ', $s);
        if (mb_strlen($s) > $max) throw new ApiError(422, 'too_long');
        return $s;
    }

    /**
     * @param array<string,mixed> $identity
     * @param array<string,mixed> $channel
     * @param array<string,mixed> $in url, message, name, place, lang
     * @return array<string,mixed> public view
     */
    public function submitSong(array $identity, array $channel, array $in): array
    {
        return $this->submitLink($identity, $channel, $in, 'song', 'song', 3, 8);
    }

    /**
     * A video suggested for a video program — `type` one of SUGGESTION_TYPES:
     * a preaching, a testimony (`testimony_video`; `testimony` is a recorded
     * one), a mission video or a film on YouTube, with an optional word on
     * why (the host may read it, like a dedication). One rate limit for all
     * of them: a listener could otherwise send four times as many.
     *
     * @param array<string,mixed> $identity
     * @param array<string,mixed> $channel
     * @param array<string,mixed> $in type, url, message, name, place, lang
     * @return array<string,mixed> public view
     */
    public function submitSuggestion(array $identity, array $channel, array $in): array
    {
        $type = (string) ($in['type'] ?? '');
        if (!in_array($type, self::SUGGESTION_TYPES, true)) throw new ApiError(422, 'bad_type');
        return $this->submitLink($identity, $channel, $in, $type, 'video', 2, 4);
    }

    /**
     * A preaching suggestion from an app loaded before the video sheet, which
     * still posts to /submissions/preaching.
     *
     * @param array<string,mixed> $identity
     * @param array<string,mixed> $channel
     * @param array<string,mixed> $in url, message, name, place, lang
     * @return array<string,mixed> public view
     */
    public function submitPreaching(array $identity, array $channel, array $in): array
    {
        return $this->submitSuggestion($identity, $channel, ['type' => 'preaching'] + $in);
    }

    /**
     * @param array<string,mixed> $identity
     * @param array<string,mixed> $channel
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    private function submitLink(array $identity, array $channel, array $in, string $type, string $bucket, int $perHour, int $perDay): array
    {
        if ($identity['banned']) throw new ApiError(403, 'banned');
        $ytId = YouTube::parseId((string) ($in['url'] ?? ''));
        if ($ytId === null) throw new ApiError(422, 'invalid_youtube_url');
        ['program' => $program, 'block' => $block] = $this->openProgram($channel, $type);
        $this->limit($identity, $bucket, $perHour, $perDay);
        $message = self::text($in['message'] ?? '', 200);
        $name = self::text($in['name'] ?? '', 30);
        $place = self::text($in['place'] ?? '', 40);

        $row = $this->insert($identity, $channel, $program, $block, $type, [
            'yt_id' => $ytId, 'message' => $message, 'name' => $name, 'place' => $place,
            'lang' => ($in['lang'] ?? '') === 'de' ? 'de' : 'en', 'consent_air' => 1,
        ]);
        return $this->publicView($row);
    }

    /**
     * A recording, already MP3 (the browser encodes it — the host has no ffmpeg).
     *
     * @param array<string,mixed> $identity
     * @param array<string,mixed> $channel
     * @param array<string,mixed> $in type, name, place, lang, consent_air, consent_replay
     * @return array<string,mixed>
     */
    public function submitAudio(array $identity, array $channel, array $in, string $tmpFile): array
    {
        if ($identity['banned']) throw new ApiError(403, 'banned');
        $type = (string) ($in['type'] ?? '');
        if (!in_array($type, self::AUDIO_TYPES, true)) throw new ApiError(422, 'bad_type');
        if (empty($in['consent_air'])) throw new ApiError(422, 'consent_required');
        ['program' => $program, 'block' => $block] = $this->openProgram($channel, $type);
        // A prayer in the prayer time has a bucket of its own: one who recorded
        // a request may still pray for others.
        $type === self::INTERCESSION ? $this->limit($identity, 'audio-intercession', 3, 6) : $this->limit($identity, 'audio', 2, 4);

        if (!is_file($tmpFile) || filesize($tmpFile) > 4_000_000) throw new ApiError(422, 'invalid_audio');
        $check = Mp3::inspect($tmpFile);
        $max = match ($type) {
            'greeting' => $this->app->config->int('GREETING_MAX_SECONDS', 60),
            self::INTERCESSION => $this->app->config->int('PRAYER_MAX_SECONDS', 60),
            default => $this->app->config->int('AUDIO_MAX_SECONDS', 90),
        } * 1000;
        if (!$check['ok'] || $check['ms'] < 3000 || $check['ms'] > $max + 1500) throw new ApiError(422, 'invalid_audio');

        $publicId = Ids::short(12);
        $dir = $this->app->config->dataDir . '/uploads';
        Files::ensureDir($dir, 0700);
        $upload = $dir . '/' . $publicId . '.mp3';
        if (!@move_uploaded_file($tmpFile, $upload) && !@rename($tmpFile, $upload)) throw new ApiError(500, 'upload_failed');
        @chmod($upload, 0600);

        $row = $this->insert($identity, $channel, $program, $block, $type, [
            'public_id' => $publicId,
            'mode' => 'audio',
            'name' => self::text($in['name'] ?? '', 30),
            'place' => self::text($in['place'] ?? '', 40),
            'lang' => ($in['lang'] ?? '') === 'de' ? 'de' : 'en',
            'upload' => basename($upload),
            'audio_ms' => $check['ms'],
            'consent_air' => 1,
            // A prayer is for its hour: never kept for replays.
            'consent_replay' => empty($in['consent_replay']) || $type === self::INTERCESSION ? 0 : 1,
        ]);
        return $this->publicView($row);
    }

    /**
     * @param array<string,mixed> $identity
     * @param array<string,mixed> $channel
     * @param array<string,mixed> $in text, name, place, lang, consent_air
     * @return array<string,mixed>
     */
    public function submitPrayer(array $identity, array $channel, array $in): array
    {
        if ($identity['banned']) throw new ApiError(403, 'banned');
        $text = self::text($in['text'] ?? '', 400);
        if (mb_strlen($text) < 5) throw new ApiError(422, 'too_short');
        ['program' => $program, 'block' => $block] = $this->openProgram($channel, 'prayer');
        $this->limit($identity, 'prayer', 3, 6);
        $row = $this->insert($identity, $channel, $program, $block, 'prayer', [
            'mode' => 'text',
            'text' => $text,
            'name' => self::text($in['name'] ?? '', 30),
            'place' => self::text($in['place'] ?? '', 40),
            'lang' => ($in['lang'] ?? '') === 'de' ? 'de' : 'en',
            // Shown on the prayer wall (text and day only) only with consent.
            'consent_air' => empty($in['consent_air']) ? 0 : 1,
        ]);
        return $this->publicView($row);
    }

    /**
     * A written prayer for a prayer hour's prayer time: read out on air word
     * for word, with first name and place — which the sender agrees to
     * (consent_air). Never on the prayer wall.
     *
     * @param array<string,mixed> $identity
     * @param array<string,mixed> $channel
     * @param array<string,mixed> $in text, name, place, lang, consent_air
     * @return array<string,mixed>
     */
    public function submitIntercession(array $identity, array $channel, array $in): array
    {
        if ($identity['banned']) throw new ApiError(403, 'banned');
        $text = self::text($in['text'] ?? '', 400);
        if (mb_strlen($text) < 5) throw new ApiError(422, 'too_short');
        if (empty($in['consent_air'])) throw new ApiError(422, 'consent_required');
        ['program' => $program, 'block' => $block] = $this->openProgram($channel, self::INTERCESSION);
        $this->limit($identity, self::INTERCESSION, 4, 8);
        $row = $this->insert($identity, $channel, $program, $block, self::INTERCESSION, [
            'mode' => 'text',
            'text' => $text,
            'name' => self::text($in['name'] ?? '', 30),
            'place' => self::text($in['place'] ?? '', 40),
            'lang' => ($in['lang'] ?? '') === 'de' ? 'de' : 'en',
            'consent_air' => 1,
        ]);
        return $this->publicView($row);
    }

    /**
     * @param array<string,mixed> $identity
     * @param array<string,mixed> $channel
     * @param array<string,mixed> $program
     * @param array{start:int,end:int,program_id:int} $block
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    private function insert(array $identity, array $channel, array $program, array $block, string $type, array $fields): array
    {
        $now = $this->app->clock->now();
        $row = $fields + [
            'public_id' => Ids::short(12),
            'identity_id' => (int) $identity['id'],
            'channel_id' => (int) $channel['id'],
            'program_id' => (int) $program['id'],
            'type' => $type,
            'status' => 'checking',
            'window_end' => $block['end'],
            'created' => $now,
            'updated' => $now,
        ];
        $id = $this->app->store()->insert('submissions', $row);
        $this->app->jobs()->enqueue('moderate', $id, 30, $now * 1000);
        return $this->get($id) ?? throw new \LogicException('insert vanished');
    }

    // --- decisions ------------------------------------------------------------------

    /**
     * Approve. Songs and videos graduate into the library at once (tags
     * only); a recording is published to /media only now. Too late for its
     * program, a song or video still graduates and may play in a later
     * program (the selection picks it like any other); anything else missed
     * its moment.
     *
     * @param array<string,mixed> $verdict
     * @param bool $overrule a moderator approves what was rejected (see overruleBlocker())
     * @param bool $keepMessage false: the song airs without the listener's dedication
     */
    public function approve(int $id, array $verdict, string $actor, bool $overrule = false, bool $keepMessage = true): void
    {
        $sub = $this->get($id) ?? throw new ApiError(404, 'not_found');
        if (!in_array($sub['status'], $overrule ? ['rejected'] : ['checking', 'review', 'received'], true)) return;
        $store = $this->app->store();
        $now = $this->app->clock->now();
        $set = ['status' => 'approved', 'reason' => '', 'verdict' => json_encode($verdict, JSON_UNESCAPED_UNICODE), 'updated' => $now];
        // Without its dedication the host names only who asked for the song.
        if (!$keepMessage) $set['message'] = '';
        $meta = json_decode((string) $sub['meta'], true) ?: [];
        foreach (['caption_en', 'caption_de', 'host_context'] as $k) {
            if (isset($verdict[$k])) $meta[$k] = mb_substr(trim((string) $verdict[$k]), 0, 300);
        }
        $set['meta'] = json_encode($meta, JSON_UNESCAPED_UNICODE);
        $late = !$this->canStillAir($sub);

        if (in_array($sub['type'], self::VIDEO_TYPES, true)) {
            $set['library_id'] = $this->graduateVideo($sub, $meta, $verdict);
        } elseif ($sub['mode'] === 'audio' && $sub['upload']) {
            $src = $this->app->config->dataDir . '/uploads/' . basename((string) $sub['upload']);
            if ($late && !(int) $sub['consent_replay']) {
                // It will never air: the recording stays private and goes.
                @unlink($src);
            } else {
                $bytes = @file_get_contents($src);
                if ($bytes === false) throw new \RuntimeException('Upload vanished for submission ' . $id);
                $set['audio'] = $this->app->media()->put('contrib', substr(hash('sha256', $bytes), 0, 20) . '.mp3', $bytes);
                @unlink($src);
                $set['library_id'] = $this->graduateContribution($sub, (string) $set['audio'], $meta, $verdict);
            }
            $set['upload'] = null;
        }
        if ($late) $set['status'] = self::lateStatus($sub);
        $store->update('submissions', $set, 'id = ?', [$id]);
        $store->audit($actor, $overrule ? 'Rejection overruled' : 'Submission approved', $sub['public_id'] . ' ' . $sub['type']);
    }

    /**
     * Why a moderator can no longer approve this rejection (null: they can).
     * A rejected recording is deleted at once, as the privacy policy says, and
     * a video YouTube will not play in the embed cannot air at all; the
     * station's own length limits, the classifier and every failure can be
     * overruled.
     *
     * @param array<string,mixed> $sub
     */
    public function overruleBlocker(array $sub): ?string
    {
        if ($sub['status'] !== 'rejected') return 'not_rejected';
        if ($sub['mode'] === 'audio') return 'recording_deleted';
        if (in_array($sub['type'], self::VIDEO_TYPES, true)) {
            $meta = json_decode((string) $sub['meta'], true) ?: [];
            $verdict = json_decode((string) $sub['verdict'], true) ?: [];
            // Its creator asked not to be on our platform — as long as that stands.
            if (isset($verdict['group_blocked']) && $this->app->groups()->isBlocked((int) $verdict['group_blocked'])) return 'group_blocked';
            // Older rows name the problem with one string ('unplayable').
            $unplayable = array_diff((array) ($verdict['video'] ?? []), ['too_long', 'too_short']);
            if (!isset($meta['youtube']) || $unplayable) return 'video_unplayable';
        }
        return null;
    }

    /**
     * A song request becomes a library song, a suggested video a library item
     * of its kind (libraryKind()). A video already in the library stays what
     * it is there (its suggestion still airs as what it was suggested as).
     *
     * @param array<string,mixed> $sub @param array<string,mixed> $meta @param array<string,mixed> $verdict
     */
    private function graduateVideo(array $sub, array $meta, array $verdict): int
    {
        $lib = $this->app->library();
        $existing = $lib->byYouTube((string) $sub['yt_id']);
        if ($existing !== null) return (int) $existing['id'];
        $now = $this->app->clock->now();
        $v = $meta['youtube'] ?? [];
        $kind = self::libraryKind((string) $sub['type']);
        // "Artist - Title" works for songs and most sermons. A testimony, a
        // mission video or a film is titled "My story | … | Name": split, the
        // host would read out half a title — it keeps the whole, and the
        // channel says better who it is from.
        $split = in_array($kind, ['song', 'preaching'], true);
        $title = $split ? (string) ($v['title'] ?? '') : (string) ($v['full_title'] ?? $v['title'] ?? '');
        $by = $split ? (string) ($v['artist'] ?? '') : (string) ($v['channel'] ?? $v['artist'] ?? '');
        $id = $this->app->store()->insert('library_items', [
            'kind' => $kind,
            'yt_id' => $sub['yt_id'],
            'title' => mb_substr($title !== '' ? $title : (string) $sub['yt_id'], 0, 120),
            'artist' => mb_substr($by, 0, 120),
            'thumb' => $this->app->media()->cacheThumb((string) $sub['yt_id']),
            'duration_ms' => (int) ($v['duration_ms'] ?? 0) ?: 240_000,
            'languages' => json_encode(array_values(array_intersect(['en', 'de'], (array) ($verdict['languages'] ?? [])))),
            'themes' => json_encode(\Arche\Plan\Catalog::tags((array) ($verdict['themes'] ?? []))),
            'moods' => json_encode(\Arche\Plan\Catalog::tags((array) ($verdict['moods'] ?? []))),
            'program_ids' => '[]',
            'source' => 'submission',
            'submission_id' => (int) $sub['id'],
            'yt_channel' => ($v['channel_id'] ?? '') !== '' ? (string) $v['channel_id'] : null,
            'group_id' => $this->app->groups()->ofChannel((string) ($v['channel_id'] ?? '')),
            'created' => $now,
            'updated' => $now,
        ]);
        // Looked up while it was checked (Library\Knowledge): its clean names, while they are on air.
        $known = $this->app->knowledge()->get((string) $sub['yt_id']);
        if ($known !== null) $this->app->knowledge()->applyNames($known);
        return $id;
    }

    /** @param array<string,mixed> $sub @param array<string,mixed> $meta @param array<string,mixed> $verdict */
    private function graduateContribution(array $sub, string $audio, array $meta, array $verdict): ?int
    {
        // Only with the listener's consent does a recording air a second time.
        if (!(int) $sub['consent_replay']) return null;
        $now = $this->app->clock->now();
        return $this->app->store()->insert('library_items', [
            'kind' => 'contrib',
            'audio' => $audio,
            'title' => mb_substr(trim($sub['name'] . ', ' . $sub['place'], ', '), 0, 120) ?: 'Listener',
            'duration_ms' => (int) $sub['audio_ms'],
            'languages' => json_encode([$sub['lang']]),
            'themes' => json_encode(\Arche\Plan\Catalog::tags((array) ($verdict['themes'] ?? []))),
            'program_ids' => json_encode([(int) $sub['program_id']]),
            'source' => 'submission',
            'submission_id' => (int) $sub['id'],
            'meta' => json_encode(['kind' => $sub['type'], 'caption_en' => $meta['caption_en'] ?? '', 'caption_de' => $meta['caption_de'] ?? ''], JSON_UNESCAPED_UNICODE),
            'active' => 0, // replays are switched on per item by a moderator
            'created' => $now,
            'updated' => $now,
        ]);
    }

    /**
     * What a listener handed in is kept RETAIN_SUBMISSIONS_DAYS, as the
     * privacy policy promises: then the row goes — name, place, text,
     * transcript — and with it a published recording, unless the library
     * still holds it for replays (which needed the listener's consent).
     * Bounded per run; the rest goes the next day.
     */
    public function purgeBefore(int $ts, int $limit = 500): int
    {
        $store = $this->app->store();
        $rows = $store->all('SELECT id, audio, upload FROM submissions WHERE created < ? ORDER BY id LIMIT ?', [$ts, $limit]);
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $audio = (string) ($r['audio'] ?? '');
            if ($audio !== '' && (int) $store->value('SELECT COUNT(*) FROM library_items WHERE audio = ?', [$audio]) === 0) {
                $this->app->media()->delete($audio);
            }
            if ($r['upload']) @unlink($this->app->config->dataDir . '/uploads/' . basename((string) $r['upload']));
            $store->tx(function () use ($store, $id) {
                $store->query('UPDATE timeline_items SET submission_id = NULL WHERE submission_id = ?', [$id]);
                $store->query('UPDATE library_items SET submission_id = NULL WHERE submission_id = ?', [$id]);
                $store->query('DELETE FROM submissions WHERE id = ?', [$id]);
            });
        }
        return count($rows);
    }

    /** @param array<string,mixed> $verdict */
    public function reject(int $id, string $reason, array $verdict, string $actor): void
    {
        if (!in_array($reason, self::REASONS, true)) $reason = 'not_accepted';
        $sub = $this->get($id);
        if ($sub === null) return;
        $this->app->store()->update('submissions', [
            'status' => 'rejected',
            'reason' => $reason,
            'verdict' => json_encode($verdict, JSON_UNESCAPED_UNICODE),
            'updated' => $this->app->clock->now(),
        ], 'id = ?', [$id]);
        if ($sub['upload']) @unlink($this->app->config->dataDir . '/uploads/' . basename((string) $sub['upload']));
        $this->app->store()->audit($actor, 'Submission rejected', $sub['public_id'] . ' ' . $reason);
    }

    public function toReview(int $id, array $verdict): void
    {
        $this->app->store()->update('submissions', [
            'status' => 'review', 'verdict' => json_encode($verdict, JSON_UNESCAPED_UNICODE), 'updated' => $this->app->clock->now(),
        ], 'id = ?', [$id]);
    }

    /** Fail closed: a submission we could not check is not accepted. */
    public function rejectAfterError(int $id): void
    {
        $this->reject($id, 'not_accepted', ['error' => 'moderation_failed'], 'system');
    }

    // --- scheduling -------------------------------------------------------------------

    /**
     * Approved songs and recordings waiting for this program, longest-waiting
     * first. Text prayers go through takePrayers(), suggested videos through
     * waitingVideos(), a prayer hour's requests and prayers through
     * takeNext().
     *
     * @param array<string,mixed> $channel
     * @param array<string,mixed> $program
     * @return list<array<string,mixed>>
     */
    public function waiting(array $channel, array $program, int $limit): array
    {
        return $this->app->store()->all(
            "SELECT * FROM submissions WHERE channel_id = ? AND program_id = ? AND status = 'approved'
             AND NOT (type = 'prayer' AND mode = 'text') AND type NOT IN (" . self::marks(self::SUGGESTION_TYPES) . ", 'intercession')
             ORDER BY created, id LIMIT ?",
            [(int) $channel['id'], (int) $program['id'], ...self::SUGGESTION_TYPES, $limit],
        );
    }

    /**
     * Approved video suggestions waiting for this program, of any kind it
     * took, longest-waiting first: a video program airs them before its own
     * selection.
     *
     * @param array<string,mixed> $channel
     * @param array<string,mixed> $program
     * @return list<array<string,mixed>>
     */
    public function waitingVideos(array $channel, array $program, int $limit): array
    {
        return $this->app->store()->all(
            "SELECT * FROM submissions WHERE channel_id = ? AND program_id = ? AND status = 'approved'
             AND type IN (" . self::marks(self::SUGGESTION_TYPES) . ') ORDER BY created, id LIMIT ?',
            [(int) $channel['id'], (int) $program['id'], ...self::SUGGESTION_TYPES, $limit],
        );
    }

    /** Taken into the plan — true once per submission, so it is drafted exactly once. */
    public function schedule(int $id): bool
    {
        return $this->app->store()->update('submissions', ['status' => 'scheduled', 'updated' => $this->app->clock->now()], "id = ? AND status = 'approved'", [$id]) === 1;
    }

    /** It cannot air any more (its song left the library). */
    public function markMissed(int $id): void
    {
        $this->app->store()->update('submissions', ['status' => 'missed', 'updated' => $this->app->clock->now()], "id = ? AND status = 'approved'", [$id]);
    }

    /** It will not air in its program, but its video stays in the library's selection (a duplicate suggestion). */
    public function shelve(int $id): void
    {
        $this->app->store()->update('submissions', ['status' => 'library', 'updated' => $this->app->clock->now()], "id = ? AND status = 'approved'", [$id]);
    }

    /**
     * Where the plan could place something approved now: requests go to the
     * end of the drafts, which reach DRAFT ahead (a prayer hour's only
     * PRAYER_LEAD) — or further, when the last item runs past that.
     */
    private function reach(int $channelId, int $lead = Timing::DRAFT): int
    {
        $tail = $this->app->timeline()->tail($channelId);
        $end = $tail === null ? 0 : ($tail['start_ms'] ?? $tail['est_start']) + $tail['dur_ms'];
        return max($this->app->clock->nowMs() + $lead, $end);
    }

    /** How long a request or prayer takes on air: read out by its length, or its recording; a few seconds of quiet after. @param array<string,mixed> $sub */
    public static function airtime(array $sub): int
    {
        return $sub['mode'] === 'audio' ? (int) $sub['audio_ms'] + Timing::PRAYER_GAP : Timing::readingEstimate(mb_strlen((string) $sub['text']));
    }

    /**
     * Whether the plan can still place $sub inside its program: a block with
     * less than MIN_SONG left takes nothing new. A program that continues past
     * midnight (tomorrow starts with it) keeps its requests — its whole run
     * counts (PlanResolver::runAt).
     *
     * A suggested video waits for its program's video slot, and plays whole:
     * when the program is no video program any more, or the video is longer
     * than what is left after the plan, that never comes — waiting for it as
     * "approved", it would only keep intake closed (a film of two hours fills
     * the queue). It goes to the library instead.
     *
     * @param array<string,mixed> $sub
     */
    private function canStillAir(array $sub): bool
    {
        $channel = $this->app->catalog()->channel((int) $sub['channel_id']);
        if ($channel === null) return false;
        $program = $this->app->catalog()->program((int) $sub['program_id']);
        $suggestion = in_array($sub['type'], self::SUGGESTION_TYPES, true);
        if ($suggestion && !Catalog::isVideoFormat($program['settings']['format'] ?? null)) return false;
        $end = (int) $sub['window_end'];
        $run = $this->app->resolver()->runAt($channel, $end - 1);
        if ($run['program_id'] === (int) $sub['program_id']) {
            // A prayer hour reads and plays what listeners sent until its outro, planned PRAYER_LEAD ahead.
            if (PrayerHour::applies($program)) {
                return $this->app->prayerHour()->closingAt($channel, $program, $end - 1) - self::airtime($sub) >= $this->reach((int) $channel['id'], Timing::PRAYER_LEAD);
            }
            $end = max($end, $run['end']);
        }
        $reach = $this->reach((int) $channel['id']);
        if ($suggestion) {
            $meta = json_decode((string) $sub['meta'], true) ?: [];
            if ((int) ($meta['youtube']['duration_ms'] ?? 0) > $end + Timing::SOFT_OVERRUN - $reach) return false;
        }
        return $end - Timing::MIN_SONG >= $reach;
    }

    /** A song or video too late for its program stays in the library's selection; anything else had its one chance. @param array<string,mixed> $sub */
    private static function lateStatus(array $sub): string
    {
        return in_array($sub['type'], self::VIDEO_TYPES, true) ? 'library' : 'missed';
    }

    /**
     * Approved submissions the plan can no longer place inside their program
     * (a busy program, a late human decision) leave the queue: waiting as
     * "approved" for good, they would also keep intake closed. A recording
     * that will never air does not stay public either, unless the listener
     * allowed replays (then the library keeps it, switched off).
     */
    public function expireUnreachable(): int
    {
        $store = $this->app->store();
        $n = 0;
        foreach ($store->all("SELECT * FROM submissions WHERE status = 'approved'") as $sub) {
            if ($this->canStillAir($sub)) continue;
            $status = self::lateStatus($sub);
            if ($store->update('submissions', ['status' => $status, 'updated' => $this->app->clock->now()], "id = ? AND status = 'approved'", [$sub['id']]) === 0) continue;
            $n++;
            $audio = (string) ($sub['audio'] ?? '');
            if ($status === 'missed' && $audio !== '' && (int) $store->value('SELECT COUNT(*) FROM library_items WHERE audio = ?', [$audio]) === 0) {
                $this->app->media()->delete($audio);
                $store->update('submissions', ['audio' => null], 'id = ?', [$sub['id']]);
            }
        }
        return $n;
    }

    /**
     * Up to $n approved typed prayers for this program, longest-waiting first,
     * together at most $maxChars long (always the first) — each claimed
     * exactly once, like schedule(). Their readings carry the ids: committed,
     * they mark them with their start (markScheduled); dropped or discarded,
     * they give them back (requeue). One taken off the wall is not read out.
     *
     * @return list<int>
     */
    public function takePrayers(array $channel, array $program, int $n, int $maxChars = PHP_INT_MAX): array
    {
        $store = $this->app->store();
        $rows = $store->all(
            "SELECT id, text FROM submissions WHERE channel_id = ? AND program_id = ? AND status = 'approved' AND type = 'prayer' AND mode = 'text'
             AND hidden = 0 ORDER BY created, id LIMIT ?",
            [(int) $channel['id'], (int) $program['id'], $n],
        );
        $ids = [];
        $chars = 0;
        foreach ($rows as $r) {
            $len = mb_strlen((string) $r['text']);
            if ($ids && $chars + $len > $maxChars) break;
            if ($this->schedule((int) $r['id'])) {
                $ids[] = (int) $r['id'];
                $chars += $len;
            }
        }
        return $ids;
    }

    /**
     * Open Doors' daily request, added to a prayer hour's run once: a typed
     * request of the station's own — approved without a check, it is Open
     * Doors' text — dated to the run's start, so it is read out first. On
     * the wall like the listeners' (with its source), gone after 90 days
     * like them.
     *
     * @param array<string,mixed> $channel
     * @param array<string,mixed> $program
     * @param array{start:int,end:int,program_id:int} $run
     * @param array<string,mixed> $item OpenDoors::current()
     */
    public function addStationRequest(array $channel, array $program, array $run, array $item): void
    {
        $store = $this->app->store();
        $station = $this->app->identities()->station();
        $created = intdiv($run['start'], 1000);
        $args = [(int) $station['id'], (int) $channel['id'], (int) $program['id'], $created];
        if ($store->value('SELECT 1 FROM submissions WHERE identity_id = ? AND channel_id = ? AND program_id = ? AND created >= ?', $args) !== null) return;
        $store->insert('submissions', [
            'public_id' => Ids::short(12),
            'identity_id' => (int) $station['id'],
            'channel_id' => (int) $channel['id'],
            'program_id' => (int) $program['id'],
            'type' => 'prayer',
            'mode' => 'text',
            'status' => 'approved',
            'name' => 'Open Doors',
            'place' => (string) ($item['country_de'] ?? ''),
            'text' => (string) $item['de'],
            'lang' => 'de',
            'consent_air' => 1,
            'window_end' => $run['end'],
            'meta' => json_encode(['source' => 'opendoors', 'guid' => (string) ($item['guid'] ?? ''), 'text_en' => (string) ($item['en'] ?? ''),
                'country_en' => (string) ($item['country_en'] ?? '')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'verdict' => json_encode(['languages' => ['de'], 'note' => "Open Doors' daily prayer request, added by the station."]),
            'created' => $created,
            'updated' => $this->app->clock->now(),
        ]);
    }

    /**
     * How many typed requests for this program wait to be read out: approved,
     * sent by $until, not taken off the wall, not tried MAX_TRIES times. The
     * prayer hour presents them, when there are any.
     *
     * @param array<int,int> $tries submission id → readings tried this run
     */
    public function waitingRequests(int $channelId, int $programId, int $until, array $tries, int $maxTries): int
    {
        $n = 0;
        foreach ($this->app->store()->all(
            "SELECT id FROM submissions WHERE channel_id = ? AND program_id = ? AND status = 'approved' AND type = 'prayer' AND mode = 'text'
             AND hidden = 0 AND created <= ?",
            [$channelId, $programId, intdiv($until, 1000)],
        ) as $r) {
            if (($tries[(int) $r['id']] ?? 0) < $maxTries) $n++;
        }
        return $n;
    }

    /**
     * A prayer hour's next words to air, claimed (schedule()) — the oldest
     * approved typed request sent by $until (the presentation), or with
     * $until null (the prayer time) the oldest request or prayer, written or
     * spoken. Skipped: what is taken off the wall, what was tried $maxTries
     * times this run, what no longer fits into $roomMs, text without a voice
     * to read it ($voice false); a recording that went is missed.
     *
     * @param array<int,int> $tries submission id → readings tried this run
     * @return array<string,mixed>|null the claimed row
     */
    public function takeNext(array $channel, array $program, ?int $until, array $tries, int $maxTries, int $roomMs, bool $voice): ?array
    {
        $rows = $this->app->store()->all(
            "SELECT * FROM submissions WHERE channel_id = ? AND program_id = ? AND status = 'approved' AND hidden = 0 AND "
            . ($until !== null ? "type = 'prayer' AND mode = 'text' AND created <= ?" : "type IN ('prayer', 'intercession')")
            . ' ORDER BY created, id LIMIT 30',
            $until !== null ? [(int) $channel['id'], (int) $program['id'], intdiv($until, 1000)] : [(int) $channel['id'], (int) $program['id']],
        );
        foreach ($rows as $sub) {
            if (($tries[(int) $sub['id']] ?? 0) >= $maxTries) continue;
            $audio = $sub['mode'] === 'audio';
            if (!$audio && !$voice) continue;
            if ($audio && (string) ($sub['audio'] ?? '') === '') {
                $this->markMissed((int) $sub['id']);
                continue;
            }
            if (self::airtime($sub) > $roomMs) continue;
            if ($this->schedule((int) $sub['id'])) return $sub;
        }
        return null;
    }

    public function markScheduled(int $id, int $startMs): void
    {
        $this->app->store()->update('submissions', ['status' => 'scheduled', 'aired_at' => $startMs, 'updated' => $this->app->clock->now()], 'id = ?', [$id]);
    }

    public function requeue(int $id): void
    {
        $this->app->store()->update('submissions', ['status' => 'approved', 'aired_at' => null, 'updated' => $this->app->clock->now()], "id = ? AND status = 'scheduled'", [$id]);
    }

    /**
     * A prayer request whose airing went with someone else's deleted account
     * (a shared prayer break) waits again — also when that break was on air
     * already ($startMs): it is blocked before its end.
     */
    public function giveBack(int $id, ?int $startMs): void
    {
        $this->app->store()->update(
            'submissions',
            ['status' => 'approved', 'aired_at' => null, 'updated' => $this->app->clock->now()],
            "id = ? AND (status = 'scheduled' OR (status = 'aired' AND aired_at = ?))",
            [$id, $startMs ?? -1],
        );
    }

    /**
     * Library ids of the songs and videos that requests and suggestions are
     * still waiting to play (approved, or in the plan and not yet on air).
     * The selection leaves them to their requests.
     *
     * @return array<int,true>
     */
    public function requestedLibraryIds(int $channelId): array
    {
        $out = [];
        foreach ($this->app->store()->all(
            'SELECT library_id FROM submissions WHERE channel_id = ? AND type IN (' . self::marks(self::VIDEO_TYPES) . ") AND library_id IS NOT NULL
             AND (status = 'approved' OR (status = 'scheduled' AND (aired_at IS NULL OR aired_at > ?)))",
            [$channelId, ...self::VIDEO_TYPES, $this->app->clock->nowMs()],
        ) as $r) {
            $out[(int) $r['library_id']] = true;
        }
        return $out;
    }

    /** Airtime (ms) already promised to listeners in this program. */
    public function queuedAirtime(int $channelId, int $programId): int
    {
        $store = $this->app->store();
        // A waiting video promises its whole length.
        $songs = (int) $store->value(
            'SELECT COALESCE(SUM(l.duration_ms), 0) FROM submissions s JOIN library_items l ON l.id = s.library_id
             WHERE s.channel_id = ? AND s.program_id = ? AND s.type IN (' . self::marks(self::VIDEO_TYPES) . ")
             AND (s.status = 'approved' OR (s.status = 'scheduled' AND (s.aired_at IS NULL OR s.aired_at > ?)))",
            [$channelId, $programId, ...self::VIDEO_TYPES, $this->app->clock->nowMs()],
        );
        $audio = (int) $store->value(
            "SELECT COALESCE(SUM(audio_ms), 0) FROM submissions WHERE channel_id = ? AND program_id = ? AND mode = 'audio'
             AND (status = 'approved' OR (status = 'scheduled' AND (aired_at IS NULL OR aired_at > ?)))",
            [$channelId, $programId, $this->app->clock->nowMs()],
        );
        // Typed requests and prayers take airtime too, read out by their
        // length (Timing::readingEstimate): a flood of them must close intake.
        $texts = (int) $store->value(
            "SELECT COALESCE(SUM(MAX(8000, 2000 + LENGTH(text) * 1000 / 14 + ?)), 0) FROM submissions
             WHERE channel_id = ? AND program_id = ? AND type IN ('prayer', 'intercession') AND mode = 'text'
             AND (status = 'approved' OR (status = 'scheduled' AND (aired_at IS NULL OR aired_at > ?)))",
            [Timing::PRAYER_GAP, $channelId, $programId, $this->app->clock->nowMs()],
        );
        return $songs + $audio + $texts;
    }

    public function markAired(): int
    {
        return $this->app->store()->query(
            "UPDATE submissions SET status = 'aired', updated = ? WHERE status = 'scheduled' AND aired_at IS NOT NULL AND aired_at <= ?",
            [$this->app->clock->now(), $this->app->clock->nowMs()],
        )->rowCount();
    }

    // --- read side ------------------------------------------------------------------------

    /** @param array<string,mixed> $identity @return list<array<string,mixed>> */
    public function forIdentity(array $identity, int $limit = 30): array
    {
        $rows = $this->app->store()->all(
            'SELECT s.* FROM submissions s JOIN identities i ON i.id = s.identity_id
             WHERE i.id = ? OR i.canonical_id = ? ORDER BY s.created DESC LIMIT ?',
            [(int) $identity['id'], (int) $identity['id'], $limit],
        );
        return array_map(fn($r) => $this->publicView($r), $rows);
    }

    /**
     * The prayer wall: typed prayer requests the sender agreed to show,
     * accepted and not taken down by a moderator, newest first. Text, day and
     * the first name and place the sender gave — whoever chose to stay
     * anonymous sent neither (the forms ask; a prayer can reveal faith or
     * health, Art. 9 GDPR, and the wall is readable by anyone who fetches
     * live.json). A place without a name is not shown, as it is not read.
     * The id is the one community voices used for prayers ('p' + public id),
     * so the app's reactions on a wall entry keep their shape.
     *
     * While a prayer hour is on air, its wall: every request sent to it — the
     * box or not: the form says so in a prayer hour — as it is read out, each
     * from the start of its reading (`from`, which the app waits for:
     * live.json is fetched every half minute), up to 60. Then the newest 30
     * of all programs again, the hour's among them only with their senders'
     * yes. A request of the station's own (Open Doors) says so (`source`) and
     * may carry its translation (`texts`).
     *
     * @return list<array{id:string,text:string,at:int,from?:int,name?:string,place?:string,source?:string,texts?:array<string,string>}>
     */
    public function wall(string $channel, int $limit = 30): array
    {
        $hour = $this->hourOnAir($channel);
        $rows = $this->app->store()->all(
            "SELECT s.public_id, s.text, s.created, s.aired_at, s.name, s.place, s.meta FROM submissions s JOIN channels c ON c.id = s.channel_id
             WHERE c.slug = ? AND s.type = 'prayer' AND s.mode = 'text' AND s.hidden = 0 AND "
            . ($hour !== null
                ? "s.status IN ('scheduled', 'aired') AND s.aired_at IS NOT NULL AND s.aired_at <= ? AND s.program_id = ? AND s.created >= ?
                   ORDER BY s.aired_at DESC, s.id DESC LIMIT 60"
                : "s.consent_air = 1 AND s.status IN ('approved', 'scheduled', 'aired') ORDER BY s.created DESC, s.id DESC LIMIT ?"),
            $hour !== null ? [$channel, $this->app->clock->nowMs() + Timing::LEAD, $hour['program_id'], intdiv($hour['start'], 1000)] : [$channel, $limit],
        );
        return array_map(function (array $r) use ($hour): array {
            $entry = ['id' => 'p' . $r['public_id'], 'text' => (string) $r['text'], 'at' => (int) $r['created'] * 1000];
            if ($hour !== null) $entry['from'] = (int) $r['aired_at'];
            $meta = json_decode((string) $r['meta'], true) ?: [];
            if (($meta['source'] ?? '') === 'opendoors') {
                $entry['source'] = 'Open Doors' . (trim((string) $r['place']) !== '' ? ' · ' . trim((string) $r['place']) : '');
                if (trim((string) ($meta['text_en'] ?? '')) !== '') $entry['texts'] = ['en' => trim((string) $meta['text_en'])];
            } elseif (trim((string) $r['name']) !== '') {
                $entry['name'] = trim((string) $r['name']);
                if (trim((string) $r['place']) !== '') $entry['place'] = trim((string) $r['place']);
            }
            return $entry;
        }, $rows);
    }

    /**
     * How many requests the prayer hour on air has received that are not yet
     * read out (the collection shows the number, not the requests): approved,
     * or planned and not yet on air. The station's own does not count. Null
     * when no prayer hour is on air.
     */
    public function collected(string $channel): ?int
    {
        $hour = $this->hourOnAir($channel);
        if ($hour === null) return null;
        return (int) $this->app->store()->value(
            "SELECT COUNT(*) FROM submissions s JOIN channels c ON c.id = s.channel_id
             WHERE c.slug = ? AND s.program_id = ? AND s.created >= ? AND s.type = 'prayer'
             AND (s.status = 'approved' OR (s.status = 'scheduled' AND (s.aired_at IS NULL OR s.aired_at > ?)))
             AND (CASE WHEN json_valid(s.meta) THEN json_extract(s.meta, '$.source') END) IS NULL",
            [$channel, $hour['program_id'], intdiv($hour['start'], 1000), $this->app->clock->nowMs()],
        );
    }

    /**
     * Whether a wall shows this request now: the prayer hour on air shows
     * every request of the hour once it is read out; any other wall (the
     * global one) only an accepted one its sender ticked. Only then can it be
     * prayed along with or reported.
     *
     * @param array<string,mixed> $s
     */
    public function shownOnWall(array $s): bool
    {
        if (!self::onWall($s, true) || !in_array($s['status'], ['approved', 'scheduled', 'aired'], true)) return false;
        $channel = $this->app->catalog()->channel((int) $s['channel_id']);
        $hour = $channel !== null ? $this->hourOnAir((string) $channel['slug']) : null;
        if ($hour !== null && (int) $s['program_id'] === $hour['program_id'] && (int) $s['created'] >= intdiv($hour['start'], 1000)) {
            return $s['aired_at'] !== null && (int) $s['aired_at'] <= $this->app->clock->nowMs() + Timing::LEAD;
        }
        return self::onWall($s);
    }

    /** The prayer hour on air on this channel now, as its run. @return array{start:int,end:int,program_id:int}|null */
    private function hourOnAir(string $slug): ?array
    {
        $channel = $this->app->catalog()->channelBySlug($slug);
        if ($channel === null) return null;
        try {
            $run = $this->app->resolver()->runAt($channel, $this->app->clock->nowMs());
        } catch (\RuntimeException) {
            return null; // a channel without programs: no hour, the usual wall
        }
        return PrayerHour::applies($this->app->catalog()->program($run['program_id'])) ? $run : null;
    }

    /**
     * 🙏 on a request on the wall: praying along. Each device counts once per
     * request (`who` is a key of its own per device and request); only a
     * request a wall may show can be prayed with, and a cap per address keeps
     * a script of made-up devices from inflating the number.
     *
     * @return bool whether it counted now
     */
    public function prayAlong(string $publicId, string $deviceId): bool
    {
        $sub = $this->byPublicId($publicId);
        if ($sub === null || !$this->shownOnWall($sub)) return false;
        $store = $this->app->store();
        $who = $this->app->identities()->prayKey($deviceId, (int) $sub['id']);
        if ($store->value('SELECT 1 FROM prayed_along WHERE submission_id = ? AND who = ?', [(int) $sub['id'], $who]) !== null) return false;
        $rl = $this->app->rateLimit();
        if (!$rl->hit('pray:' . $rl->ipKey(), $this->app->config->int('PRAY_ALONG_PER_IP_HOUR', 600), 3600)) return false;
        return $store->tx(function () use ($store, $sub, $who): bool {
            if ($store->query('INSERT OR IGNORE INTO prayed_along(submission_id, who) VALUES(?, ?)', [(int) $sub['id'], $who])->rowCount() !== 1) return false;
            $store->query('UPDATE submissions SET prayed_count = prayed_count + 1 WHERE id = ?', [(int) $sub['id']]);
            return true;
        });
    }

    /**
     * Who prayed along with which request matters only while a wall can show
     * it (to count each device once): then it goes, and only the number stays
     * with the request. The privacy policy says so.
     */
    public function forgetPrayedAlong(): int
    {
        $keep = [];
        foreach ($this->app->catalog()->channels() as $c) {
            $slug = (string) $c['slug'];
            // The wall shown now (a prayer hour's), and the one shown after it.
            foreach ($this->wall($slug) as $e) $keep[] = substr($e['id'], 1);
            foreach ($this->app->store()->all(
                "SELECT s.public_id FROM submissions s JOIN channels c ON c.id = s.channel_id
                 WHERE c.slug = ? AND s.type = 'prayer' AND s.mode = 'text' AND s.consent_air = 1 AND s.hidden = 0
                 AND s.status IN ('approved', 'scheduled', 'aired') ORDER BY s.created DESC, s.id DESC LIMIT 30",
                [$slug],
            ) as $r) $keep[] = (string) $r['public_id'];
        }
        $store = $this->app->store();
        if (!$keep) return $store->query('DELETE FROM prayed_along')->rowCount();
        $keep = array_values(array_unique($keep));
        $marks = implode(',', array_fill(0, count($keep), '?'));
        return $store->query("DELETE FROM prayed_along WHERE submission_id NOT IN (SELECT id FROM submissions WHERE public_id IN ($marks))", $keep)->rowCount();
    }

    /**
     * Whether a wall may show this submission: a typed prayer request, not
     * taken down — in a prayer hour ($prayerHour: its wall shows every request
     * of the hour while it is on air) any; on the global wall only with its
     * sender's yes (the box, never ticked in advance).
     *
     * @param array<string,mixed> $s
     */
    public static function onWall(array $s, bool $prayerHour = false): bool
    {
        return $s['type'] === 'prayer' && $s['mode'] === 'text' && (int) ($s['hidden'] ?? 0) === 0 && ($prayerHour || (int) $s['consent_air'] === 1);
    }

    /**
     * A moderator takes a typed prayer off the wall (or puts it back). Taken
     * off, it is not read out on air any more either: the readings and
     * repeats planned for it go (the committed minutes stay as they are), and
     * it waits until a moderator puts it back or its program ends.
     *
     * @return bool false when there is no typed prayer with this id
     */
    public function setHidden(string $publicId, bool $hidden): bool
    {
        $changed = $this->app->store()->update('submissions', ['hidden' => $hidden ? 1 : 0, 'updated' => $this->app->clock->now()],
            "public_id = ? AND type = 'prayer' AND mode = 'text'", [$publicId]) === 1;
        if ($changed && $hidden) $this->app->timeline()->dropRepeatsOf((int) ($this->byPublicId($publicId)['id'] ?? 0));
        return $changed;
    }

    /**
     * Enough listeners reported a request on the wall (Moderation\Reports): it
     * comes off until a moderator decides — `hidden = 2`, so /mod can tell it
     * from their own takedown. Like setHidden, what is planned for it goes.
     *
     * @return bool whether it was on the wall until now
     */
    public function hideByReports(int $id): bool
    {
        $changed = $this->app->store()->update('submissions', ['hidden' => 2, 'updated' => $this->app->clock->now()], 'id = ? AND hidden = 0', [$id]) === 1;
        if ($changed) $this->app->timeline()->dropRepeatsOf($id);
        return $changed;
    }

    /**
     * What a listener sees: pending / scheduled / aired / rejected (+ one of
     * three generic reasons) / library ("may play in a future program") /
     * missed (accepted, but its program ran out of time).
     *
     * @param array<string,mixed> $s
     * @return array<string,mixed>
     */
    public function publicView(array $s): array
    {
        $status = match ((string) $s['status']) {
            'received', 'checking', 'review' => 'pending',
            'approved' => 'approved',
            'scheduled' => 'scheduled',
            'aired' => 'aired',
            'library' => 'library',
            'missed' => 'missed',
            default => 'rejected',
        };
        $meta = json_decode((string) $s['meta'], true) ?: [];
        return [
            'id' => (string) $s['public_id'],
            'type' => (string) $s['type'],
            'mode' => (string) $s['mode'],
            'status' => $status,
            'reason' => $status === 'rejected' ? ((string) $s['reason'] ?: 'not_accepted') : null,
            'title' => (string) ($meta['youtube']['title'] ?? (in_array($s['type'], ['prayer', self::INTERCESSION], true) && $s['mode'] === 'text' ? mb_substr((string) $s['text'], 0, 60) : '')),
            'airsAt' => $status === 'scheduled' && $s['aired_at'] !== null ? (int) $s['aired_at'] : null,
            'airedAt' => $status === 'aired' ? (int) $s['aired_at'] : null,
            'created' => (int) $s['created'] * 1000,
            // How many prayed along with it on the wall: shown to its sender only.
            'prayedWith' => (int) ($s['prayed_count'] ?? 0),
        ];
    }
}

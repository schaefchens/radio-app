<?php
declare(strict_types=1);

namespace Arche\Submission;

use Arche\ApiError;
use Arche\App;
use Arche\Audio\Mp3;
use Arche\Library\YouTube;
use Arche\Program\SubmissionWindow;
use Arche\Support\Files;
use Arche\Support\Ids;

/**
 * Listener submissions: song requests, recorded stories/testimonies/greetings/
 * prayers, and typed prayer requests.
 *
 *   received → checking → approved → scheduled → aired
 *                       ↘ review (human, only if enabled) ↘ rejected
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
    private const AUDIO_TYPES = ['story', 'testimony', 'greeting', 'prayer'];

    public function __construct(private App $app) {}

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
        if ($identity['banned']) throw new ApiError(403, 'banned');
        $ytId = YouTube::parseId((string) ($in['url'] ?? ''));
        if ($ytId === null) throw new ApiError(422, 'invalid_youtube_url');
        ['program' => $program, 'block' => $block] = $this->openProgram($channel, 'song');
        $this->limit($identity, 'song', 3, 8);
        $message = self::text($in['message'] ?? '', 200);
        $name = self::text($in['name'] ?? '', 30);
        $place = self::text($in['place'] ?? '', 40);

        $row = $this->insert($identity, $channel, $program, $block, 'song', [
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
        $this->limit($identity, 'audio', 2, 4);

        if (!is_file($tmpFile) || filesize($tmpFile) > 4_000_000) throw new ApiError(422, 'invalid_audio');
        $check = Mp3::inspect($tmpFile);
        $max = ($type === 'greeting' ? $this->app->config->int('GREETING_MAX_SECONDS', 60) : $this->app->config->int('AUDIO_MAX_SECONDS', 90)) * 1000;
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
            'consent_replay' => empty($in['consent_replay']) ? 0 : 1,
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
            // Shown as a community voice on the main screen only with consent.
            'consent_air' => empty($in['consent_air']) ? 0 : 1,
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
     * Approve. Songs graduate into the library at once (tags only); a
     * recording is published to /media only now. If the program window has
     * already closed, the submission still graduates and waits for the next
     * matching program instead of being wasted.
     *
     * @param array<string,mixed> $verdict
     */
    /**
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
        // Without its dedication a request is a plain track: nothing is read out.
        if (!$keepMessage) $set['message'] = '';
        $meta = json_decode((string) $sub['meta'], true) ?: [];
        foreach (['caption_en', 'caption_de', 'host_context'] as $k) {
            if (isset($verdict[$k])) $meta[$k] = mb_substr(trim((string) $verdict[$k]), 0, 300);
        }
        $set['meta'] = json_encode($meta, JSON_UNESCAPED_UNICODE);

        if ($sub['type'] === 'song') {
            $set['library_id'] = $this->graduateSong($sub, $meta, $verdict);
        } elseif ($sub['mode'] === 'audio' && $sub['upload']) {
            $src = $this->app->config->dataDir . '/uploads/' . basename((string) $sub['upload']);
            $bytes = @file_get_contents($src);
            if ($bytes === false) throw new \RuntimeException('Upload vanished for submission ' . $id);
            $set['audio'] = $this->app->media()->put('contrib', substr(hash('sha256', $bytes), 0, 20) . '.mp3', $bytes);
            @unlink($src);
            $set['upload'] = null;
            $set['library_id'] = $this->graduateContribution($sub, (string) $set['audio'], $meta, $verdict);
        }
        // Missed its window: it lives on in the library, not in the live queue.
        if ((int) $sub['window_end'] <= $this->app->clock->nowMs() + 15 * 60_000) $set['status'] = 'library';
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
        if ($sub['type'] === 'song') {
            $meta = json_decode((string) $sub['meta'], true) ?: [];
            $verdict = json_decode((string) $sub['verdict'], true) ?: [];
            // Older rows name the problem with one string ('unplayable').
            $unplayable = array_diff((array) ($verdict['video'] ?? []), ['too_long', 'too_short']);
            if (!isset($meta['youtube']) || $unplayable) return 'video_unplayable';
        }
        return null;
    }

    /** @param array<string,mixed> $sub @param array<string,mixed> $meta @param array<string,mixed> $verdict */
    private function graduateSong(array $sub, array $meta, array $verdict): int
    {
        $lib = $this->app->library();
        $existing = $lib->byYouTube((string) $sub['yt_id']);
        if ($existing !== null) return (int) $existing['id'];
        $now = $this->app->clock->now();
        $v = $meta['youtube'] ?? [];
        return $this->app->store()->insert('library_items', [
            'kind' => 'song',
            'yt_id' => $sub['yt_id'],
            'title' => mb_substr((string) ($v['title'] ?? $sub['yt_id']), 0, 120),
            'artist' => mb_substr((string) ($v['artist'] ?? ''), 0, 120),
            'thumb' => $this->app->media()->cacheThumb((string) $sub['yt_id']),
            'duration_ms' => (int) ($v['duration_ms'] ?? 0) ?: 240_000,
            'languages' => json_encode(array_values(array_intersect(['en', 'de'], (array) ($verdict['languages'] ?? [])))),
            'themes' => json_encode(\Arche\Plan\Catalog::tags((array) ($verdict['themes'] ?? []))),
            'moods' => json_encode(\Arche\Plan\Catalog::tags((array) ($verdict['moods'] ?? []))),
            'program_ids' => '[]',
            'source' => 'submission',
            'submission_id' => (int) $sub['id'],
            'created' => $now,
            'updated' => $now,
        ]);
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
     * The next approved song/recording for this program, marked as scheduled
     * so it is drafted exactly once. Text prayers go through takePrayers().
     *
     * @param array<string,mixed> $channel
     * @param array<string,mixed> $program
     * @return array<string,mixed>|null
     */
    public function nextForProgram(array $channel, array $program): ?array
    {
        $store = $this->app->store();
        $sub = $store->one(
            "SELECT * FROM submissions WHERE channel_id = ? AND program_id = ? AND status = 'approved'
             AND NOT (type = 'prayer' AND mode = 'text') ORDER BY created LIMIT 1",
            [(int) $channel['id'], (int) $program['id']],
        );
        if ($sub === null) return null;
        $store->update('submissions', ['status' => 'scheduled', 'updated' => $this->app->clock->now()], "id = ? AND status = 'approved'", [$sub['id']]);
        return $sub;
    }

    /** @return list<int> up to $n approved text prayers, now scheduled */
    public function takePrayers(array $channel, array $program, int $n): array
    {
        $store = $this->app->store();
        $rows = $store->all(
            "SELECT id FROM submissions WHERE channel_id = ? AND program_id = ? AND status = 'approved' AND type = 'prayer' AND mode = 'text'
             ORDER BY created LIMIT ?",
            [(int) $channel['id'], (int) $program['id'], $n],
        );
        $ids = array_map(fn($r) => (int) $r['id'], $rows);
        foreach ($ids as $id) $store->update('submissions', ['status' => 'scheduled', 'updated' => $this->app->clock->now()], 'id = ?', [$id]);
        return $ids;
    }

    public function markScheduled(int $id, int $startMs): void
    {
        $this->app->store()->update('submissions', ['status' => 'scheduled', 'aired_at' => $startMs, 'updated' => $this->app->clock->now()], 'id = ?', [$id]);
    }

    public function requeue(int $id): void
    {
        $this->app->store()->update('submissions', ['status' => 'approved', 'aired_at' => null, 'updated' => $this->app->clock->now()], "id = ? AND status = 'scheduled'", [$id]);
    }

    /** Airtime (ms) already promised to listeners in this program. */
    public function queuedAirtime(int $channelId, int $programId): int
    {
        $store = $this->app->store();
        $songs = (int) $store->value(
            "SELECT COALESCE(SUM(l.duration_ms), 0) FROM submissions s JOIN library_items l ON l.id = s.library_id
             WHERE s.channel_id = ? AND s.program_id = ? AND s.type = 'song' AND (s.status = 'approved' OR (s.status = 'scheduled' AND (s.aired_at IS NULL OR s.aired_at > ?)))",
            [$channelId, $programId, $this->app->clock->nowMs()],
        );
        $audio = (int) $store->value(
            "SELECT COALESCE(SUM(audio_ms), 0) FROM submissions WHERE channel_id = ? AND program_id = ? AND mode = 'audio'
             AND (status = 'approved' OR (status = 'scheduled' AND (aired_at IS NULL OR aired_at > ?)))",
            [$channelId, $programId, $this->app->clock->nowMs()],
        );
        return $songs + $audio;
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
     * What a listener sees: pending / scheduled / aired / rejected (+ one of
     * three generic reasons) / library ("will play in a future program").
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
            default => 'rejected',
        };
        $meta = json_decode((string) $s['meta'], true) ?: [];
        return [
            'id' => (string) $s['public_id'],
            'type' => (string) $s['type'],
            'mode' => (string) $s['mode'],
            'status' => $status,
            'reason' => $status === 'rejected' ? ((string) $s['reason'] ?: 'not_accepted') : null,
            'title' => (string) ($meta['youtube']['title'] ?? ($s['type'] === 'prayer' && $s['mode'] === 'text' ? mb_substr((string) $s['text'], 0, 60) : '')),
            'airsAt' => $status === 'scheduled' && $s['aired_at'] !== null ? (int) $s['aired_at'] : null,
            'airedAt' => $status === 'aired' ? (int) $s['aired_at'] : null,
            'created' => (int) $s['created'] * 1000,
        ];
    }
}

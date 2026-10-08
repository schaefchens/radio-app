<?php
declare(strict_types=1);

namespace Arche\Moderation;

use Arche\App;
use Arche\Config;
use Arche\Library\Knowledge;
use Arche\Library\YouTube;
use Arche\Submission\Submissions;

/**
 * Automatic moderation, as a job with one outside call per phase:
 *
 *   song, video      start → youtube (Data API checks) → judge (Claude)
 *   audio            start → transcribe (OpenAI) → judge
 *   prayer           start → judge
 *
 * (A video: a preaching, testimony, mission video or film suggested for a
 * video program — Submissions::SUGGESTION_TYPES.)
 *
 * Two stages in one verdict: the baseline (safety, legality, Christian
 * relevance) and the per-program fit (the program's type, themes and moods).
 * It fails closed — no key, no budget, a refusal or repeated errors all end in
 * "not accepted this time", never in an unchecked broadcast. `uncertain` goes
 * to the human review queue only when MODERATION_HUMAN_REVIEW is on.
 */
final class Moderator
{
    /**
     * How long a listener's link may be, in seconds, when the env sets no
     * `<TYPE>_MIN_SECONDS` / `<TYPE>_MAX_SECONDS`: a missing key must never
     * read as 0, which would refuse every video as too long.
     */
    private const VIDEO_LIMITS = [
        'song' => [60, 720],
        'preaching' => [300, 5400],
        'testimony_video' => [120, 3600],
        'mission' => [180, 5400],
        // A film runs two hours and more.
        'film' => [300, 10800],
    ];

    /** How long a request's check waits for its video's look-up (seconds). */
    private const KNOWLEDGE_WAIT_SONG = 180;
    private const KNOWLEDGE_WAIT_VIDEO = 600;

    public function __construct(private App $app) {}

    /** @param array<string,mixed> $job */
    public function runPhase(array $job): ?string
    {
        $subs = $this->app->submissions();
        $sub = $subs->get((int) $job['ref_id']);
        if ($sub === null || $sub['status'] !== 'checking') return null;
        $phase = (string) $job['phase'];

        if ($phase === 'start') {
            return match (true) {
                in_array($sub['type'], Submissions::VIDEO_TYPES, true) => 'youtube',
                $sub['mode'] === 'audio' => 'transcribe',
                default => 'judge',
            };
        }
        if ($phase === 'youtube') return $this->checkVideo($sub);
        if ($phase === 'knowledge' || $phase === 'wait:knowledge') return $this->awaitKnowledge($sub);
        if ($phase === 'transcribe') {
            $file = $this->app->config->dataDir . '/uploads/' . basename((string) $sub['upload']);
            if (!is_file($file)) {
                $subs->rejectAfterError((int) $sub['id']);
                return null;
            }
            $text = $this->app->openai()->transcribe($file, (string) $sub['lang']);
            $this->app->usage()->recordStt((int) $sub['audio_ms']);
            $this->app->store()->update('submissions', ['transcript' => mb_substr($text, 0, 4000)], 'id = ?', [$sub['id']]);
            return 'judge';
        }
        if ($phase === 'judge') {
            $this->judge($subs->get((int) $sub['id']) ?? $sub);
            return null;
        }
        return null;
    }

    /** @param array<string,mixed> $sub */
    private function checkVideo(array $sub): ?string
    {
        $yt = $this->app->youtube();
        $subs = $this->app->submissions();
        if (!$yt->configured() && !$this->app->config->stubAi()) {
            $subs->reject((int) $sub['id'], 'not_accepted', ['error' => 'youtube_not_configured'], 'moderator');
            return null;
        }
        // The Data API is no AI: with a key it is asked even in stub mode (the
        // e2e stack points it at a fake); only without one does a stub answer.
        $v = $yt->configured() ? $yt->video((string) $sub['yt_id']) : self::stubVideo((string) $sub['yt_id'], (string) $sub['type']);
        // Our side or Google's being unreachable is retried by the job lease;
        // anything YouTube actually says about the video is final.
        if (!$v['ok'] && in_array($v['error'], ['unreachable', 'api_error'], true)) throw new \RuntimeException('YouTube ' . $v['error']);
        // What YouTube told us is kept even when the video fails: a moderator
        // reviewing the decision needs to know which song it was.
        if ($v['ok']) {
            [$artist, $title] = YouTube::splitTitle($v['title'], $v['channel']);
            $meta = json_decode((string) $sub['meta'], true) ?: [];
            $meta['youtube'] = [
                'title' => $title, 'artist' => $artist, 'channel' => $v['channel'], 'channel_id' => (string) ($v['channel_id'] ?? ''), 'duration_ms' => $v['duration_ms'],
                'description' => mb_substr($v['description'], 0, 800), 'tags' => array_slice($v['tags'], 0, 12),
                // A testimony, a mission video or a film keeps it whole (Submissions::graduateVideo()).
                'full_title' => mb_substr($v['title'], 0, 200),
            ];
            $this->app->store()->update('submissions', ['meta' => json_encode($meta, JSON_UNESCAPED_UNICODE)], 'id = ?', [$sub['id']]);
        }
        // Its creator asked not to be on our platform (Library\Groups): the
        // listener hears the usual "not accepted", the moderators see who.
        $blocked = $this->app->groups()->blocking($v, $this->app->library()->byYouTube((string) $sub['yt_id']));
        if ($blocked !== null) {
            $subs->reject((int) $sub['id'], 'not_accepted', ['group_blocked' => (int) $blocked['id']], 'moderator');
            return null;
        }
        $problems = self::videoProblems($v, $this->app->config, (string) $sub['type']);
        if ($problems) {
            $markets = $this->app->config->list('SUBMISSION_MARKETS');
            $subs->reject((int) $sub['id'], 'not_suitable', [
                'video' => $problems,
                'duration_ms' => $v['duration_ms'],
                'blocked_in' => array_values(array_filter($markets, fn($m) => !YouTube::playableIn($v, [$m]))),
            ], 'moderator');
            return null;
        }
        return 'knowledge';
    }

    /**
     * A request's video is looked up before it is judged (Library\Knowledge):
     * what is really sung or said, not its title. The look-up starts in any
     * case — the host may use it — but the check waits for it only while the
     * admins use it in the checks: up to 3 minutes for a song, 10 for a video.
     * An approved request is announced about 8 minutes later, right after
     * approval, so the knowledge must be there by then. Waiting is no failure:
     * the deadline is its own (a wait never counts as an attempt).
     *
     * @param array<string,mixed> $sub
     */
    private function awaitKnowledge(array $sub): string
    {
        $knowledge = $this->app->knowledge();
        $kind = Submissions::libraryKind((string) $sub['type']);
        $row = $knowledge->ensure((string) $sub['yt_id'], $kind, 25);
        if (!$knowledge->settings()['checks'] || $row === null || Knowledge::settled($row)) return 'judge';
        $meta = json_decode((string) $sub['meta'], true) ?: [];
        $now = $this->app->clock->now();
        if (!isset($meta['knowledge_since'])) {
            $meta['knowledge_since'] = $now;
            $this->app->store()->update('submissions', ['meta' => json_encode($meta, JSON_UNESCAPED_UNICODE)], 'id = ?', [$sub['id']]);
        }
        $limit = $kind === 'song' ? self::KNOWLEDGE_WAIT_SONG : self::KNOWLEDGE_WAIT_VIDEO;
        return $now - (int) $meta['knowledge_since'] >= $limit ? 'judge' : 'wait:knowledge';
    }

    /**
     * Why a video cannot be a request (or, by $type, a suggested video), one
     * code per failed check. too_long and too_short are the station's rules
     * (a moderator may overrule them); the rest mean the embed would not play.
     *
     * @param array<string,mixed> $v YouTube::video()
     * @return list<string>
     */
    public static function videoProblems(array $v, Config $c, string $type = 'song'): array
    {
        if (!$v['ok']) return [(string) ($v['error'] ?: 'not_found')];
        $out = [];
        if (!$v['embeddable']) $out[] = 'not_embeddable';
        if (!$v['public']) $out[] = 'not_public';
        if ($v['live']) $out[] = 'live';
        if ($v['age_restricted']) $out[] = 'age_restricted';
        $key = isset(self::VIDEO_LIMITS[$type]) ? $type : 'song';
        [$min, $max] = [$c->int(strtoupper($key) . '_MIN_SECONDS', self::VIDEO_LIMITS[$key][0]), $c->int(strtoupper($key) . '_MAX_SECONDS', self::VIDEO_LIMITS[$key][1])];
        if ($v['duration_ms'] < $min * 1000) $out[] = 'too_short';
        if ($v['duration_ms'] > $max * 1000) $out[] = 'too_long';
        if (!YouTube::playableIn($v, $c->list('SUBMISSION_MARKETS'))) $out[] = 'region';
        return $out;
    }

    /** @param array<string,mixed> $sub */
    private function judge(array $sub): void
    {
        $subs = $this->app->submissions();
        $id = (int) $sub['id'];
        $c = $this->app->config;
        $moderationCalls = 0;
        // Every kind of check counts — built from the types, so a new one cannot slip past the cap.
        $kinds = ['moderate_audio', 'moderate_prayer', 'moderate_intercession', ...array_map(fn(string $t): string => 'moderate_' . $t, Submissions::VIDEO_TYPES)];
        foreach ($kinds as $k) $moderationCalls += $this->app->usage()->callsToday('text:' . $k);
        if ($moderationCalls >= $c->int('MODERATION_MAX_PER_DAY', 300)) {
            $subs->reject($id, 'not_accepted', ['error' => 'daily_cap'], 'moderator');
            return;
        }
        $program = $this->app->catalog()->program((int) $sub['program_id']);
        $video = in_array($sub['type'], Submissions::VIDEO_TYPES, true);
        $kind = match (true) {
            $video => 'moderate_' . $sub['type'],
            $sub['mode'] === 'audio' => 'moderate_audio',
            $sub['type'] === Submissions::INTERCESSION => 'moderate_intercession',
            default => 'moderate_prayer',
        };
        $meta = json_decode((string) $sub['meta'], true) ?: [];

        $data = [
            'type' => $sub['type'],
            'program' => $program ? [
                'title' => $program['title_en'],
                'description' => $program['description_en'],
                'format' => $program['settings']['format'],
                'themes' => $program['themes'],
                'moods' => $program['moods'],
                'allowed_types' => $program['allowed'],
            ] : null,
            'listener' => ['name' => $sub['name'], 'place' => $sub['place']],
        ];
        $settings = $this->app->knowledge()->settings();
        $checks = $settings['checks'];
        if ($video) {
            $data['video'] = $meta['youtube'] ?? [];
            if (isset($data['video']['description'])) $data['video']['description'] = self::uploaderText((string) $data['video']['description']);
            $data['message'] = (string) $sub['message'];
            if ($checks) {
                $known = Knowledge::forJudge($this->app->knowledge()->get((string) $sub['yt_id']));
                // Fails closed: what nobody heard is not put on air (a moderator may still look).
                if ($known === null) {
                    if ($c->bool('MODERATION_HUMAN_REVIEW')) {
                        $subs->toReview($id, ['error' => 'no_knowledge', 'type_allowed' => true]);
                        return;
                    }
                    $subs->reject($id, 'not_accepted', ['error' => 'no_knowledge'], 'moderator');
                    return;
                }
                $data['knowledge'] = $known;
            }
        } elseif ($sub['mode'] === 'audio') {
            $data['transcript'] = (string) $sub['transcript'];
            $data['language'] = (string) $sub['lang'];
        } elseif ($sub['type'] === Submissions::INTERCESSION) {
            $data['prayer'] = (string) $sub['text'];
        } else {
            $data['prayer_request'] = (string) $sub['text'];
        }

        $result = $this->app->text()->json(
            $kind,
            'moderation',
            Policy::system($checks ? $settings['standard'] : null),
            "Judge this submission (JSON data):\n" . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            Policy::schema($sub['mode'] === 'audio', $checks),
            2048,
            'low',
        );

        if (!$result->ok()) {
            // A declined request is an answer too: the content was not suitable.
            if ($result->reason === 'refusal') {
                $subs->reject($id, 'not_suitable', ['refusal' => true], 'moderator');
                return;
            }
            if (in_array($result->reason, ['budget', 'no_key'], true)) {
                $subs->reject($id, 'not_accepted', ['error' => $result->reason], 'moderator');
                return;
            }
            throw new \RuntimeException('Moderation call failed: ' . $result->reason);
        }

        $v = $result->data ?? [];
        $safe = ($v['safe'] ?? false) === true;
        $christian = ($v['christian'] ?? false) === true;
        $fit = ($v['program_fit'] ?? false) === true;
        $messageOk = ($v['message_ok'] ?? false) === true || ($video && trim((string) $sub['message']) === '');
        $verdict = (string) ($v['verdict'] ?? 'uncertain');
        // The station's standard, while the admins use it in the checks: missing counts as not biblical.
        $biblical = !$checks || ($v['biblical'] ?? false) === true;
        $allowed = $program !== null && in_array($sub['type'], $program['allowed'], true);
        // Decided here, not by the model: kept with the verdict for the moderators.
        $v['type_allowed'] = $allowed;
        // What was heard, beside the verdict: the moderators read why.
        if (isset($data['knowledge'])) $v['heard'] = array_intersect_key($data['knowledge']['heard'], array_flip(['christian', 'biblical', 'concerns', 'explicit', 'age', 'summary_en']));

        if ($verdict === 'approve' && $safe && $christian && $biblical && $fit && $messageOk && $allowed) {
            $subs->approve($id, $v, 'moderator');
            return;
        }
        if ($safe && $christian && $biblical && (!$fit || !$allowed) && $verdict !== 'uncertain') {
            $subs->reject($id, 'not_program_fit', $v, 'moderator');
            return;
        }
        if ($verdict === 'uncertain' && $safe && $c->bool('MODERATION_HUMAN_REVIEW')) {
            $subs->toReview($id, $v);
            return;
        }
        $subs->reject($id, $verdict === 'uncertain' ? 'not_accepted' : 'not_suitable', $v, 'moderator');
    }

    /**
     * A video's description as the check reads it: without the links,
     * addresses and handles almost every official video carries. The model
     * took them for promotion by the listener and rejected "God's Not Dead"
     * as unsafe. What is left still names the song, the album and the artist.
     */
    public static function uploaderText(string $text, int $max = 500): string
    {
        $text = preg_replace('~(?:https?://|www\.)\S+~iu', '', $text) ?? '';
        $text = preg_replace('~[\w.+-]+@[\w-]+(?:\.[\w-]+)+~u', '', $text) ?? '';
        $text = preg_replace('~(?<![\w@])@[\w.]+~u', '', $text) ?? '';
        $text = preg_replace('~[ \t]+~u', ' ', $text) ?? '';
        $text = preg_replace('~\s*\n\s*~u', "\n", $text) ?? '';
        return mb_substr(trim($text), 0, $max);
    }

    /** Queue one batch job when chat highlights are waiting for a decision. */
    public function queueHighlights(): void
    {
        $waiting = (int) $this->app->store()->value("SELECT COUNT(*) FROM highlights WHERE status = 'candidate'");
        if ($waiting > 0) $this->app->jobs()->enqueue('highlights', 1, 50, $this->app->clock->nowMs());
    }

    /** @param array<string,mixed> $job */
    public function runHighlights(array $job): ?string
    {
        $store = $this->app->store();
        $batch = $store->all("SELECT uid, name, country, lang, text FROM highlights WHERE status = 'candidate' ORDER BY likes DESC, at LIMIT 20");
        if (!$batch) return null;
        $items = array_map(fn($h) => ['id' => $h['uid'], 'text' => $h['text'], 'lang' => $h['lang']], $batch);
        $result = $this->app->text()->json(
            'moderate_highlights',
            'moderation',
            Policy::highlightsSystem(),
            "Chat messages (JSON data):\n" . json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ['type' => 'object', 'properties' => ['approved' => ['type' => 'array', 'items' => ['type' => 'string']]],
                'required' => ['approved'], 'additionalProperties' => false],
            1024,
            'low',
        );
        if (!$result->ok() && !in_array($result->reason, ['refusal', 'budget', 'no_key'], true)) {
            throw new \RuntimeException('Highlight moderation failed: ' . $result->reason);
        }
        $approved = array_flip(array_map('strval', (array) ($result->data['approved'] ?? [])));
        $now = $this->app->clock->now();
        foreach ($batch as $h) {
            $store->update('highlights', ['status' => isset($approved[$h['uid']]) ? 'approved' : 'rejected', 'updated' => $now], 'uid = ?', [$h['uid']]);
        }
        // More waiting? The next tick picks the job up again.
        return (int) $store->value("SELECT COUNT(*) FROM highlights WHERE status = 'candidate'") > 0 ? 'start' : null;
    }

    /** A video for each type, inside its length limits. @return array<string,mixed> */
    public static function stubVideo(string $id, string $type): array
    {
        [$title, $channel, $tag, $ms] = match ($type) {
            'preaching' => ['Stub Preacher - Stub Sermon', 'Stub Church', 'sermon', 1_800_000],
            'testimony_video' => ['Stub Witness - My Story of Faith', 'Stub Stories', 'testimony', 600_000],
            'mission' => ['Stub Mission - Report from the Field', 'Stub Mission', 'mission', 1_200_000],
            'film' => ['Stub Film (Full Movie)', 'Stub Studio', 'film', 6_000_000],
            default => ['Stub Artist - Stub Song (Official Video)', 'Stub Artist', 'worship', 240_000],
        };
        return ['ok' => true, 'error' => '', 'id' => $id, 'title' => $title, 'channel' => $channel,
            'description' => '', 'tags' => [$tag], 'duration_ms' => $ms, 'embeddable' => true, 'public' => true,
            'live' => false, 'age_restricted' => false, 'blocked' => [], 'allowed' => null];
    }
}

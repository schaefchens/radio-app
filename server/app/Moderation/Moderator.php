<?php
declare(strict_types=1);

namespace Arche\Moderation;

use Arche\App;
use Arche\Library\YouTube;

/**
 * Automatic moderation, as a job with one outside call per phase:
 *
 *   song     start → youtube (Data API checks) → judge (Claude)
 *   audio    start → transcribe (OpenAI) → judge
 *   prayer   start → judge
 *
 * Two stages in one verdict: the baseline (safety, legality, Christian
 * relevance) and the per-program fit (the program's type, themes and moods).
 * It fails closed — no key, no budget, a refusal or repeated errors all end in
 * "not accepted this time", never in an unchecked broadcast. `uncertain` goes
 * to the human review queue only when MODERATION_HUMAN_REVIEW is on.
 */
final class Moderator
{
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
                $sub['type'] === 'song' => 'youtube',
                $sub['mode'] === 'audio' => 'transcribe',
                default => 'judge',
            };
        }
        if ($phase === 'youtube') return $this->checkVideo($sub);
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
        $v = $yt->configured() ? $yt->video((string) $sub['yt_id']) : $this->stubVideo((string) $sub['yt_id']);
        // Our side or Google's being unreachable is retried by the job lease;
        // anything YouTube actually says about the video is final.
        if (!$v['ok'] && in_array($v['error'], ['unreachable', 'api_error'], true)) throw new \RuntimeException('YouTube ' . $v['error']);
        $c = $this->app->config;
        $fine = $v['ok'] && $v['embeddable'] && $v['public'] && !$v['live'] && !$v['age_restricted']
            && $v['duration_ms'] >= $c->int('SONG_MIN_SECONDS', 60) * 1000
            && $v['duration_ms'] <= $c->int('SONG_MAX_SECONDS', 720) * 1000
            && YouTube::playableIn($v, $c->list('SUBMISSION_MARKETS'));
        if (!$fine) {
            $subs->reject((int) $sub['id'], 'not_suitable', ['video' => $v['ok'] ? 'unplayable' : $v['error']], 'moderator');
            return null;
        }
        [$artist, $title] = YouTube::splitTitle($v['title'], $v['channel']);
        $meta = json_decode((string) $sub['meta'], true) ?: [];
        $meta['youtube'] = [
            'title' => $title, 'artist' => $artist, 'channel' => $v['channel'], 'duration_ms' => $v['duration_ms'],
            'description' => mb_substr($v['description'], 0, 800), 'tags' => array_slice($v['tags'], 0, 12),
        ];
        $this->app->store()->update('submissions', ['meta' => json_encode($meta, JSON_UNESCAPED_UNICODE)], 'id = ?', [$sub['id']]);
        return 'judge';
    }

    /** @param array<string,mixed> $sub */
    private function judge(array $sub): void
    {
        $subs = $this->app->submissions();
        $id = (int) $sub['id'];
        $c = $this->app->config;
        $moderationCalls = 0;
        foreach (['moderate_song', 'moderate_audio', 'moderate_prayer'] as $k) $moderationCalls += $this->app->usage()->callsToday('text:' . $k);
        if ($moderationCalls >= $c->int('MODERATION_MAX_PER_DAY', 300)) {
            $subs->reject($id, 'not_accepted', ['error' => 'daily_cap'], 'moderator');
            return;
        }
        $program = $this->app->catalog()->program((int) $sub['program_id']);
        $kind = $sub['type'] === 'song' ? 'moderate_song' : ($sub['mode'] === 'audio' ? 'moderate_audio' : 'moderate_prayer');
        $meta = json_decode((string) $sub['meta'], true) ?: [];

        $data = [
            'type' => $sub['type'],
            'program' => $program ? [
                'title' => $program['title_en'],
                'description' => $program['description_en'],
                'themes' => $program['themes'],
                'moods' => $program['moods'],
                'allowed_types' => $program['allowed'],
            ] : null,
            'listener' => ['name' => $sub['name'], 'place' => $sub['place']],
        ];
        if ($sub['type'] === 'song') {
            $data['video'] = $meta['youtube'] ?? [];
            $data['message'] = (string) $sub['message'];
        } elseif ($sub['mode'] === 'audio') {
            $data['transcript'] = (string) $sub['transcript'];
            $data['language'] = (string) $sub['lang'];
        } else {
            $data['prayer_request'] = (string) $sub['text'];
        }

        $result = $this->app->text()->json(
            $kind,
            'moderation',
            Policy::system(),
            "Judge this submission (JSON data):\n" . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            Policy::schema($sub['mode'] === 'audio'),
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
        $messageOk = ($v['message_ok'] ?? false) === true || ($sub['type'] === 'song' && trim((string) $sub['message']) === '');
        $verdict = (string) ($v['verdict'] ?? 'uncertain');
        $allowed = $program !== null && in_array($sub['type'], $program['allowed'], true);

        if ($verdict === 'approve' && $safe && $christian && $fit && $messageOk && $allowed) {
            $subs->approve($id, $v, 'moderator');
            return;
        }
        if ($safe && $christian && (!$fit || !$allowed) && $verdict !== 'uncertain') {
            $subs->reject($id, 'not_program_fit', $v, 'moderator');
            return;
        }
        if ($verdict === 'uncertain' && $safe && $c->bool('MODERATION_HUMAN_REVIEW')) {
            $subs->toReview($id, $v);
            return;
        }
        $subs->reject($id, $verdict === 'uncertain' ? 'not_accepted' : 'not_suitable', $v, 'moderator');
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

    /** @return array<string,mixed> */
    private function stubVideo(string $id): array
    {
        return ['ok' => true, 'error' => '', 'id' => $id, 'title' => 'Stub Artist - Stub Song (Official Video)', 'channel' => 'Stub Artist',
            'description' => '', 'tags' => ['worship'], 'duration_ms' => 240_000, 'embeddable' => true, 'public' => true,
            'live' => false, 'age_restricted' => false, 'blocked' => [], 'allowed' => null];
    }
}

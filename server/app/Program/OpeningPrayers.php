<?php
declare(strict_types=1);

namespace Arche\Program;

use Arche\ApiError;
use Arche\App;
use Arche\Audio\Mp3;

/**
 * Opening prayers a moderator prepared for a prayer hour: a recording in
 * their own voice, or a text the host voice reads word for word (no AI
 * writing, only in the languages filled in). Each airing of the program takes
 * the oldest one waiting; with none, the AI host prays. One is marked aired
 * when it is committed, so a plan thrown away, a gate or a voice not ready in
 * time leaves it waiting for the next airing.
 *
 * Not part of the Catalog: adding one must not bump the plan version, which
 * would throw every channel's drafts away.
 */
final class OpeningPrayers
{
    public const MAX_AUDIO_MS = 180_000;
    public const MAX_TEXT = 1100;

    public function __construct(private App $app) {}

    /** @return list<array<string,mixed>> waiting ones first (oldest first), then the aired ones (latest first) */
    public function list(int $programId): array
    {
        return array_map([self::class, 'view'], $this->app->store()->all(
            "SELECT * FROM opening_prayers WHERE program_id = ?
             ORDER BY status = 'aired', CASE WHEN status = 'aired' THEN -aired_at ELSE id END LIMIT 50",
            [$programId],
        ));
    }

    /** @return array<string,mixed>|null the oldest one waiting */
    public function next(int $programId): ?array
    {
        return $this->app->store()->one("SELECT * FROM opening_prayers WHERE program_id = ? AND status = 'waiting' ORDER BY id LIMIT 1", [$programId]);
    }

    /** @return array<string,mixed> */
    public function addText(int $programId, string $name, string $en, string $de, string $actor): array
    {
        [$en, $de] = [trim($en), trim($de)];
        if ($en === '' && $de === '') throw new ApiError(422, 'missing_text');
        if (mb_strlen($en) > self::MAX_TEXT || mb_strlen($de) > self::MAX_TEXT) throw new ApiError(422, 'too_long');
        return $this->insert($programId, ['mode' => 'text', 'name' => $name, 'text_en' => $en, 'text_de' => $de], $actor);
    }

    /** A recording, already MP3 (an upload, or the browser encodes it). @return array<string,mixed> */
    public function addAudio(int $programId, string $name, string $tmpFile, string $actor): array
    {
        $check = Mp3::inspect($tmpFile);
        if (!$check['ok'] || $check['ms'] < 5_000 || $check['ms'] > self::MAX_AUDIO_MS + 1_500) throw new ApiError(422, 'invalid_audio');
        $bytes = (string) file_get_contents($tmpFile);
        $url = $this->app->media()->put('prayers', substr(hash('sha256', $bytes), 0, 20) . '.mp3', $bytes);
        return $this->insert($programId, ['mode' => 'audio', 'name' => $name, 'audio' => $url, 'audio_ms' => $check['ms']], $actor);
    }

    /** Only one still waiting: an aired prayer stays in the list as what was prayed. */
    public function delete(int $id, string $actor): void
    {
        $row = $this->app->store()->one("SELECT * FROM opening_prayers WHERE id = ? AND status = 'waiting'", [$id]) ?? throw new ApiError(404, 'not_found');
        $this->app->store()->query('DELETE FROM opening_prayers WHERE id = ?', [$id]);
        if ($row['audio']) $this->app->media()->delete((string) $row['audio']);
        $this->app->store()->audit($actor, 'Opening prayer deleted', (string) $id);
    }

    public function markAired(int $id, int $startMs): void
    {
        $this->app->store()->update('opening_prayers', ['status' => 'aired', 'aired_at' => $startMs], "id = ? AND status = 'waiting'", [$id]);
    }

    /**
     * Aired ones go after RETAIN_SUBMISSIONS_DAYS with their recording, like
     * what listeners send (a voice and a name are personal data too); so do
     * all of a program that is deleted.
     */
    public function purge(int $airedBeforeMs, ?int $programId = null): int
    {
        $rows = $programId !== null
            ? $this->app->store()->all('SELECT id, audio FROM opening_prayers WHERE program_id = ?', [$programId])
            : $this->app->store()->all("SELECT id, audio FROM opening_prayers WHERE status = 'aired' AND aired_at < ?", [$airedBeforeMs]);
        foreach ($rows as $r) {
            if ($r['audio']) $this->app->media()->delete((string) $r['audio']);
            $this->app->store()->query('DELETE FROM opening_prayers WHERE id = ?', [(int) $r['id']]);
        }
        return count($rows);
    }

    /** @param array<string,mixed> $fields @return array<string,mixed> */
    private function insert(int $programId, array $fields, string $actor): array
    {
        $program = $this->app->catalog()->program($programId) ?? throw new ApiError(404, 'not_found');
        $fields['name'] = mb_substr(trim((string) $fields['name']), 0, 60);
        $id = $this->app->store()->insert('opening_prayers', $fields + [
            'program_id' => (int) $program['id'], 'created_by' => $actor, 'created' => $this->app->clock->now(),
        ]);
        $this->app->store()->audit($actor, 'Opening prayer added', $program['slug'] . ' ' . $fields['mode']);
        return self::view($this->app->store()->one('SELECT * FROM opening_prayers WHERE id = ?', [$id]) ?? []);
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private static function view(array $r): array
    {
        return [
            'id' => (int) $r['id'], 'mode' => (string) $r['mode'], 'name' => (string) $r['name'],
            'text_en' => (string) $r['text_en'], 'text_de' => (string) $r['text_de'],
            'audio' => $r['audio'], 'audio_ms' => (int) $r['audio_ms'],
            'status' => (string) $r['status'], 'aired_at' => $r['aired_at'] !== null ? (int) $r['aired_at'] : null,
            'created' => (int) $r['created'] * 1000,
        ];
    }
}

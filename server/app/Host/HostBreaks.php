<?php
declare(strict_types=1);

namespace Arche\Host;

use Arche\App;
use Arche\Audio\Mp3;
use Arche\Program\PrayerHour;
use Arche\Program\Timing;
use Arche\Submission\Submissions;
use Arche\Support\Ids;

/**
 * A host break is one job with one network call per phase:
 *
 *   script → tts:en → tts:de → ready
 *
 * so each fits one tick's budget, and a phase lost to a killed request is
 * retried by the job lease on the next tick. The cost gates run at script
 * time, not at drafting time: a listener who tunes in now should hear the
 * host in the next break already planned, not only in the ones planned later.
 */
final class HostBreaks
{
    public function __construct(private App $app) {}

    /**
     * Whether host breaks can be produced at all: a text model (Claude or
     * OpenAI) and a voice (OpenAI, or ElevenLabs when opted in) — or stub mode.
     */
    public function available(): bool
    {
        $c = $this->app->config;
        return $c->stubAi() || ($c->textProvider() !== '' && ($c->openaiKey() !== '' || $c->has('ELEVENLABS_API_KEY')));
    }

    /**
     * @param array<string,mixed> $channel
     * @param array<string,mixed> $program
     * @param array<string,mixed> $item the draft timeline row
     * @param array<string,mixed> $context
     */
    public function create(array $channel, array $program, string $kind, array $item, array $context = []): int
    {
        $now = $this->app->clock->now();
        $id = $this->app->store()->insert('host_breaks', [
            'channel_id' => (int) $channel['id'],
            'program_id' => (int) $program['id'],
            'kind' => $kind,
            'state' => 'pending',
            'context' => json_encode($context ?: new \stdClass(), JSON_UNESCAPED_UNICODE),
            'created' => $now,
            'updated' => $now,
        ]);
        // Earliest airtime first; announcements, prayers and every moment the
        // committer would rather delay than drop (a unit) before plain breaks.
        $priority = in_array($kind, ['announce', 'contrib', 'prayer', 'opening', 'invite'], true) || ($item['unit'] ?? null) !== null ? 10 : 20;
        $this->app->jobs()->enqueue('host', $id, $priority, (int) $item['est_start']);
        return $id;
    }

    /** @return array<string,mixed>|null */
    public function get(int $id): ?array
    {
        $row = $this->app->store()->one('SELECT * FROM host_breaks WHERE id = ?', [$id]);
        if ($row === null) return null;
        foreach (['context', 'texts', 'audio', 'durations'] as $k) {
            $v = json_decode((string) $row[$k], true);
            $row[$k] = is_array($v) ? $v : [];
        }
        $row['id'] = (int) $row['id'];
        return $row;
    }

    /** @param string $why 'late': its time came before its voice (a plan change gives none) */
    public function cancel(int $id, string $why = ''): void
    {
        $this->app->store()->query(
            "UPDATE host_breaks SET state = 'cancelled', source = CASE WHEN ? = '' THEN source ELSE 'skipped:' || ? END, updated = ?
             WHERE id = ? AND state = 'pending'",
            [$why, $why, $this->app->clock->now(), $id],
        );
        $this->app->jobs()->cancel('host', $id);
    }

    /**
     * The typed prayer requests a prayer break was drafted for. They have a
     * key of their own: the script phase stores the writer's context over the
     * drafted one, and there `prayers` are the texts, not the ids. A break
     * drafted before the key existed keeps its ids under `prayers` until its
     * script is written.
     *
     * @param array<string,mixed> $hb decoded host break
     * @return list<int>
     */
    public static function prayerIds(array $hb): array
    {
        $ids = $hb['context']['prayer_ids'] ?? $hb['context']['prayers'] ?? [];
        return array_values(array_filter((array) $ids, 'is_int'));
    }

    /** @param array<string,mixed> $hb */
    public function airDuration(array $hb): int
    {
        $max = 0;
        foreach ($hb['durations'] as $ms) $max = max($max, (int) $ms);
        return max(2000, $max + Timing::HOST_PAD);
    }

    /** @param array<string,mixed> $hb @return array<string,mixed> */
    public function payload(array $hb): array
    {
        return [
            'kind' => (string) $hb['kind'],
            'audio' => $hb['audio'],
            'text' => array_intersect_key($hb['texts'], $hb['audio']),
            'voices' => $hb['kind'] === 'break' ? array_slice($this->app->presence()->voices($this->channelSlug($hb)), 0, 3) : [],
            'prayers' => $this->wallRefs($hb),
        ];
    }

    /**
     * In a prayer hour, the requests this moment prays for that are on the
     * wall, by their wall id ('p' + public id): the app shows them as
     * "Praying now". Only ids — what may be shown of them is live.json's
     * business, where a moderator's takedown applies after publishing too.
     *
     * @param array<string,mixed> $hb
     * @return list<string>
     */
    private function wallRefs(array $hb): array
    {
        if ($hb['kind'] !== 'prayer' || !$this->inPrayerHour($hb)) return [];
        $ids = self::prayerIds($hb);
        if (isset($hb['context']['again_id'])) $ids[] = (int) $hb['context']['again_id'];
        $refs = [];
        foreach ($ids as $id) {
            $sub = $this->app->submissions()->get($id);
            if ($sub !== null && Submissions::onWall($sub)) $refs[] = 'p' . $sub['public_id'];
        }
        return $refs;
    }

    /**
     * Run one phase. Returns the next phase, or null when the job is finished
     * (ready, failed or cancelled).
     *
     * @param array<string,mixed> $job
     */
    public function runPhase(array $job): ?string
    {
        $hb = $this->get((int) $job['ref_id']);
        if ($hb === null || $hb['state'] !== 'pending') return null;
        $phase = $job['phase'] === 'start' ? 'script' : (string) $job['phase'];

        if ($phase === 'script') {
            $gate = $this->gate($hb);
            if ($gate !== null) {
                $this->fail($hb, $gate);
                return null;
            }
            $context = $this->app->hostWriter()->context($hb);
            $written = $this->app->hostWriter()->write($hb, $context);
            $this->save($hb['id'], [
                'context' => json_encode($context + $hb['context'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'texts' => json_encode($written['texts'], JSON_UNESCAPED_UNICODE),
                'source' => $written['source'],
            ]);
            return 'tts:' . $this->app->config->stationLangs()[0];
        }

        if (str_starts_with($phase, 'tts:')) {
            $lang = substr($phase, 4);
            $channel = $this->app->catalog()->channel((int) $hb['channel_id']) ?? [];
            $text = (string) ($hb['texts'][$lang] ?? '');
            if ($text !== '') {
                $spoken = $this->app->voice()->speak($text, $lang, $channel, (string) ($channel['host_style'] ?? ''));
                $name = sprintf('%d-%s.%s.mp3', $hb['id'], Ids::short(6), $lang);
                $url = $this->app->media()->put('host/' . gmdate('Ymd', $this->app->clock->now()), $name, $spoken['bytes']);
                $check = Mp3::inspect((string) $this->app->media()->path($url));
                if (!$check['ok']) throw new \RuntimeException('TTS returned no playable audio');
                $hb['audio'][$lang] = $url;
                $hb['durations'][$lang] = $check['ms'];
                $this->save($hb['id'], ['audio' => json_encode($hb['audio']), 'durations' => json_encode($hb['durations'])]);
            }
            $langs = $this->app->config->stationLangs();
            $i = array_search($lang, $langs, true);
            if ($i !== false && isset($langs[$i + 1])) return 'tts:' . $langs[$i + 1];
            if (!$hb['audio']) {
                $this->fail($hb, 'no_audio');
                return null;
            }
            $this->save($hb['id'], ['state' => 'ready']);
            return null;
        }
        return null;
    }

    /** Why this break should not cost anything right now, or null. @param array<string,mixed> $hb */
    private function gate(array $hb): ?string
    {
        $c = $this->app->config;
        if (!$this->available()) return 'unavailable';
        $slug = $this->channelSlug($hb);
        $owed = in_array($hb['kind'], ['announce', 'contrib'], true)
            || ($hb['kind'] === 'prayer' && self::prayerIds($hb) !== [])
            || (in_array($hb['kind'], ['intro', 'opening', 'invite'], true) && $this->inPrayerHour($hb));
        // A listener who handed something in gets their announcement or
        // prayer even when they are the only one listening. A prayer hour's
        // welcome, opening prayer and invitation are written about eight
        // minutes before the hour, before its listeners tune in: gated, the
        // hour opened without them for everyone who came on time. Everything
        // else — breaks, a prayer for everyone, the outro — needs an audience.
        if (!$owed && $this->app->presence()->listeners($slug) < $c->int('HOST_MIN_LISTENERS', 1)) return 'no_listeners';
        $today = (int) $this->app->store()->value(
            "SELECT COUNT(*) FROM host_breaks WHERE channel_id = ? AND state = 'ready' AND updated >= ?",
            [(int) $hb['channel_id'], $this->app->clock->now() - 86400],
        );
        if ($today >= $c->int('HOST_MAX_BREAKS_PER_DAY', 300)) return 'daily_cap';
        if (!$this->app->usage()->withinBudget()) return 'budget';
        return null;
    }

    /** @param array<string,mixed> $hb */
    private function inPrayerHour(array $hb): bool
    {
        return PrayerHour::applies($hb['program_id'] !== null ? $this->app->catalog()->program((int) $hb['program_id']) : null);
    }

    /** @param array<string,mixed> $hb */
    private function fail(array $hb, string $why): void
    {
        $this->save($hb['id'], ['state' => 'failed', 'source' => 'skipped:' . $why]);
    }

    /** @param array<string,mixed> $set */
    private function save(int $id, array $set): void
    {
        $this->app->store()->update('host_breaks', $set + ['updated' => $this->app->clock->now()], 'id = ?', [$id]);
    }

    /** @param array<string,mixed> $hb */
    private function channelSlug(array $hb): string
    {
        return (string) ($this->app->catalog()->channel((int) $hb['channel_id'])['slug'] ?? 'main');
    }
}

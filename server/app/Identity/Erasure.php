<?php
declare(strict_types=1);

namespace Arche\Identity;

use Arche\ApiError;
use Arche\App;
use Arche\Presence\Presence;
use Arche\Support\Files;

/**
 * Deleting an account, by its owner (the app stores require it in the app;
 * Google also on the web: /konto-loeschen). What goes at once:
 *
 * - the identity, every device logged in with its passphrase, and with them
 *   the name, country and the passphrase itself;
 * - everything it handed in: requests, recordings (also the ones kept for
 *   replays), prayer requests — off the wall, out of the program;
 * - the host's scripts and clips that name it — its own announcements and
 *   prayers, others' that react to its request or use its community voice —,
 *   its community voices, the chat reports it is the author of;
 * - what this device left in passing (presence, playback errors, praying along).
 *
 * What stays until its normal retention is listed in the privacy policy
 * (app/src/content/legal.ts): minute files already written (immutable,
 * 48 hours), backups (7 days), reports it filed (anonymised, 30 days), the
 * audit log (90 days). No network call here: the CDN purge is queued.
 */
final class Erasure
{
    /** How long a host job still writing a script may put a name back (sweep()). */
    private const SWEEP_SECONDS = 900;
    /** Waiting for the tick's publish phase, which makes no network calls: 10 s at most. */
    private const LOCK_TRIES = 100;

    /** @param int $lockTries tries at the publish lock, 100 ms apart */
    public function __construct(private App $app, private int $lockTries = self::LOCK_TRIES) {}

    /**
     * @param array<string,mixed> $root the canonical identity
     * @param string $deviceId the calling device ('' when unknown): its traces go too
     * @return array{devices:int,submissions:int}
     */
    public function erase(array $root, string $deviceId): array
    {
        $store = $this->app->store();
        $rootId = (int) $root['id'];
        if ($root['role'] === 'admin' && (int) $store->value(
            "SELECT COUNT(*) FROM identities WHERE role = 'admin' AND cred_key IS NOT NULL AND canonical_id IS NULL",
        ) <= 1) {
            // The station would need its setup key again.
            throw new ApiError(409, 'last_admin');
        }

        $rows = $store->all('SELECT id, public_id FROM identities WHERE id = ? OR canonical_id = ?', [$rootId, $rootId]);
        $ids = array_map('intval', array_column($rows, 'id'));
        $publicIds = array_map('strval', array_column($rows, 'public_id'));
        $in = implode(',', $ids);
        $subs = $store->all("SELECT id, public_id, channel_id, type, audio, upload, aired_at, name, place FROM submissions WHERE identity_id IN ($in)");
        $subIds = array_map('intval', array_column($subs, 'id'));
        $marks = implode(',', array_fill(0, count($publicIds), '?'));
        $voices = $store->all("SELECT uid, name, text FROM highlights WHERE sub IN ($marks)", $publicIds);
        $tags = array_map([Presence::class, 'voiceTag'], $publicIds);

        // Out of the program under the publish lock: the drafter and the
        // committer change the plan only while they hold it. Without it,
        // nothing is deleted yet and the listener tries again.
        $replays = $subIds ? $store->all("SELECT id, audio FROM library_items WHERE kind = 'contrib' AND submission_id IN (" . implode(',', $subIds) . ')') : [];
        $breaks = null;
        for ($try = 0; $try < $this->lockTries && $breaks === null; $try++) {
            $breaks = $this->app->lock()->run('publish', fn() => $this->withdraw($subIds, $subs, $voices, $tags, $replays));
            if ($breaks === null) usleep(100_000);
        }
        if ($breaks === null) throw new ApiError(503, 'busy');
        $this->app->hostBreaks()->forget($breaks, $subIds);

        // Recordings kept for replays (out of the program above): out of the library.
        foreach ($replays as $lib) {
            $store->query('DELETE FROM library_items WHERE id = ?', [(int) $lib['id']]);
            $this->app->media()->delete((string) ($lib['audio'] ?? '') ?: null);
        }
        foreach ($subs as $s) {
            $this->app->media()->delete((string) ($s['audio'] ?? '') ?: null);
            if ($s['upload']) @unlink($this->app->config->dataDir . '/uploads/' . basename((string) $s['upload']));
        }

        $now = $this->app->clock->now();
        $store->tx(function () use ($store, $subIds, $publicIds, $marks, $ids, $rootId, $deviceId, $now) {
            foreach ($subIds as $id) $this->app->jobs()->cancel('moderate', $id);
            if ($subIds) {
                $S = implode(',', $subIds);
                $store->query("UPDATE library_items SET submission_id = NULL WHERE submission_id IN ($S)");
                // prayed_along and wall_reports go with them (ON DELETE CASCADE).
                $store->query("DELETE FROM submissions WHERE id IN ($S)");
            }
            $store->query("DELETE FROM highlights WHERE sub IN ($marks)", $publicIds);
            $store->query("DELETE FROM chat_reports WHERE author IN ($marks)", $publicIds);
            // Reports it filed stay for the moderators, without who filed them.
            $store->query("UPDATE chat_reports SET reporter = 'gone:' || id WHERE reporter IN ($marks)", $publicIds);
            $store->query("UPDATE opening_prayers SET created_by = '' WHERE created_by IN ($marks)", array_map(fn($p) => "mod:$p", $publicIds));
            foreach ($ids as $id) {
                $store->query("DELETE FROM attempts WHERE key LIKE ? OR key LIKE ? OR key = ?", ["sub:%:$id:%", "report:$id:%", "wake:$id"]);
            }
            if ($deviceId !== '') $this->forgetDevice($deviceId);
            // Aliases first: ON DELETE SET NULL would make them identities of their own.
            $store->query('DELETE FROM identities WHERE canonical_id = ?', [$rootId]);
            $store->query('DELETE FROM identities WHERE id = ?', [$rootId]);
            $store->insert('erasures', [
                'subs' => json_encode($publicIds),
                'submission_ids' => json_encode($subIds),
                'created' => $now,
            ]);
            $store->audit('user', 'Account deleted', count($ids) . ' device(s), ' . count($subIds) . ' submission(s)');
        });

        // Off the walls and out of the day files at once.
        foreach ($this->app->catalog()->channels() as $channel) {
            $this->app->publisher()->publishLive($channel);
            $this->app->publisher()->publishDays($channel);
        }
        $this->scrubPlayed($subs);
        return ['devices' => count($ids), 'submissions' => count($subIds)];
    }

    /** What a device without an account left behind (or the calling device of a deleted one). */
    public function forgetDevice(string $deviceId): void
    {
        if (!Identities::validDeviceId($deviceId)) return;
        $store = $this->app->store();
        $key = $this->app->identities()->deviceKey($deviceId);
        $store->query('DELETE FROM presence WHERE device = ?', [$key]);
        $store->query('DELETE FROM playback_errors WHERE reporter = ?', [$key]);
        foreach (array_column($store->all('SELECT DISTINCT submission_id FROM prayed_along'), 'submission_id') as $sid) {
            $store->query('DELETE FROM prayed_along WHERE submission_id = ? AND who = ?', [(int) $sid, $this->app->identities()->prayKey($deviceId, (int) $sid)]);
        }
    }

    /**
     * Out of the program, under the publish lock. Others' host breaks that
     * name it are drafted afresh when they are drafts (their unit keeps its
     * place), blocked through live.json when committed and not yet over,
     * like its own items; every payload loses its name and its voices.
     *
     * @param list<int> $subIds
     * @param list<array<string,mixed>> $subs
     * @param list<array<string,mixed>> $voices its community voices
     * @param list<string> $tags its authors' marks
     * @param list<array<string,mixed>> $replays its recordings kept for replays (library rows)
     * @return list<int> every host break to forget
     */
    private function withdraw(array $subIds, array $subs, array $voices, array $tags, array $replays): array
    {
        $hostBreaks = $this->app->hostBreaks();
        $timeline = $this->app->timeline();
        $own = $hostBreaks->referencing($subIds);
        $naming = array_values(array_diff($this->naming($subIds, $subs, $voices, $tags), $own));
        $committed = array_values(array_filter($naming, fn($id) => $hostBreaks->rewrite($id) === null));
        $result = $timeline->withdraw($subIds, [...$own, ...$committed]);
        foreach ($replays as $lib) $timeline->withdrawReplays((int) $lib['id']);
        $timeline->scrubVoices($tags, array_map('strval', array_column($voices, 'uid')));
        return array_values(array_unique([...$own, ...$naming, ...$result['breaks']]));
    }

    /**
     * Others' host breaks that name it: one that reacts to its request (in a
     * block of requests the host reacts to the one before; `previous_id`, or
     * by name and place in scripts from before that key), and one written
     * with its community voice that uses it. A voice a script was given but
     * does not use just leaves the break's context.
     *
     * @param list<int> $subIds
     * @param list<array<string,mixed>> $subs
     * @param list<array<string,mixed>> $voices
     * @param list<string> $tags
     * @return list<int>
     */
    private function naming(array $subIds, array $subs, array $voices, array $tags): array
    {
        $store = $this->app->store();
        $out = [];
        if ($subIds) {
            $out = array_column($store->all(
                "SELECT id FROM host_breaks WHERE json_valid(context) AND json_extract(context, '$.previous_id') IN (" . implode(',', $subIds) . ')',
            ), 'id');
            foreach ($subs as $s) {
                if ((string) $s['name'] === '') continue;
                array_push($out, ...array_column($store->all(
                    "SELECT id FROM host_breaks WHERE json_valid(context) AND json_extract(context, '$.previous_id') IS NULL
                     AND json_extract(context, '$.previous_request.name') = ? AND json_extract(context, '$.previous_request.place') = ?",
                    [(string) $s['name'], (string) $s['place']],
                ), 'id'));
            }
        }
        if ($tags) {
            $pairs = [];
            foreach ($voices as $v) $pairs[$v['name'] . "\n" . $v['text']] = true;
            $theirs = fn(array $v, ?string $tag): bool => $tag !== null
                ? in_array($tag, $tags, true)
                : isset($pairs[($v['name'] ?? '') . "\n" . ($v['text'] ?? '')]);
            $texts = array_values(array_unique(array_map('strval', array_column($voices, 'text')))) ?: ['-'];
            foreach ($store->all(
                "SELECT id FROM host_breaks WHERE json_valid(context) AND (
                   EXISTS (SELECT 1 FROM json_each(host_breaks.context, '$.community_by') b WHERE b.value IN (" . implode(',', array_fill(0, count($tags), '?')) . "))
                   OR EXISTS (SELECT 1 FROM json_each(host_breaks.context, '$.community') c WHERE json_extract(c.value, '$.text') IN (" . implode(',', array_fill(0, count($texts), '?')) . ')))',
                [...$tags, ...$texts],
            ) as $r) {
                if ($this->usesVoice((int) $r['id'], $theirs)) $out[] = $r['id'];
            }
        }
        return array_values(array_unique(array_map('intval', $out)));
    }

    /**
     * Whether a break's script uses one of these voices — says its name or
     * quotes it. If not, the voices leave its context (a script still to be
     * written is written without them: they are deleted).
     *
     * @param callable(array<string,mixed>, ?string): bool $theirs
     */
    private function usesVoice(int $breakId, callable $theirs): bool
    {
        $hb = $this->app->hostBreaks()->get($breakId);
        if ($hb === null) return false;
        $tags = array_values((array) ($hb['context']['community_by'] ?? []));
        $script = mb_strtolower(implode("\n", array_map('strval', $hb['texts'])));
        $mine = 0;
        foreach (array_values((array) ($hb['context']['community'] ?? [])) as $i => $v) {
            if (!$theirs((array) $v, isset($tags[$i]) ? (string) $tags[$i] : null)) continue;
            $mine++;
            $name = mb_strtolower(trim((string) ($v['name'] ?? '')));
            $text = mb_strtolower(trim((string) ($v['text'] ?? '')));
            if ($script !== '' && (mb_strlen($name) < 2 || str_contains($script, $name) || ($text !== '' && str_contains($script, mb_substr($text, 0, 24))))) return true;
        }
        if ($mine > 0) $this->app->hostBreaks()->dropVoices($breakId, $theirs);
        return false;
    }

    /**
     * Day files older than the week the tick rewrites list aired recordings
     * by "name, place": those entries lose their title.
     *
     * @param list<array<string,mixed>> $subs
     */
    private function scrubPlayed(array $subs): void
    {
        $aired = [];
        foreach ($subs as $s) {
            if ($s['type'] !== 'song' && $s['type'] !== 'preaching' && $s['aired_at'] !== null) $aired[(int) $s['channel_id']][] = (int) $s['aired_at'];
        }
        foreach ($aired as $channelId => $starts) {
            $channel = $this->app->catalog()->channel($channelId);
            if ($channel === null) continue;
            foreach (glob($this->app->publicPath("program/{$channel['slug']}/days/*.json")) ?: [] as $file) {
                $day = json_decode((string) file_get_contents($file), true);
                if (!is_array($day) || !isset($day['played']) || !is_array($day['played'])) continue;
                $changed = false;
                foreach ($day['played'] as &$entry) {
                    if (($entry['type'] ?? '') === 'contrib' && in_array((int) ($entry['start'] ?? 0), $starts, true) && ($entry['title'] ?? '') !== '') {
                        $entry['title'] = '';
                        $changed = true;
                    }
                }
                unset($entry);
                if ($changed) Files::write($file, Files::json($day));
            }
        }
    }

    /**
     * A host job in the middle of a phase when the account was deleted may
     * write the script it was working on back (it holds the break in
     * memory). Run in the jobs phase, after the jobs: breaks of submissions
     * erased in the last minutes that were written to after the erasure are
     * scrubbed again.
     */
    public function sweep(): int
    {
        $store = $this->app->store();
        $n = 0;
        foreach ($store->all('SELECT subs, submission_ids, created FROM erasures WHERE created >= ?', [$this->app->clock->now() - self::SWEEP_SECONDS]) as $e) {
            $subIds = array_values(array_map('intval', (array) json_decode((string) $e['submission_ids'], true)));
            $tags = array_map(fn($p) => Presence::voiceTag((string) $p), (array) json_decode((string) $e['subs'], true));
            $written = [];
            if ($subIds) {
                $written = [...$this->app->hostBreaks()->referencing($subIds), ...array_column($store->all(
                    "SELECT id FROM host_breaks WHERE json_valid(context) AND json_extract(context, '$.previous_id') IN (" . implode(',', $subIds) . ')',
                ), 'id')];
            }
            if ($tags) {
                $theirs = fn(array $v, ?string $tag): bool => $tag !== null && in_array($tag, $tags, true);
                foreach ($store->all(
                    "SELECT id FROM host_breaks WHERE json_valid(context) AND EXISTS (
                       SELECT 1 FROM json_each(host_breaks.context, '$.community_by') b WHERE b.value IN (" . implode(',', array_fill(0, count($tags), '?')) . '))',
                    $tags,
                ) as $r) {
                    if ($this->usesVoice((int) $r['id'], $theirs)) $written[] = $r['id'];
                }
            }
            // Only breaks that existed then: a new submission may have been
            // given a deleted one's id since (SQLite reuses row ids).
            $stale = $written ? array_column($store->all(
                'SELECT id FROM host_breaks WHERE created <= ? AND updated >= ? AND id IN (' . implode(',', array_map('intval', $written)) . ')',
                [(int) $e['created'], (int) $e['created']],
            ), 'id') : [];
            $n += $this->app->hostBreaks()->forget(array_map('intval', $stale), $subIds);
        }
        return $n;
    }

    /** @return list<string> public ids of accounts deleted since $since (unix seconds) */
    public function erasedSince(int $since): array
    {
        $out = [];
        foreach ($this->app->store()->all('SELECT subs FROM erasures WHERE created >= ?', [$since]) as $e) {
            foreach ((array) json_decode((string) $e['subs'], true) as $p) $out[] = (string) $p;
        }
        return array_values(array_unique($out));
    }
}

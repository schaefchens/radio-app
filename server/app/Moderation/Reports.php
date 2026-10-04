<?php
declare(strict_types=1);

namespace Arche\Moderation;

use Arche\ApiError;
use Arche\App;
use Arche\Submission\Submissions;

/**
 * Listeners report what others wrote (the app stores require it for every
 * kind of user content): a request on the prayer wall, or a community voice.
 * Chat messages are reported through the room (realtime/src/hub.ts). Every
 * report lands in /mod, where moderators decide within a day (STORE.md).
 *
 * A wall request reported by WALL_REPORTS_HIDE different listeners is taken
 * down at once (submissions.hidden = 2) until a moderator decides: a wall
 * anyone can read must not wait a day for that decision. Device ids cost
 * nothing, so only listeners known for a day count, and a request a
 * moderator kept stays up whatever is reported after.
 */
final class Reports
{
    /** How long a reporter must have been known to take a request down. */
    private const REPORTER_AGE = 86400;

    public function __construct(private App $app) {}

    /** @param array<string,mixed> $identity @return array{ok:bool,hidden:bool} */
    public function reportWall(array $identity, string $wallId, string $reason): array
    {
        $subs = $this->app->submissions();
        // The wall's ids are 'p' + the request's public id (Submissions::wall).
        $sub = $subs->byPublicId(str_starts_with($wallId, 'p') ? substr($wallId, 1) : $wallId);
        if ($sub === null || !Submissions::onWall($sub) || !in_array($sub['status'], ['approved', 'scheduled', 'aired'], true)) {
            throw new ApiError(404, 'not_found');
        }
        $store = $this->app->store();
        $id = (int) $sub['id'];
        // Reporting again is no new report, and costs nothing.
        if ($store->value('SELECT 1 FROM wall_reports WHERE submission_id = ? AND reporter_id = ?', [$id, (int) $identity['id']]) !== null) {
            return ['ok' => true, 'hidden' => false];
        }
        $this->limit($identity);
        $store->query(
            'INSERT OR IGNORE INTO wall_reports(submission_id, reporter_id, reason, status, created) VALUES(?, ?, ?, ?, ?)',
            [$id, (int) $identity['id'], mb_substr(trim($reason), 0, 60), 'open', $this->app->clock->now()],
        );
        $threshold = $this->app->config->int('WALL_REPORTS_HIDE', 3);
        $kept = $store->value("SELECT 1 FROM wall_reports WHERE submission_id = ? AND status = 'dismissed'", [$id]) !== null;
        $open = (int) $store->value(
            "SELECT COUNT(*) FROM wall_reports r JOIN identities i ON i.id = r.reporter_id WHERE r.submission_id = ? AND r.status = 'open' AND i.created <= ?",
            [$id, $this->app->clock->now() - self::REPORTER_AGE],
        );
        $hidden = $threshold > 0 && !$kept && $open >= $threshold && $subs->hideByReports($id);
        if ($hidden) {
            $channel = $this->app->catalog()->channel((int) $sub['channel_id']);
            if ($channel !== null) $this->app->publisher()->publishLive($channel);
            $store->audit('reports', 'Taken off the prayer wall by reports', (string) $sub['public_id'] . ", $open reports");
        }
        return ['ok' => true, 'hidden' => $hidden];
    }

    /**
     * A community voice (an approved chat highlight) reported: into the chat
     * reports moderators already work through, labelled as a voice there.
     *
     * @param array<string,mixed> $identity
     */
    public function reportVoice(array $identity, string $uid, string $reason): void
    {
        $store = $this->app->store();
        $h = $store->one("SELECT * FROM highlights WHERE uid = ? AND status = 'approved'", [$uid]) ?? throw new ApiError(404, 'not_found');
        $reporter = (string) $identity['public_id'];
        if ($store->value('SELECT 1 FROM chat_reports WHERE msg = ? AND reporter = ?', [$uid, $reporter]) !== null) return;
        $this->limit($identity);
        $now = $this->app->clock->now();
        $store->query(
            'INSERT OR IGNORE INTO chat_reports(msg, text, author, reporter, reason, status, at, created) VALUES(?, ?, ?, ?, ?, ?, ?, ?)',
            [$uid, (string) $h['text'], (string) $h['sub'], $reporter, mb_substr(trim($reason), 0, 60), 'open', (int) $h['at'], $now],
        );
    }

    /** @param array<string,mixed> $identity */
    private function limit(array $identity): void
    {
        $rl = $this->app->rateLimit();
        $me = 'report:' . (int) $identity['id'];
        if (!$rl->hit("$me:h", 10, 3600) || !$rl->hit("$me:d", 40, 86400)
            || !$rl->hit('report:' . $rl->ipKey(), $this->app->config->int('REPORTS_PER_IP_HOUR', 60), 3600)) {
            throw new ApiError(429, 'rate_limited');
        }
    }
}

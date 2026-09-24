<?php
declare(strict_types=1);

namespace Arche\Api;

use Arche\ApiError;
use Arche\Http\Context;
use Arche\Http\Response;
use Arche\Identity\Identities;
use Arche\Program\Timing;

/**
 * /api/mod/*: the Plan layer and the content pool, for moderators; users and
 * channels for admins. Every write is audited under the moderator's public id.
 */
final class ModApi
{
    /** @var array<string,mixed> */
    private array $me;

    public function __construct(private Context $c) {}

    private function mod(): void
    {
        $this->me = $this->c->requireRole('moderator');
    }

    private function admin(): void
    {
        $this->me = $this->c->requireRole('admin');
    }

    private function actor(): string
    {
        return 'mod:' . $this->me['public_id'];
    }

    /** @param array<string,string> $a */
    private function id(array $a, string $k = 'id'): int
    {
        $v = (int) ($a[$k] ?? 0);
        if ($v <= 0) throw new ApiError(404, 'not_found');
        return $v;
    }

    // --- overview & status ----------------------------------------------------------

    public function overview(): array
    {
        $this->mod();
        $app = $this->c->app;
        $channels = [];
        foreach ($app->catalog()->channels(false) as $ch) {
            $channels[] = $ch + ['programs' => $app->catalog()->programs((int) $ch['id'])];
        }
        return [
            'me' => Identities::publicView($this->me),
            'channels' => $channels,
            'library' => ['songs' => $app->library()->count('song'), 'jingles' => $app->library()->count('jingle')],
            'review' => (int) $app->store()->value("SELECT COUNT(*) FROM submissions WHERE status = 'review'"),
            'reports' => (int) $app->store()->value("SELECT COUNT(*) FROM chat_reports WHERE status = 'open'"),
            'highlights' => (int) $app->store()->value("SELECT COUNT(*) FROM highlights WHERE status = 'candidate'"),
            'youtube' => $app->youtube()->configured(),
        ];
    }

    public function status(): array
    {
        $this->mod();
        $app = $this->c->app;
        $store = $app->store();
        $now = $app->clock->nowMs();
        $channels = [];
        foreach ($app->catalog()->channels() as $ch) {
            $cid = (int) $ch['id'];
            $channels[] = [
                'slug' => $ch['slug'],
                'frontier' => $app->committer()->frontier($cid),
                'published' => $store->get("published:$cid"),
                'aheadSec' => ($f = $app->committer()->frontier($cid)) !== null ? intdiv($f - $now, 1000) : null,
                'drafts' => (int) $store->value("SELECT COUNT(*) FROM timeline_items WHERE channel_id = ? AND state = 'draft'", [$cid]),
                'listeners' => $app->presence()->listeners((string) $ch['slug']),
            ];
        }
        return [
            'now' => $now,
            'lastTick' => $store->get('last_tick'),
            'channels' => $channels,
            'jobs' => $app->jobs()->counts(),
            'hostBreaks' => $store->all("SELECT state, source, COUNT(*) AS n FROM host_breaks WHERE created >= ? GROUP BY state, source", [intdiv($now, 1000) - 86400]),
            'usage' => $app->usage()->recent(7),
            'spentTodayUsd' => round($app->usage()->spentTodayMicros() / 1_000_000, 3),
            'budgetUsd' => $app->config->float('AI_DAILY_BUDGET_USD', 5.0),
            'ai' => $this->aiSetup(),
            'realtime' => $app->nodes()->status(),
            'cdn' => $app->cdn()->status(),
            'audit' => $store->all('SELECT time, actor, event, detail FROM audit ORDER BY id DESC LIMIT 60'),
        ];
    }

    /** @return array{text:string,hostModel:string,moderationModel:string,voice:string} which models are at work */
    private function aiSetup(): array
    {
        $c = $this->c->app->config;
        $provider = $c->textProvider();
        [$host, $moderation] = match ($provider) {
            'anthropic' => [$c->get('HOST_MODEL'), $c->get('MODERATION_MODEL')],
            'openai' => [$c->get('OPENAI_HOST_MODEL'), $c->get('OPENAI_MODERATION_MODEL')],
            default => ['', ''],
        };
        return ['text' => $provider, 'hostModel' => $host, 'moderationModel' => $moderation, 'voice' => $this->c->app->voice()->provider()];
    }

    // --- library -------------------------------------------------------------------------

    public function library(): array
    {
        $this->mod();
        $q = $this->c->req->query;
        return ['items' => $this->c->app->library()->search((string) ($q['q'] ?? ''), (string) ($q['kind'] ?? ''), (int) ($q['limit'] ?? 200), (int) ($q['offset'] ?? 0))];
    }

    public function libraryLookup(): array
    {
        $this->mod();
        return ['video' => $this->c->app->library()->lookup((string) $this->c->req->input('url', ''))];
    }

    public function libraryAdd(): array
    {
        $this->mod();
        $in = $this->c->req->json();
        return ['item' => $this->c->app->library()->addSong((string) ($in['url'] ?? ''), $in, $this->actor())];
    }

    /** @param array<string,string> $a */
    public function libraryUpdate(array $a): array
    {
        $this->mod();
        return ['item' => $this->c->app->library()->update($this->id($a), $this->c->req->json(), $this->actor())];
    }

    /**
     * Pull from air: disable the item and mark every committed airing of it
     * that has not finished yet as blocked. Published minute files cannot
     * change, so clients learn it from live.json (≤ one tick) and skip it.
     *
     * @param array<string,string> $a
     */
    public function libraryPull(array $a): array
    {
        $this->mod();
        $app = $this->c->app;
        $id = $this->id($a);
        $app->library()->update($id, ['active' => false], $this->actor());
        $n = $app->store()->query(
            "UPDATE timeline_items SET blocked = 1 WHERE library_id = ? AND state = 'committed' AND start_ms + dur_ms > ?",
            [$id, $app->clock->nowMs()],
        )->rowCount();
        $app->timeline()->dropDraftsOf($id);
        foreach ($app->catalog()->channels() as $ch) $app->publisher()->publishLive($ch);
        $app->store()->audit($this->actor(), 'Pulled from air', "library $id, $n airing(s)");
        return ['blocked' => $n];
    }

    public function jingleUpload(): array
    {
        $this->mod();
        $file = $this->c->req->file('audio') ?? throw new ApiError(422, 'missing_audio');
        return ['item' => $this->c->app->library()->addJingle($file, (string) ($this->c->req->post['title'] ?? 'Jingle'), $this->actor())];
    }

    public function jingleTts(): array
    {
        $this->mod();
        $in = $this->c->req->json();
        $voice = (string) ($in['voice'] ?? 'coral');
        if (!preg_match('/^[a-z]{2,16}$/', $voice)) throw new ApiError(422, 'bad_voice');
        return ['item' => $this->c->app->library()->addJingleTts((string) ($in['text'] ?? ''), $voice, $this->actor())];
    }

    // --- channels & programs --------------------------------------------------------------------

    public function channels(): array
    {
        $this->mod();
        return ['channels' => $this->c->app->catalog()->channels(false)];
    }

    public function channelCreate(): array
    {
        $this->admin();
        $ch = $this->c->app->catalog()->saveChannel(null, $this->c->req->json(), $this->actor());
        // A new channel needs a program and a plan to be playable at all.
        $catalog = $this->c->app->catalog();
        $program = $catalog->saveProgram(null, (int) $ch['id'], [
            'slug' => 'live', 'title_en' => $ch['name_en'], 'title_de' => $ch['name_de'], 'allowed' => ['song'], 'themes' => ['worship'],
        ], $this->actor());
        $plan = $catalog->saveDayPlan(null, (int) $ch['id'], 'Standard', [['start_min' => 0, 'end_min' => 1440, 'program_id' => $program['id']]], $this->actor());
        $catalog->setWeekPlan((int) $ch['id'], array_fill_keys(range(1, 7), $plan), $this->actor());
        return ['channel' => $catalog->saveChannel((int) $ch['id'], ['default_day_plan_id' => $plan, 'fallback_program_id' => $program['id']], $this->actor())];
    }

    /** @param array<string,string> $a */
    public function channelUpdate(array $a): array
    {
        $this->admin();
        return ['channel' => $this->c->app->catalog()->saveChannel($this->id($a), $this->c->req->json(), $this->actor())];
    }

    /** @param array<string,string> $a */
    public function channelAvatar(array $a): array
    {
        $this->admin();
        $file = $this->c->req->file('image') ?? throw new ApiError(422, 'missing_image');
        $url = $this->c->app->media()->storeImage($file, 'stage', 256, 256);
        return ['channel' => $this->c->app->catalog()->saveChannel($this->id($a), ['host_avatar' => $url], $this->actor())];
    }

    /** @param array<string,string> $a */
    public function programs(array $a): array
    {
        $this->mod();
        return ['programs' => $this->c->app->catalog()->programs($this->c->channelById($this->id($a))['id'])];
    }

    /** @param array<string,string> $a */
    public function programCreate(array $a): array
    {
        $this->mod();
        $cid = (int) $this->c->channelById($this->id($a))['id'];
        return ['program' => $this->c->app->catalog()->saveProgram(null, $cid, $this->c->req->json(), $this->actor())];
    }

    /** @param array<string,string> $a */
    public function programUpdate(array $a): array
    {
        $this->mod();
        $program = $this->c->app->catalog()->program($this->id($a)) ?? throw new ApiError(404, 'not_found');
        return ['program' => $this->c->app->catalog()->saveProgram((int) $program['id'], (int) $program['channel_id'], $this->c->req->json(), $this->actor())];
    }

    /** @param array<string,string> $a */
    public function programDelete(array $a): array
    {
        $this->mod();
        $this->c->app->catalog()->deleteProgram($this->id($a), $this->actor());
        return ['ok' => true];
    }

    /** @param array<string,string> $a */
    public function programImage(array $a): array
    {
        $this->mod();
        $program = $this->c->app->catalog()->program($this->id($a)) ?? throw new ApiError(404, 'not_found');
        $file = $this->c->req->file('image') ?? throw new ApiError(422, 'missing_image');
        $url = $this->c->app->media()->storeImage($file, 'stage', 1280, 720);
        return ['program' => $this->c->app->catalog()->saveProgram((int) $program['id'], (int) $program['channel_id'], ['image' => $url], $this->actor())];
    }

    // --- plans ------------------------------------------------------------------------------------

    /** @param array<string,string> $a */
    public function plans(array $a): array
    {
        $this->mod();
        $ch = $this->c->channelById($this->id($a));
        $catalog = $this->c->app->catalog();
        return [
            'channel' => $ch,
            'programs' => $catalog->programs((int) $ch['id']),
            'dayPlans' => $catalog->dayPlans((int) $ch['id']),
            'week' => (object) $catalog->weekPlan((int) $ch['id']),
            'specialDays' => $catalog->specialDays((int) $ch['id']),
        ];
    }

    /** The resolved blocks of a date, so the editor can show what a plan change does. @param array<string,string> $a */
    public function planPreview(array $a): array
    {
        $this->mod();
        $ch = $this->c->channelById($this->id($a));
        $date = (string) ($this->c->req->query['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = $this->c->app->resolver()->localDate($ch, $this->c->app->clock->nowMs());
        return ['date' => $date, 'dayPlanId' => $this->c->app->resolver()->dayPlanIdFor($ch, $date), 'day' => $this->c->app->publisher()->day($ch, $date)];
    }

    /** @param array<string,string> $a */
    public function dayPlanCreate(array $a): array
    {
        $this->mod();
        $ch = $this->c->channelById($this->id($a));
        $in = $this->c->req->json();
        $id = $this->c->app->catalog()->saveDayPlan(null, (int) $ch['id'], (string) ($in['name'] ?? ''), (array) ($in['blocks'] ?? []), $this->actor());
        return ['id' => $id];
    }

    /** @param array<string,string> $a */
    public function dayPlanUpdate(array $a): array
    {
        $this->mod();
        $id = $this->id($a);
        $plan = $this->c->app->store()->one('SELECT * FROM day_plans WHERE id = ?', [$id]) ?? throw new ApiError(404, 'not_found');
        $in = $this->c->req->json();
        $this->c->app->catalog()->saveDayPlan($id, (int) $plan['channel_id'], (string) ($in['name'] ?? $plan['name']), (array) ($in['blocks'] ?? []), $this->actor());
        return ['id' => $id];
    }

    /** @param array<string,string> $a */
    public function dayPlanDelete(array $a): array
    {
        $this->mod();
        $this->c->app->catalog()->deleteDayPlan($this->id($a), $this->actor());
        return ['ok' => true];
    }

    /** @param array<string,string> $a */
    public function weekPlan(array $a): array
    {
        $this->mod();
        $ch = $this->c->channelById($this->id($a));
        $this->c->app->catalog()->setWeekPlan((int) $ch['id'], (array) $this->c->req->input('week', []), $this->actor());
        return ['week' => (object) $this->c->app->catalog()->weekPlan((int) $ch['id'])];
    }

    /** @param array<string,string> $a */
    public function specialDayCreate(array $a): array
    {
        $this->mod();
        $ch = $this->c->channelById($this->id($a));
        return ['id' => $this->c->app->catalog()->addSpecialDay((int) $ch['id'], $this->c->req->json(), $this->actor())];
    }

    /** @param array<string,string> $a */
    public function specialDayDelete(array $a): array
    {
        $this->mod();
        $this->c->app->catalog()->deleteSpecialDay($this->id($a), $this->id($a, 'sid'), $this->actor());
        return ['ok' => true];
    }

    // --- review queue ----------------------------------------------------------------------------

    /**
     * Submissions for the moderators, with the full verdict: ?status=review
     * (the automatic check was unsure; the default), rejected (newest first,
     * with whether the rejection can still be overruled) or all.
     */
    public function review(): array
    {
        $this->mod();
        $subs = $this->c->app->submissions();
        [$where, $order] = match ((string) ($this->c->req->query['status'] ?? 'review')) {
            'rejected' => ["s.status = 'rejected'", 's.updated DESC'],
            'all' => ['1 = 1', 's.created DESC'],
            default => ["s.status = 'review'", 's.created'],
        };
        $rows = $this->c->app->store()->all(
            "SELECT s.*, p.title_en AS program_title FROM submissions s LEFT JOIN programs p ON p.id = s.program_id
             WHERE $where ORDER BY $order LIMIT 100",
        );
        $out = [];
        foreach ($rows as $s) {
            $meta = json_decode((string) $s['meta'], true) ?: [];
            $out[] = [
                'id' => $s['public_id'], 'type' => $s['type'], 'mode' => $s['mode'], 'program' => $s['program_title'],
                'name' => $s['name'], 'place' => $s['place'], 'message' => $s['message'], 'text' => $s['text'],
                'transcript' => $s['transcript'], 'yt' => $s['yt_id'], 'video' => $meta['youtube'] ?? null,
                'verdict' => json_decode((string) $s['verdict'], true), 'created' => (int) $s['created'] * 1000,
                'status' => $s['status'], 'reason' => $s['reason'], 'updated' => (int) $s['updated'] * 1000,
                'blocker' => $s['status'] === 'rejected' ? $subs->overruleBlocker($s) : null,
            ];
        }
        return ['items' => $out];
    }

    /**
     * The recording of a submission in review, so a moderator can listen
     * before deciding. It stays in the private uploads dir until approval
     * publishes it; this is the only way to hear it before that.
     *
     * @param array<string,string> $a
     */
    public function reviewAudio(array $a): Response
    {
        $this->mod();
        $sub = $this->c->app->submissions()->byPublicId((string) ($a['id'] ?? '')) ?? throw new ApiError(404, 'not_found');
        $path = $sub['status'] === 'review' && $sub['upload']
            ? $this->c->app->config->dataDir . '/uploads/' . basename((string) $sub['upload'])
            : null;
        if ($path === null || !is_file($path)) throw new ApiError(404, 'not_found');
        return Response::file($path, 'audio/mpeg');
    }

    /** @param array<string,string> $a */
    public function reviewDecide(array $a): array
    {
        $this->mod();
        $subs = $this->c->app->submissions();
        $sub = $subs->byPublicId((string) ($a['id'] ?? '')) ?? throw new ApiError(404, 'not_found');
        $verdict = json_decode((string) $sub['verdict'], true) ?: [];
        // Overruling a rejection: approve only, and only while it can still air.
        if ($sub['status'] === 'rejected' && $this->c->req->input('decision') === 'approve') {
            $blocker = $subs->overruleBlocker($sub);
            if ($blocker !== null) throw new ApiError(409, $blocker);
            $subs->approve((int) $sub['id'], $verdict + ['human' => true, 'overruled' => true], $this->actor(),
                overrule: true, keepMessage: $this->c->req->input('keepMessage', true) !== false);
            return ['submission' => $subs->publicView($subs->get((int) $sub['id']) ?? $sub)];
        }
        if ($sub['status'] !== 'review') throw new ApiError(409, 'not_in_review');
        if ($this->c->req->input('decision') === 'approve') {
            $subs->approve((int) $sub['id'], $verdict + ['human' => true], $this->actor(),
                keepMessage: $this->c->req->input('keepMessage', true) !== false);
        } else {
            $subs->reject((int) $sub['id'], (string) $this->c->req->input('reason', 'not_accepted'), $verdict + ['human' => true], $this->actor());
        }
        return ['submission' => $subs->publicView($subs->get((int) $sub['id']) ?? $sub)];
    }

    // --- users (admin) -----------------------------------------------------------------------------

    public function users(): array
    {
        $this->admin();
        $q = trim((string) ($this->c->req->query['q'] ?? ''));
        // Devices that logged in with the same passphrase are aliases of one
        // account (canonical_id): listed once, with how many devices use it.
        $rows = $this->c->app->store()->all(
            "SELECT i.*, (SELECT COUNT(*) FROM identities a WHERE a.canonical_id = i.id) AS aliases
             FROM identities i WHERE i.canonical_id IS NULL AND (? = '' OR i.public_id = ? OR i.display_name LIKE ?)
             ORDER BY i.role != 'listener' DESC, i.last_seen DESC LIMIT 100",
            [$q, $q, '%' . $q . '%'],
        );
        return ['users' => array_map(fn($r) => Identities::publicView($r) + [
            'lastSeen' => (int) $r['last_seen'] * 1000,
            'devices' => 1 + (int) $r['aliases'],
        ], $rows)];
    }

    /** @param array<string,string> $a */
    public function userUpdate(array $a): array
    {
        $this->admin();
        $publicId = (string) ($a['id'] ?? '');
        $ids = $this->c->app->identities();
        $in = $this->c->req->json();
        if ($publicId === $this->me['public_id'] && isset($in['role']) && $in['role'] !== 'admin') throw new ApiError(409, 'cannot_demote_self');
        $out = null;
        if (isset($in['role'])) $out = $ids->setRole($publicId, (string) $in['role'], $this->actor());
        if (array_key_exists('banned', $in)) $out = $ids->setBanned($publicId, (bool) $in['banned'], $this->actor());
        return ['user' => $out ? Identities::publicView($out) : null];
    }

    // --- chat moderation -------------------------------------------------------------------------------

    public function reports(): array
    {
        $this->mod();
        return ['reports' => $this->c->app->store()->all("SELECT * FROM chat_reports WHERE status = 'open' ORDER BY created DESC LIMIT 200")];
    }

    /** @param array<string,string> $a */
    public function reportDecide(array $a): array
    {
        $this->mod();
        $app = $this->c->app;
        $store = $app->store();
        $report = $store->one('SELECT * FROM chat_reports WHERE id = ?', [$this->id($a)]) ?? throw new ApiError(404, 'not_found');
        $action = (string) $this->c->req->input('action', 'dismiss');
        if ($action === 'remove' || $action === 'ban') {
            $store->query('INSERT OR IGNORE INTO removed_messages(msg, time) VALUES(?, ?)', [$report['msg'], $app->clock->now()]);
            $store->query("UPDATE highlights SET status = 'rejected' WHERE uid = ?", [$report['msg']]);
        }
        if ($action === 'ban' && $report['author'] !== '') $app->identities()->setBanned((string) $report['author'], true, $this->actor());
        $store->query("UPDATE chat_reports SET status = ? WHERE msg = ?", [$action === 'dismiss' ? 'dismissed' : 'actioned', $report['msg']]);
        $store->audit($this->actor(), 'Chat report ' . $action, (string) $report['msg']);
        return ['ok' => true];
    }

    public function highlights(): array
    {
        $this->mod();
        return ['highlights' => $this->c->app->store()->all('SELECT * FROM highlights ORDER BY created DESC LIMIT 200')];
    }

    /** @param array<string,string> $a */
    public function highlightDecide(array $a): array
    {
        $this->mod();
        $status = $this->c->req->input('status') === 'approved' ? 'approved' : 'rejected';
        $this->c->app->store()->update('highlights', ['status' => $status, 'updated' => $this->c->app->clock->now()], 'uid = ?', [(string) ($a['id'] ?? '')]);
        return ['ok' => true];
    }

    public function blocklist(): array
    {
        $this->mod();
        return ['words' => (array) ($this->c->app->store()->get('chat_blocklist') ?? [])];
    }

    public function blocklistSave(): array
    {
        $this->mod();
        $words = [];
        foreach ((array) $this->c->req->input('words', []) as $w) {
            $w = mb_strtolower(trim((string) $w));
            if ($w !== '' && mb_strlen($w) <= 40) $words[$w] = true;
        }
        $list = array_slice(array_keys($words), 0, 500);
        $this->c->app->store()->set('chat_blocklist', $list);
        $this->c->app->store()->audit($this->actor(), 'Chat blocklist saved', count($list) . ' entries');
        return ['words' => $list];
    }

    /** Minute arithmetic helper shared with tests. */
    public static function minute(int $ms): int
    {
        return Timing::floorMinute($ms);
    }
}

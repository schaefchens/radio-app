<?php
declare(strict_types=1);

namespace Arche\Api;

use Arche\ApiError;
use Arche\Ai\VoiceError;
use Arche\Audio\Mp3;
use Arche\Host\Hosts;
use Arche\Http\Context;
use Arche\Http\Response;
use Arche\Identity\Identities;
use Arche\Program\Timing;
use Arche\Support\BudgetExceeded;

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
            $channels[] = $this->withHosts('channel', $ch) + ['programs' => array_map(fn($p) => $this->withHosts('program', $p), $app->catalog()->programs((int) $ch['id']))];
        }
        return [
            'me' => Identities::publicView($this->me),
            'channels' => $channels,
            'library' => [
                'songs' => $app->library()->count('song'), 'preachings' => $app->library()->count('preaching'),
                'testimonies' => $app->library()->count('testimony'), 'missions' => $app->library()->count('mission'),
                'films' => $app->library()->count('film'), 'jingles' => $app->library()->count('jingle'),
            ],
            'review' => (int) $app->store()->value("SELECT COUNT(*) FROM submissions WHERE status = 'review'"),
            'reports' => (int) $app->store()->value("SELECT COUNT(*) FROM chat_reports WHERE status = 'open'"),
            // Wall requests listeners reported (Moderation\Reports): decided in Review → Prayer wall.
            'wallReports' => (int) $app->store()->value("SELECT COUNT(DISTINCT submission_id) FROM wall_reports WHERE status = 'open'"),
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

    /**
     * Which models are at work: the text model, and the hosts' voices
     * (`voice`, the main channel's first host's provider, for /mod tabs
     * opened before hosts existed).
     *
     * @return array<string,mixed>
     */
    private function aiSetup(): array
    {
        $app = $this->c->app;
        $c = $app->config;
        $provider = $c->textProvider();
        [$host, $moderation] = match ($provider) {
            'anthropic' => [$c->get('HOST_MODEL'), $c->get('MODERATION_MODEL')],
            'openai' => [$c->get('OPENAI_HOST_MODEL'), $c->get('OPENAI_MODERATION_MODEL')],
            default => ['', ''],
        };
        $voice = $c->stubAi() ? 'stub' : (string) $app->hosts()->channelHost($app->catalog()->mainChannel())['voice'];
        return ['text' => $provider, 'hostModel' => $host, 'moderationModel' => $moderation, 'voice' => $voice, 'voices' => $app->hosts()->summary()];
    }

    // --- library -------------------------------------------------------------------------

    public function library(): array
    {
        $this->mod();
        $q = $this->c->req->query;
        return ['items' => $this->c->app->library()->search((string) ($q['q'] ?? ''), (string) ($q['kind'] ?? ''), (int) ($q['limit'] ?? 200), (int) ($q['offset'] ?? 0), (int) ($q['group'] ?? 0))];
    }

    public function libraryLookup(): array
    {
        $this->mod();
        return ['video' => $this->c->app->library()->lookup((string) $this->c->req->input('url', ''))];
    }

    /** A song, or with `kind` a video of a video program (Library::VIDEO_KINDS), by its YouTube link. */
    public function libraryAdd(): array
    {
        $this->mod();
        $in = $this->c->req->json();
        // An unknown kind is refused (bad_kind), never quietly added as a song.
        $kind = (string) ($in['kind'] ?? 'song');
        return ['item' => $this->c->app->library()->addVideo($kind, (string) ($in['url'] ?? ''), $in, $this->actor())];
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

    // --- groups (Library\Groups) ---------------------------------------------------------------

    public function groups(): array
    {
        $this->mod();
        return ['groups' => $this->c->app->groups()->all()];
    }

    public function groupCreate(): array
    {
        $this->mod();
        return ['group' => $this->c->app->groups()->save(null, $this->c->req->json(), $this->actor())];
    }

    /** @param array<string,string> $a */
    public function groupUpdate(array $a): array
    {
        $this->mod();
        return ['group' => $this->c->app->groups()->save($this->id($a), $this->c->req->json(), $this->actor())];
    }

    /** @param array<string,string> $a */
    public function groupDelete(array $a): array
    {
        $this->mod();
        $this->c->app->groups()->delete($this->id($a), $this->actor());
        return ['ok' => true];
    }

    /** A YouTube channel from a link to one of its videos or its /channel/ address. */
    public function groupChannel(): array
    {
        $this->mod();
        return ['channel' => $this->c->app->groups()->resolveChannel((string) $this->c->req->input('url', ''))];
    }

    public function jingleUpload(): array
    {
        $this->mod();
        $file = $this->c->req->file('audio') ?? throw new ApiError(422, 'missing_audio');
        return ['item' => $this->c->app->library()->addJingle($file, (string) ($this->c->req->post['title'] ?? 'Jingle'), $this->actor())];
    }

    public function bedUpload(): array
    {
        $this->mod();
        $file = $this->c->req->file('audio') ?? throw new ApiError(422, 'missing_audio');
        return ['item' => $this->c->app->library()->addBed($file, (string) ($this->c->req->post['title'] ?? ''), $this->actor())];
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
        return ['channels' => array_map(fn($ch) => $this->withHosts('channel', $ch), $this->c->app->catalog()->channels(false))];
    }

    /**
     * A channel or program row with its lineup (`hosts`: [{id, role}]).
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function withHosts(string $owner, array $row): array
    {
        $row += ['hosts' => $this->c->app->hosts()->lineup($owner, (int) $row['id'])];
        // A program's: whether its host speaks from recorded lines (Host\Lines).
        if ($owner === 'program') $row += ['lines' => $this->c->app->lines()->programMode((int) $row['id'])];
        return $row;
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
        return ['channel' => $this->withHosts('channel', $catalog->saveChannel((int) $ch['id'], ['default_day_plan_id' => $plan, 'fallback_program_id' => $program['id']], $this->actor()))];
    }

    /** @param array<string,string> $a */
    public function channelUpdate(array $a): array
    {
        $this->admin();
        return ['channel' => $this->withHosts('channel', $this->c->app->catalog()->saveChannel($this->id($a), $this->c->req->json(), $this->actor()))];
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
        return ['programs' => array_map(fn($p) => $this->withHosts('program', $p), $this->c->app->catalog()->programs($this->c->channelById($this->id($a))['id']))];
    }

    /** @param array<string,string> $a */
    public function programCreate(array $a): array
    {
        $this->mod();
        $cid = (int) $this->c->channelById($this->id($a))['id'];
        return ['program' => $this->withHosts('program', $this->c->app->catalog()->saveProgram(null, $cid, $this->c->req->json(), $this->actor()))];
    }

    /** @param array<string,string> $a */
    public function programUpdate(array $a): array
    {
        $this->mod();
        $program = $this->c->app->catalog()->program($this->id($a)) ?? throw new ApiError(404, 'not_found');
        return ['program' => $this->withHosts('program', $this->c->app->catalog()->saveProgram((int) $program['id'], (int) $program['channel_id'], $this->c->req->json(), $this->actor()))];
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
        return ['program' => $this->withHosts('program', $this->c->app->catalog()->saveProgram((int) $program['id'], (int) $program['channel_id'], ['image' => $url], $this->actor()))];
    }

    /** @param array<string,string> $a */
    public function openingPrayers(array $a): array
    {
        $this->mod();
        return ['prayers' => $this->c->app->openingPrayers()->list($this->id($a))];
    }

    /**
     * A prepared opening prayer: a recording (multipart `audio` + `name`) or a
     * text (JSON `name`, `text_en`, `text_de`).
     *
     * @param array<string,string> $a
     */
    public function openingPrayerAdd(array $a): array
    {
        $this->mod();
        $prayers = $this->c->app->openingPrayers();
        $program = $this->id($a);
        $file = $this->c->req->file('audio');
        if ($file !== null) return ['prayer' => $prayers->addAudio($program, (string) ($this->c->req->post['name'] ?? ''), $file, $this->actor())];
        $in = $this->c->req->json();
        return ['prayer' => $prayers->addText($program, (string) ($in['name'] ?? ''), (string) ($in['text_en'] ?? ''), (string) ($in['text_de'] ?? ''), $this->actor())];
    }

    /** @param array<string,string> $a */
    public function openingPrayerDelete(array $a): array
    {
        $this->mod();
        $this->c->app->openingPrayers()->delete($this->id($a), $this->actor());
        return ['ok' => true];
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
     * with whether the rejection can still be overruled), wall (the typed
     * prayers the prayer wall can show, newest first — the ones taken down
     * too, so they can be put back) or all. `hidden` and `consentAir` say
     * whether an item is off the wall and whether it may be on it at all.
     */
    public function review(): array
    {
        $this->mod();
        $subs = $this->c->app->submissions();
        $prayerHour = "(CASE WHEN json_valid(p.settings) THEN json_extract(p.settings, '$.format') END) = 'prayer'";
        [$where, $order] = match ((string) ($this->c->req->query['status'] ?? 'review')) {
            'rejected' => ["s.status = 'rejected'", 's.updated DESC'],
            // Reported ones first: they wait for a decision.
            // A prayer hour's requests are on its wall, ticked or not.
            'wall' => ["s.type = 'prayer' AND s.mode = 'text' AND (s.consent_air = 1 OR $prayerHour) AND s.status IN ('approved', 'scheduled', 'aired')",
                'reports > 0 DESC, s.created DESC, s.id DESC'],
            'all' => ['1 = 1', 's.created DESC'],
            default => ["s.status = 'review'", 's.created'],
        };
        $rows = $this->c->app->store()->all(
            "SELECT s.*, p.title_en AS program_title, $prayerHour AS of_prayer_hour,
               (SELECT COUNT(*) FROM wall_reports r WHERE r.submission_id = s.id AND r.status = 'open') AS reports
             FROM submissions s LEFT JOIN programs p ON p.id = s.program_id
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
                // Refused because its creator asked not to be here: whose request it was.
                'group' => ($gid = (int) ((json_decode((string) $s['verdict'], true) ?: [])['group_blocked'] ?? 0)) > 0 ? ($this->c->app->groups()->get($gid)['name'] ?? null) : null,
                'hidden' => (bool) $s['hidden'], 'consentAir' => (bool) $s['consent_air'],
                // On a wall at all: with the box ticked, or as a prayer hour's request (on that hour's wall).
                'wall' => $s['type'] === 'prayer' && $s['mode'] === 'text' && ((bool) $s['consent_air'] || (bool) $s['of_prayer_hour']),
                // Open reports from listeners, and who took it down: a moderator, or enough reports.
                'reports' => (int) $s['reports'], 'hiddenBy' => match ((int) $s['hidden']) { 1 => 'moderator', 2 => 'reports', default => null },
                'prayedWith' => (int) $s['prayed_count'],
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

    /**
     * Take a typed prayer off the prayer wall, or put it back: body
     * {hidden: bool}. live.json is rewritten at once, so it is gone from the
     * next fetch (≤ 30 s in the app), not only after the next tick.
     *
     * @param array<string,string> $a
     */
    public function reviewWall(array $a): array
    {
        $this->mod();
        $app = $this->c->app;
        $publicId = (string) ($a['id'] ?? '');
        $hidden = $this->c->req->input('hidden');
        // Only a real boolean: a malformed body must not put a prayer that
        // was taken down back on the wall.
        if (!is_bool($hidden)) throw new ApiError(422, 'bad_hidden');
        if (!$app->submissions()->setHidden($publicId, $hidden)) throw new ApiError(404, 'not_found');
        $sub = $app->submissions()->byPublicId($publicId) ?? throw new ApiError(404, 'not_found');
        // Listeners' reports are answered by the decision: kept (dismissed) or taken down (actioned).
        $app->store()->query("UPDATE wall_reports SET status = ? WHERE submission_id = ? AND status = 'open'", [$hidden ? 'actioned' : 'dismissed', (int) $sub['id']]);
        if ($hidden && $this->c->req->input('ban') === true) {
            $sender = $app->identities()->get((int) $sub['identity_id']);
            if ($sender !== null) $app->identities()->setBanned((string) $sender['public_id'], true, $this->actor());
        }
        $channel = $app->catalog()->channel((int) $sub['channel_id']);
        if ($channel !== null) $app->publisher()->publishLive($channel);
        $app->store()->audit($this->actor(), $hidden ? 'Removed from the prayer wall' : 'Shown on the prayer wall', $publicId);
        return ['ok' => true, 'hidden' => $hidden];
    }

    // --- recorded host lines (Host\Lines) ---------------------------------------------------------------

    /** Moderators look after the lines; recording is bounded by the host's monthly allowance, an admin's. */
    public function lines(): array
    {
        $this->mod();
        $q = $this->c->req->query;
        return $this->c->app->lines()->list($q, (int) ($q['limit'] ?? 50), (int) ($q['offset'] ?? 0));
    }

    public function linesOverview(): array
    {
        $this->mod();
        $host = $this->c->app->hosts()->get((int) ($this->c->req->query['host'] ?? 0)) ?? throw new ApiError(404, 'not_found');
        return $this->c->app->lines()->overview($host, $this->me['role'] === 'admin');
    }

    public function lineAdd(): array
    {
        $this->mod();
        return ['line' => $this->c->app->lines()->add($this->c->req->json(), $this->actor())];
    }

    /** Lines asked of the AI: a model call and recordings each, so a few an hour per moderator. */
    public function linesWrite(): array
    {
        $this->mod();
        if (!$this->c->app->rateLimit()->hit('lines-write:' . $this->me['id'], 30, 3600)) throw new ApiError(429, 'rate_limited');
        return ['ok' => true, 'queued' => $this->c->app->lines()->requestWrite($this->c->req->json(), $this->actor())];
    }

    public function linesBulk(): array
    {
        $this->mod();
        $body = $this->c->req->json();
        return ['ok' => true, 'changed' => $this->c->app->lines()->bulk(is_array($body['ids'] ?? null) ? array_values($body['ids']) : [], (string) ($body['action'] ?? ''), $this->actor())];
    }

    /** @param array<string,string> $a */
    public function lineUpdate(array $a): array
    {
        $this->mod();
        return ['line' => $this->c->app->lines()->update($this->id($a), $this->c->req->json(), $this->actor())];
    }

    /** @param array<string,string> $a */
    public function lineDelete(array $a): array
    {
        $this->mod();
        $this->c->app->lines()->remove($this->id($a), $this->actor());
        return ['ok' => true];
    }

    /** @param array<string,string> $a */
    public function hostLineOptions(array $a): array
    {
        $this->admin();
        return ['options' => $this->c->app->lines()->setOptions($this->id($a), $this->c->req->json(), $this->actor())];
    }

    // --- hosts (Host\Hosts) ----------------------------------------------------------------------------

    /** Moderators read them too (a program's lineup is theirs to set); the key's end is for admins. */
    public function hosts(): array
    {
        $this->mod();
        $admin = $this->me['role'] === 'admin';
        return ['hosts' => $this->c->app->hosts()->views($admin)];
    }

    public function hostCreate(): array
    {
        $this->admin();
        $hosts = $this->c->app->hosts();
        return ['host' => $hosts->view($hosts->save(null, $this->c->req->json(), $this->actor()), true)];
    }

    /** @param array<string,string> $a */
    public function hostUpdate(array $a): array
    {
        $this->admin();
        $hosts = $this->c->app->hosts();
        return ['host' => $hosts->view($hosts->save($this->id($a), $this->c->req->json(), $this->actor()), true)];
    }

    /** @param array<string,string> $a */
    public function hostDelete(array $a): array
    {
        $this->admin();
        $this->c->app->hosts()->delete($this->id($a), $this->actor());
        return ['ok' => true];
    }

    /** @param array<string,string> $a */
    public function hostAvatar(array $a): array
    {
        $this->admin();
        $file = $this->c->req->file('image') ?? throw new ApiError(422, 'missing_image');
        $hosts = $this->c->app->hosts();
        return ['host' => $hosts->view($hosts->setAvatar($this->id($a), $file, $this->actor()), true)];
    }

    /**
     * What a provider offers the host editor: voices and models, and for
     * ElevenLabs the characters left this month — with a key typed in, or
     * the host's own. No characters are spent. In stub mode ElevenLabs is
     * never asked (dev must not touch the station's account).
     */
    public function hostCatalog(): array
    {
        $this->admin();
        $app = $this->c->app;
        $in = $this->c->req->json();
        $provider = (string) ($in['provider'] ?? 'openai');
        $list = fn(array $ids): array => array_map(fn($v) => ['id' => $v, 'name' => $v], $ids);
        if ($provider === 'openai') return ['voices' => $list(Hosts::OPENAI_VOICES), 'models' => $list(Hosts::OPENAI_MODELS), 'account' => null, 'errors' => []];
        if ($provider !== 'elevenlabs') throw new ApiError(422, 'host_provider');
        $models = $list(Hosts::ELEVENLABS_MODELS);
        if ($app->config->stubAi()) return ['voices' => [], 'models' => $models, 'account' => null, 'errors' => [], 'stub' => true];
        $key = trim((string) ($in['api_key'] ?? ''));
        $host = isset($in['host_id']) ? $app->hosts()->get((int) $in['host_id']) : null;
        if ($key === '' && $host !== null && $host['provider'] === 'elevenlabs') $key = $app->hosts()->key($host);
        if ($key === '') throw new ApiError(422, 'host_key');
        $el = $app->elevenLabs();
        $out = ['voices' => [], 'models' => $models, 'account' => null, 'errors' => []];
        // Each on its own: a restricted key may read voices but not the account.
        foreach (['voices', 'models', 'account'] as $what) {
            try {
                $got = $el->$what($key);
                if ($got !== null && $got !== []) $out[$what] = $got;
            } catch (VoiceError $e) {
                $out['errors'][] = $e->getMessage();
            } catch (\RuntimeException) {
                $out['errors'][] = 'ElevenLabs: no answer';
            }
        }
        return $out;
    }

    /**
     * "Try voice": a sample in a host's voice with the editor's unsaved
     * changes (voice, direction, settings, a key typed in). Counted against
     * the host's characters like any clip, and a daily cap still applies — an
     * ElevenLabs host without one is never voiced. 20 an hour per admin.
     */
    public function hostTry(): array
    {
        $this->admin();
        $app = $this->c->app;
        $in = $this->c->req->json();
        $hosts = $app->hosts();
        $host = $hosts->get((int) ($in['host_id'] ?? 0)) ?? throw new ApiError(404, 'not_found');
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) ($in['text'] ?? '')));
        if ($text === '' || mb_strlen($text) > 300) throw new ApiError(422, 'host_try_text');
        $langs = $app->config->stationLangs();
        $lang = in_array($in['lang'] ?? '', $langs, true) ? (string) $in['lang'] : $langs[0];
        $changes = is_array($in['draft'] ?? null) ? $in['draft'] : [];
        $draft = $hosts->draft($host, $changes);
        $key = trim((string) ($changes['api_key'] ?? ''));
        if (!$hosts->mayTry($draft, mb_strlen($text))) throw new ApiError(422, 'host_no_room');
        if (!$app->rateLimit()->hit('host-try:' . $this->me['id'], 20, 3600)) throw new ApiError(429, 'rate_limited');
        try {
            $spoken = $app->voice()->speak($draft, $text, $lang, $key !== '' ? $key : null);
        } catch (VoiceError $e) {
            throw new ApiError(502, 'voice_failed', ['reason' => $e->getMessage()]);
        } catch (BudgetExceeded) {
            throw new ApiError(503, 'voice_timeout');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'try');
        file_put_contents($tmp, $spoken['bytes']);
        $mp3 = Mp3::inspect($tmp);
        @unlink($tmp);
        if (!$mp3['ok']) throw new ApiError(502, 'voice_failed', ['reason' => 'no playable audio']);
        $app->store()->audit($this->actor(), 'Host voice tried', $host['id'] . ' ' . $host['name'] . ' (' . $lang . ', ' . mb_strlen($text) . ' characters)');
        // Which voice and model spoke: what the editor shows, so a choice can be checked by ear and by name.
        return ['audio' => base64_encode($spoken['bytes']), 'ms' => $mp3['ms'], 'provider' => $spoken['provider'],
            'voice' => Hosts::voiceFor($draft, $lang), 'model' => (string) $draft['model']];
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
             FROM identities i WHERE i.canonical_id IS NULL AND i.role != 'station' AND (? = '' OR i.public_id = ? OR i.display_name LIKE ?)
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
        // A report on a community voice (Moderation\Reports) names its highlight: `voice` says so.
        return ['reports' => $this->c->app->store()->all(
            "SELECT r.*, (h.uid IS NOT NULL) AS voice FROM chat_reports r LEFT JOIN highlights h ON h.uid = r.msg
             WHERE r.status = 'open' ORDER BY r.created DESC LIMIT 200",
        )];
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
            $voice = $store->one('SELECT channel FROM highlights WHERE uid = ?', [$report['msg']]);
            $store->query("UPDATE highlights SET status = 'rejected' WHERE uid = ?", [$report['msg']]);
            // A community voice is in live.json: gone with the next fetch, not the next tick.
            $channel = $voice !== null ? $app->catalog()->channelBySlug((string) $voice['channel']) : null;
            if ($channel !== null) $app->publisher()->publishLive($channel);
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

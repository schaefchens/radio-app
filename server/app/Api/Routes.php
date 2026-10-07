<?php
declare(strict_types=1);

namespace Arche\Api;

use Arche\Http\Context;
use Arche\Http\Router;

/**
 * Every API route in one place. Paths are relative to /api. Listener routes
 * identify the device by the X-Arche-Id/X-Arche-Secret headers; /mod routes
 * additionally need a passphrase identity with the moderator (or admin) role.
 */
final class Routes
{
    private static ?Router $router = null;

    public static function router(): Router
    {
        if (self::$router !== null) return self::$router;
        $r = new Router();
        $p = static fn(string $m) => static fn(Context $c, array $a) => (new PublicApi($c))->$m($a);
        $m = static fn(string $method) => static fn(Context $c, array $a) => (new ModApi($c))->$method($a);
        $w = static fn(string $method) => static fn(Context $c, array $a) => (new WorkerApi($c))->$method($a);

        // --- listeners -------------------------------------------------------------
        $r->add('GET', '/time', $p('time'));
        $r->add('POST', '/session', $p('session'));
        $r->add('POST', '/pulse', $p('pulse'));
        $r->add('GET', '/me', $p('me'));
        $r->add('PATCH', '/me', $p('updateMe'));
        $r->add('DELETE', '/me', $p('deleteMe'));
        $r->add('POST', '/identity/claim', $p('claim'));
        $r->add('POST', '/identity/login', $p('login'));
        $r->add('GET', '/setup', $p('setupStatus'));
        $r->add('POST', '/setup/admin', $p('setupAdmin'));
        $r->add('GET', '/submissions', $p('mySubmissions'));
        $r->add('POST', '/submissions/song', $p('submitSong'));
        $r->add('POST', '/submissions/video', $p('submitVideo'));
        $r->add('POST', '/submissions/preaching', $p('submitPreaching'));
        $r->add('POST', '/submissions/audio', $p('submitAudio'));
        $r->add('POST', '/submissions/prayer', $p('submitPrayer'));
        $r->add('POST', '/submissions/intercession', $p('submitIntercession'));
        $r->add('POST', '/wall/{id}/report', $p('reportWall'));
        $r->add('POST', '/voices/{id}/report', $p('reportVoice'));
        $r->add('POST', '/realtime/wake', $p('wake'));
        $r->add('POST', '/realtime/report', $p('nodeReport'));

        // --- moderators ------------------------------------------------------------
        $r->add('GET', '/mod/overview', $m('overview'));
        $r->add('GET', '/mod/status', $m('status'));
        $r->add('GET', '/mod/library', $m('library'));
        $r->add('POST', '/mod/library/lookup', $m('libraryLookup'));
        $r->add('POST', '/mod/library', $m('libraryAdd'));
        $r->add('PATCH', '/mod/library/{id}', $m('libraryUpdate'));
        $r->add('POST', '/mod/library/{id}/pull', $m('libraryPull'));
        $r->add('POST', '/mod/jingles', $m('jingleUpload'));
        $r->add('POST', '/mod/jingles/tts', $m('jingleTts'));
        $r->add('POST', '/mod/beds', $m('bedUpload'));
        $r->add('GET', '/mod/groups', $m('groups'));
        $r->add('POST', '/mod/groups', $m('groupCreate'));
        $r->add('POST', '/mod/groups/channel', $m('groupChannel'));
        $r->add('PATCH', '/mod/groups/{id}', $m('groupUpdate'));
        $r->add('DELETE', '/mod/groups/{id}', $m('groupDelete'));
        $r->add('GET', '/mod/hosts', $m('hosts'));
        $r->add('POST', '/mod/hosts', $m('hostCreate'));
        $r->add('POST', '/mod/hosts/catalog', $m('hostCatalog'));
        $r->add('POST', '/mod/hosts/try', $m('hostTry'));
        $r->add('GET', '/mod/hosts/scenarios', $m('hostScenarios'));
        $r->add('POST', '/mod/hosts/scenario', $m('hostScenario'));
        $r->add('PATCH', '/mod/hosts/{id}', $m('hostUpdate'));
        $r->add('DELETE', '/mod/hosts/{id}', $m('hostDelete'));
        $r->add('POST', '/mod/hosts/{id}/avatar', $m('hostAvatar'));
        $r->add('PATCH', '/mod/hosts/{id}/lines', $m('hostLineOptions'));
        $r->add('GET', '/mod/hosts/try/{id}', $m('hostTryResult'));
        // Voice workers (Host\Workers): admins add them; the workers themselves pull tasks.
        $r->add('GET', '/mod/workers', $m('workers'));
        $r->add('POST', '/mod/workers', $m('workerCreate'));
        $r->add('PATCH', '/mod/workers/{id}', $m('workerUpdate'));
        $r->add('DELETE', '/mod/workers/{id}', $m('workerDelete'));
        $r->add('POST', '/worker/poll', $w('poll'));
        $r->add('POST', '/worker/tasks/{id}/audio', $w('audio'));
        $r->add('POST', '/worker/tasks/{id}/fail', $w('fail'));
        // Recorded host lines (Host\Lines); the fixed paths before {id}.
        $r->add('GET', '/mod/lines/overview', $m('linesOverview'));
        $r->add('POST', '/mod/lines/write', $m('linesWrite'));
        $r->add('POST', '/mod/lines/bulk', $m('linesBulk'));
        $r->add('GET', '/mod/lines', $m('lines'));
        $r->add('POST', '/mod/lines', $m('lineAdd'));
        $r->add('PATCH', '/mod/lines/{id}', $m('lineUpdate'));
        $r->add('DELETE', '/mod/lines/{id}', $m('lineDelete'));
        $r->add('GET', '/mod/channels', $m('channels'));
        $r->add('POST', '/mod/channels', $m('channelCreate'));
        $r->add('PATCH', '/mod/channels/{id}', $m('channelUpdate'));
        $r->add('POST', '/mod/channels/{id}/avatar', $m('channelAvatar'));
        $r->add('GET', '/mod/channels/{id}/programs', $m('programs'));
        $r->add('POST', '/mod/channels/{id}/programs', $m('programCreate'));
        $r->add('PATCH', '/mod/programs/{id}', $m('programUpdate'));
        $r->add('DELETE', '/mod/programs/{id}', $m('programDelete'));
        $r->add('POST', '/mod/programs/{id}/image', $m('programImage'));
        $r->add('GET', '/mod/programs/{id}/opening-prayers', $m('openingPrayers'));
        $r->add('POST', '/mod/programs/{id}/opening-prayers', $m('openingPrayerAdd'));
        $r->add('DELETE', '/mod/opening-prayers/{id}', $m('openingPrayerDelete'));
        $r->add('GET', '/mod/channels/{id}/plans', $m('plans'));
        $r->add('GET', '/mod/channels/{id}/preview', $m('planPreview'));
        $r->add('POST', '/mod/channels/{id}/day-plans', $m('dayPlanCreate'));
        $r->add('PUT', '/mod/day-plans/{id}', $m('dayPlanUpdate'));
        $r->add('DELETE', '/mod/day-plans/{id}', $m('dayPlanDelete'));
        $r->add('PUT', '/mod/channels/{id}/week', $m('weekPlan'));
        $r->add('POST', '/mod/channels/{id}/special-days', $m('specialDayCreate'));
        $r->add('DELETE', '/mod/channels/{id}/special-days/{sid}', $m('specialDayDelete'));
        $r->add('GET', '/mod/review', $m('review'));
        $r->add('POST', '/mod/review/{id}', $m('reviewDecide'));
        $r->add('GET', '/mod/review/{id}/audio', $m('reviewAudio'));
        $r->add('POST', '/mod/review/{id}/wall', $m('reviewWall'));
        $r->add('GET', '/mod/users', $m('users'));
        $r->add('PATCH', '/mod/users/{id}', $m('userUpdate'));
        $r->add('GET', '/mod/reports', $m('reports'));
        $r->add('POST', '/mod/reports/{id}', $m('reportDecide'));
        $r->add('GET', '/mod/highlights', $m('highlights'));
        $r->add('POST', '/mod/highlights/{id}', $m('highlightDecide'));
        $r->add('GET', '/mod/chat-blocklist', $m('blocklist'));
        $r->add('PUT', '/mod/chat-blocklist', $m('blocklistSave'));

        return self::$router = $r;
    }
}

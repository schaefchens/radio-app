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

        // --- listeners -------------------------------------------------------------
        $r->add('GET', '/time', $p('time'));
        $r->add('POST', '/session', $p('session'));
        $r->add('POST', '/pulse', $p('pulse'));
        $r->add('GET', '/me', $p('me'));
        $r->add('PATCH', '/me', $p('updateMe'));
        $r->add('POST', '/identity/claim', $p('claim'));
        $r->add('POST', '/identity/login', $p('login'));
        $r->add('GET', '/setup', $p('setupStatus'));
        $r->add('POST', '/setup/admin', $p('setupAdmin'));
        $r->add('GET', '/submissions', $p('mySubmissions'));
        $r->add('POST', '/submissions/song', $p('submitSong'));
        $r->add('POST', '/submissions/audio', $p('submitAudio'));
        $r->add('POST', '/submissions/prayer', $p('submitPrayer'));
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
        $r->add('GET', '/mod/channels', $m('channels'));
        $r->add('POST', '/mod/channels', $m('channelCreate'));
        $r->add('PATCH', '/mod/channels/{id}', $m('channelUpdate'));
        $r->add('POST', '/mod/channels/{id}/avatar', $m('channelAvatar'));
        $r->add('GET', '/mod/channels/{id}/programs', $m('programs'));
        $r->add('POST', '/mod/channels/{id}/programs', $m('programCreate'));
        $r->add('PATCH', '/mod/programs/{id}', $m('programUpdate'));
        $r->add('DELETE', '/mod/programs/{id}', $m('programDelete'));
        $r->add('POST', '/mod/programs/{id}/image', $m('programImage'));
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

<?php
declare(strict_types=1);

namespace Arche\Api;

use Arche\ApiError;
use Arche\Http\Context;
use Arche\Identity\Identities;
use Arche\Presence\Trends;

/** Listener-facing endpoints. Everything slow happens in jobs, not here. */
final class PublicApi
{
    public function __construct(private Context $c) {}

    /** Server clock for the client's offset estimate (min-RTT sample). */
    public function time(): array
    {
        return ['now' => $this->c->app->clock->nowMs()];
    }

    /** First call of every app start: clock, who am I, feature switches. */
    public function session(): array
    {
        $app = $this->c->app;
        $identity = $this->c->identity(false);
        $cfg = $app->config;
        return [
            'now' => $app->clock->nowMs(),
            'identity' => $identity ? Identities::publicView($identity) : null,
            'config' => [
                'pulse' => $cfg->int('PULSE_SECONDS', 120),
                'langs' => $cfg->stationLangs(),
                'realtime' => $app->nodes()->driver() !== 'off',
                'setupNeeded' => $this->adminMissing(),
                // Program files and media come from here ('' = this origin).
                'cdn' => $cfg->cdnBase(),
            ],
        ];
    }

    /**
     * Presence plus everything the device collected since its last pulse:
     * reactions (batched — one request per pulse, not per tap) and player
     * errors. Answering with the clock doubles as a periodic resync.
     */
    public function pulse(): array
    {
        $app = $this->c->app;
        $req = $this->c->req;
        $device = $this->c->deviceKey();
        $channel = $this->c->channel();
        $slug = (string) $channel['slug'];
        $app->presence()->pulse($device, $slug);

        // One device cannot move the trend much: at most 30 taps per pulse count.
        $budget = 30;
        $deltas = [];
        foreach (array_slice((array) $req->input('reactions', []), 0, 50) as $r) {
            if (!is_array($r) || !isset($r['item'], $r['kind']) || !isset(Trends::WEIGHTS[(string) $r['kind']])) continue;
            $n = max(0, min($budget, (int) ($r['n'] ?? 1)));
            $budget -= $n;
            if ($n > 0) $deltas[(string) $r['item']][(string) $r['kind']] = ($deltas[(string) $r['item']][(string) $r['kind']] ?? 0) + $n;
            if ($budget <= 0) break;
        }
        $app->trends()->applyItemReactions($deltas, 30);

        $voices = [];
        foreach (array_slice((array) $req->input('voices', []), 0, 20) as $v) {
            if (is_array($v) && isset($v['voice'], $v['kind']) && isset(Trends::WEIGHTS[(string) $v['kind']])) {
                $voices[(string) $v['voice']][(string) $v['kind']] = 1;
            }
        }
        $app->trends()->applyVoiceReactions($voices);

        foreach (array_slice((array) $req->input('errors', []), 0, 5) as $e) {
            if (!is_array($e) || !isset($e['item'], $e['code'])) continue;
            $item = $app->timeline()->byUid((string) $e['item']);
            if ($item !== null && $item['type'] === 'song' && $item['library_id'] !== null) {
                $app->library()->reportPlaybackError($item['library_id'], $device, (int) $e['code']);
            }
        }
        return ['now' => $app->clock->nowMs(), 'pulse' => $app->config->int('PULSE_SECONDS', 120)];
    }

    public function me(): array
    {
        $i = $this->c->identity(false);
        return ['identity' => $i ? Identities::publicView($i) : null];
    }

    public function updateMe(): array
    {
        $i = $this->c->requireIdentity();
        $req = $this->c->req->json();
        $fields = array_intersect_key($req, array_flip(['name', 'country', 'lang']));
        return ['identity' => Identities::publicView($this->c->app->identities()->update($i, $fields))];
    }

    public function claim(): array
    {
        $i = $this->c->requireIdentity();
        $out = $this->c->app->identities()->claim($i, (string) $this->c->req->input('credId', ''), (string) $this->c->req->input('credSecret', ''));
        return ['identity' => Identities::publicView($out)];
    }

    public function login(): array
    {
        $req = $this->c->req;
        $out = $this->c->app->identities()->login(
            $req->header('x-arche-id'),
            $req->header('x-arche-secret'),
            (string) $req->input('credId', ''),
            (string) $req->input('credSecret', ''),
        );
        return ['identity' => Identities::publicView($out)];
    }

    private function adminMissing(): bool
    {
        return (int) $this->c->app->store()->value("SELECT COUNT(*) FROM identities WHERE role = 'admin' AND cred_key IS NOT NULL") === 0;
    }

    public function setupStatus(): array
    {
        return ['needed' => $this->adminMissing()];
    }

    /**
     * The first admin. The webhosting has no shell, so this is the one way in:
     * the one-time ADMIN_SETUP_KEY from the server .env plus a passphrase
     * identity. Once an admin exists the endpoint is closed for good.
     */
    public function setupAdmin(): array
    {
        $app = $this->c->app;
        $i = $this->c->requireIdentity();
        if (!$this->adminMissing()) throw new ApiError(409, 'setup_done');
        if (!$app->rateLimit()->hit('setup:' . $app->rateLimit()->ipKey(), 5, 3600)) throw new ApiError(429, 'rate_limited');
        $key = $app->config->get('ADMIN_SETUP_KEY');
        if (strlen($key) < 32 || !hash_equals($key, (string) $this->c->req->input('key', ''))) throw new ApiError(403, 'bad_setup_key');
        if (empty($i['cred_key'])) throw new ApiError(409, 'needs_passphrase');
        $out = $app->identities()->setRole((string) $i['public_id'], 'admin', 'setup');
        return ['identity' => Identities::publicView($out)];
    }

    public function mySubmissions(): array
    {
        $i = $this->c->identity(false);
        return ['submissions' => $i ? $this->c->app->submissions()->forIdentity($i) : []];
    }

    public function submitSong(): array
    {
        $i = $this->c->requireIdentity();
        return ['submission' => $this->c->app->submissions()->submitSong($i, $this->c->channel(), $this->c->req->json())];
    }

    public function submitAudio(): array
    {
        $i = $this->c->requireIdentity();
        $file = $this->c->req->file('audio') ?? throw new ApiError(422, 'missing_audio');
        return ['submission' => $this->c->app->submissions()->submitAudio($i, $this->c->channel(), $this->c->req->post, $file)];
    }

    public function submitPrayer(): array
    {
        $i = $this->c->requireIdentity();
        return ['submission' => $this->c->app->submissions()->submitPrayer($i, $this->c->channel(), $this->c->req->json())];
    }

    public function wake(): array
    {
        $i = $this->c->requireIdentity();
        $app = $this->c->app;
        if (!$app->rateLimit()->hit('wake:' . $i['id'], 40, 300)) throw new ApiError(429, 'rate_limited');
        return $app->wake()->handle($i, (string) $this->c->channel()['slug']);
    }

    /** Signed batch report from a realtime node. */
    public function nodeReport(): array
    {
        $app = $this->c->app;
        $slot = $app->nodes()->authenticate($this->c->req->headers, $this->c->req->body);
        if ($slot === null) throw new ApiError(401, 'bad_signature');
        $report = json_decode($this->c->req->body, true);
        if (!is_array($report) || ($report['v'] ?? 0) !== 1) throw new ApiError(400, 'bad_report');
        return $app->nodes()->ingest($slot, $report);
    }
}

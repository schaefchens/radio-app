<?php
declare(strict_types=1);

namespace Arche\Presence;

use Arche\App;

/**
 * Listener counts and the community voices on the main screen.
 *
 * Until BunnyCDN logs are wired in, the count comes from presence pulses: the
 * app pings every `pulse` seconds (live.json says how often; 0 switches the
 * pulses off under load). Writes are coalesced to one per device per 50 s.
 * While the realtime system is up, its own per-channel count is folded in.
 */
final class Presence
{
    /** @var array<string,int> */
    private array $counts = [];

    public function __construct(private App $app) {}

    public function pulse(string $device, string $channel): void
    {
        $now = $this->app->clock->now();
        $this->app->store()->query(
            'INSERT INTO presence(device, channel, seen) VALUES(?, ?, ?)
             ON CONFLICT(device) DO UPDATE SET channel = excluded.channel, seen = excluded.seen
             WHERE presence.seen < excluded.seen - 50 OR presence.channel != excluded.channel',
            [$device, $channel, $now],
        );
        unset($this->counts[$channel]);
    }

    public function listeners(string $channel): int
    {
        if (isset($this->counts[$channel])) return $this->counts[$channel];
        $since = $this->app->clock->now() - $this->app->config->int('PRESENCE_WINDOW_SECONDS', 300);
        $pulses = (int) $this->app->store()->value('SELECT COUNT(*) FROM presence WHERE channel = ? AND seen >= ?', [$channel, $since]);
        // At scale the CDN log is the count (every client fetches each minute
        // file once); pulses and room presence cover the rest.
        return $this->counts[$channel] = max($pulses, $this->app->nodes()->presence($channel), $this->app->cdn()->listeners($channel) ?? 0);
    }

    /**
     * Voices for the main screen: approved chat highlights and approved
     * prayer requests from the last two hours, newest first.
     *
     * @return list<array{id:string,name:string,country:string,text:string,at:int}>
     */
    public function voices(string $channel, int $limit = 6): array
    {
        $since = $this->app->clock->now() - 7200;
        $store = $this->app->store();
        $out = [];
        foreach ($store->all(
            "SELECT uid, name, country, text, at FROM highlights WHERE channel = ? AND status = 'approved' AND at >= ? ORDER BY at DESC LIMIT ?",
            [$channel, $since * 1000, $limit],
        ) as $h) {
            $out[] = ['id' => (string) $h['uid'], 'name' => (string) $h['name'], 'country' => (string) $h['country'], 'text' => (string) $h['text'], 'at' => (int) $h['at']];
        }
        foreach ($store->all(
            "SELECT s.public_id, s.name, s.text, s.created, i.country FROM submissions s JOIN identities i ON i.id = s.identity_id
             JOIN channels c ON c.id = s.channel_id
             WHERE c.slug = ? AND s.type = 'prayer' AND s.mode = 'text' AND s.status IN ('approved', 'scheduled', 'aired')
             AND s.consent_air = 1 AND s.created >= ? ORDER BY s.created DESC LIMIT ?",
            [$channel, $since, $limit],
        ) as $p) {
            $out[] = ['id' => 'p' . $p['public_id'], 'name' => (string) $p['name'], 'country' => (string) $p['country'],
                'text' => (string) $p['text'], 'at' => (int) $p['created'] * 1000];
        }
        usort($out, fn($a, $b) => $b['at'] <=> $a['at']);
        return array_slice($out, 0, $limit);
    }
}

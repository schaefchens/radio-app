<?php
declare(strict_types=1);

namespace Arche\Realtime;

use Arche\App;

/**
 * The realtime nodes' state, kept in SQLite so a wake poll never costs a
 * Hetzner API call (the API allows 3600 requests an hour, and every waiting
 * listener polls every few seconds).
 *
 *   off → creating → booting → ready ⇄ draining → off
 *
 * `ready` means "its first report arrived", not "Hetzner says running": the
 * node reports at startup, so a report proves it is actually listening.
 *
 * Drivers: `static` (dev: the compose `realtime` service, always there),
 * `hcloud` (scale-to-zero on Hetzner Cloud), `off`.
 */
final class Nodes
{
    public function __construct(private App $app) {}

    public function driver(): string
    {
        $d = $this->app->config->get('REALTIME_DRIVER');
        return in_array($d, ['static', 'hcloud', 'off'], true) ? $d : 'off';
    }

    /** @return list<array{slot:string,host:string,ipv4:int,ipv6:int,volume:int}> */
    public function slots(): array
    {
        $out = [];
        foreach ($this->app->config->json('REALTIME_SLOTS') as $s) {
            if (!is_array($s) || !isset($s['slot'], $s['host'])) continue;
            $out[] = ['slot' => (string) $s['slot'], 'host' => (string) $s['host'], 'ipv4' => (int) ($s['ipv4'] ?? 0),
                'ipv6' => (int) ($s['ipv6'] ?? 0), 'volume' => (int) ($s['volume'] ?? 0)];
        }
        $max = max(1, $this->app->config->int('REALTIME_MAX_NODES', 1));
        return array_slice($out, 0, $max);
    }

    /** @return array<string,mixed>|null */
    public function row(string $slot): ?array
    {
        $r = $this->app->store()->one('SELECT * FROM nodes WHERE slot = ?', [$slot]);
        if ($r === null) return null;
        $r['info'] = json_decode((string) $r['info'], true) ?: [];
        return $r;
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return array_map(function ($r) {
            $r['info'] = json_decode((string) $r['info'], true) ?: [];
            return $r;
        }, $this->app->store()->all('SELECT * FROM nodes ORDER BY slot'));
    }

    /** @param array<string,mixed> $set */
    private function save(string $slot, array $set): void
    {
        $now = $this->app->clock->now();
        $store = $this->app->store();
        $store->query('INSERT OR IGNORE INTO nodes(slot, state, updated) VALUES(?, ?, ?)', [$slot, 'off', $now]);
        if (isset($set['info']) && is_array($set['info'])) $set['info'] = json_encode($set['info'], JSON_UNESCAPED_SLASHES);
        $store->update('nodes', $set + ['updated' => $now], 'slot = ?', [$slot]);
    }

    /** Listeners connected to the realtime system for a channel (fresh reports only). */
    public function presence(string $channel): int
    {
        $since = $this->app->clock->now() - 60;
        $n = 0;
        foreach ($this->app->store()->all("SELECT info FROM nodes WHERE state IN ('ready', 'draining') AND last_report >= ?", [$since]) as $r) {
            $info = json_decode((string) $r['info'], true) ?: [];
            $n += (int) ($info['presence'][$channel] ?? 0);
        }
        return $n;
    }

    // --- wake ------------------------------------------------------------------------------

    /**
     * Where should this listener connect? Never blocks: at most one create call
     * per wake, under a non-blocking lock; everyone else reads the state.
     *
     * @return array{status:string,url?:string,retryMs?:int}
     */
    public function wake(): array
    {
        $driver = $this->driver();
        if ($driver === 'off') return ['status' => 'unavailable'];
        if ($driver === 'static') {
            return ['status' => 'ready', 'url' => $this->app->config->get('REALTIME_STATIC_URL')];
        }

        $now = $this->app->clock->now();
        $capacity = $this->app->config->int('REALTIME_NODE_CAPACITY', 800);
        $best = null;
        $starting = false;
        foreach ($this->slots() as $slot) {
            $row = $this->row($slot['slot']);
            if ($row === null) continue;
            $this->save($slot['slot'], ['last_wake' => $now]);
            if ($row['state'] === 'ready' && !(int) $row['draining'] && (int) $row['connections'] < $capacity) {
                if ($best === null || (int) $row['connections'] < (int) $best['row']['connections']) $best = ['row' => $row, 'slot' => $slot];
            }
            if (in_array($row['state'], ['creating', 'booting'], true)) $starting = true;
        }
        if ($best !== null) return ['status' => 'ready', 'url' => 'wss://' . $best['slot']['host'] . '/ws'];
        if ($starting) return ['status' => 'starting', 'retryMs' => 3000];

        // Nothing usable: start the first free slot (scale out when all are full).
        $result = $this->app->lock()->run('wake', function () {
            foreach ($this->slots() as $slot) {
                $row = $this->row($slot['slot']);
                if ($row !== null && $row['state'] !== 'off') continue;
                $this->create($slot);
                return true;
            }
            return false;
        });
        if ($result === false) return ['status' => 'unavailable'];
        return ['status' => 'starting', 'retryMs' => 3000];
    }

    /** @param array{slot:string,host:string,ipv4:int,ipv6:int,volume:int} $slot */
    private function create(array $slot): void
    {
        $cloud = $this->app->cloud();
        $c = $this->app->config;
        $this->save($slot['slot'], ['state' => 'creating', 'error' => '', 'created_at' => $this->app->clock->now(), 'host' => $slot['host'],
            'connections' => 0, 'empty_since' => null, 'draining' => 0, 'ready_at' => null, 'last_report' => null]);
        try {
            // A server still holding this slot's IPs/volume (being deleted, or
            // left over) must go first; the next wake retries the create.
            $existing = $cloud->servers('app=arche,role=realtime-node,slot=' . $slot['slot']);
            if ($existing) {
                $server = $existing[0];
                if (($server['status'] ?? '') === 'running') {
                    $this->save($slot['slot'], ['state' => 'booting', 'server_id' => (int) $server['id']]);
                } else {
                    $this->save($slot['slot'], ['state' => 'off', 'error' => 'slot busy: ' . ($server['status'] ?? '?')]);
                }
                return;
            }
            // Hetzner runs out of a server type now and then (the answer is
            // "unsupported location for server type" or resource_unavailable):
            // the fallback type keeps the rooms open. Each needs a snapshot of
            // its own architecture.
            $types = array_values(array_unique(array_filter([$c->get('REALTIME_SERVER_TYPE'), $c->get('REALTIME_FALLBACK_TYPE')])));
            $failure = null;
            foreach ($types as $type) {
                $snapshot = $cloud->newestSnapshot('app=arche,role=realtime-node', $type);
                if ($snapshot === null) {
                    $failure ??= new \RuntimeException("No realtime snapshot for $type — run npm run realtime:snapshot");
                    continue;
                }
                $image = 'arche-realtime:' . ($snapshot['labels']['version'] ?? 'latest');
                try {
                    $server = $cloud->createServer([
                        'name' => 'arche-' . $slot['slot'] . '-' . gmdate('ymdHis', $this->app->clock->now()),
                        'server_type' => $type,
                        'image' => (int) $snapshot['id'],
                        'location' => $c->get('REALTIME_LOCATION'),
                        'start_after_create' => true,
                        'public_net' => ['enable_ipv4' => true, 'enable_ipv6' => true, 'ipv4' => $slot['ipv4'], 'ipv6' => $slot['ipv6']],
                        'volumes' => [$slot['volume']],
                        'automount' => false,
                        'firewalls' => $c->has('REALTIME_FIREWALL_ID') ? [['firewall' => $c->int('REALTIME_FIREWALL_ID')]] : [],
                        'user_data' => $this->cloudInit($slot, $image),
                        'labels' => ['app' => 'arche', 'role' => 'realtime-node', 'slot' => $slot['slot']],
                    ]);
                } catch (\Throwable $e) {
                    $failure = $e;
                    continue;
                }
                $this->save($slot['slot'], ['state' => 'booting', 'server_id' => (int) ($server['id'] ?? 0)]);
                $this->app->store()->audit('realtime', 'Node created', $slot['slot'] . ' ' . $type . ' ' . $image);
                return;
            }
            throw $failure ?? new \RuntimeException('No REALTIME_SERVER_TYPE configured');
        } catch (\Throwable $e) {
            $this->save($slot['slot'], ['state' => 'off', 'error' => substr($e->getMessage(), 0, 200)]);
            $this->app->store()->audit('realtime', 'Node create failed', $slot['slot'] . ': ' . $e->getMessage());
        }
    }

    /** @param array{slot:string,host:string,ipv4:int,ipv6:int,volume:int} $slot */
    public function cloudInit(array $slot, string $image): string
    {
        $root = $this->app->config->root;
        $tmpl = null;
        foreach ([$root . '/resources/cloud-init.yaml.tmpl', dirname($root) . '/infra/realtime/cloud-init.yaml.tmpl'] as $f) {
            if (is_file($f)) {
                $tmpl = (string) file_get_contents($f);
                break;
            }
        }
        if ($tmpl === null) throw new \RuntimeException('cloud-init template missing');
        $pub = $this->publicKey();
        $vars = [
            'PUBLIC_HOST' => $slot['host'],
            'NODE_SLOT' => $slot['slot'],
            'API_BASE' => rtrim($this->app->config->get('SITE_BASE_URL'), '/'),
            'NODE_SECRET' => $this->app->config->get('NODE_SECRET'),
            'TOKEN_PUBLIC_KEY' => $pub,
            'ACME_EMAIL' => $this->app->config->get('REALTIME_ACME_EMAIL'),
            'VOLUME_ID' => (string) $slot['volume'],
            'IMAGE' => $image,
        ];
        foreach ($vars as $k => $v) {
            if ($v === '') throw new \RuntimeException("cloud-init value $k is empty");
            $tmpl = str_replace('{{' . $k . '}}', $v, $tmpl);
        }
        if (str_contains($tmpl, '{{')) throw new \RuntimeException('cloud-init template has unknown placeholders');
        return $tmpl;
    }

    private function publicKey(): string
    {
        $pub = $this->app->config->get('TOKEN_PUBLIC_KEY');
        if ($pub !== '') return $pub;
        $sk = base64_decode($this->app->config->get('TOKEN_SIGNING_KEY'), true);
        return $sk !== false && strlen($sk) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES ? base64_encode(sodium_crypto_sign_publickey_from_secretkey($sk)) : '';
    }

    // --- reports ------------------------------------------------------------------------------

    /**
     * Verify the signed report headers. Returns the slot on success.
     *
     * @param array<string,string> $headers lower-cased
     */
    public function authenticate(array $headers, string $body): ?string
    {
        $secret = $this->app->config->get('NODE_SECRET');
        $slot = (string) ($headers['x-arche-node'] ?? '');
        $ts = (int) ($headers['x-arche-timestamp'] ?? 0);
        $sig = (string) ($headers['x-arche-signature'] ?? '');
        if (strlen($secret) < 32 || $slot === '' || abs($this->app->clock->now() - $ts) > 120) return null;
        if (!hash_equals(hash_hmac('sha256', $ts . '.' . $body, $secret), $sig)) return null;
        $known = array_column($this->slots(), 'slot');
        if ($this->driver() === 'static') $known[] = 'local';
        return in_array($slot, $known, true) ? $slot : null;
    }

    /**
     * Take in one report and answer with bans, removals, config and drain.
     *
     * Reports are additive and may be re-sent after a failed delivery, so
     * candidates and abuse reports are keyed (message id, reporter) and
     * inserted at most once.
     *
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    public function ingest(string $slot, array $report): array
    {
        $now = $this->app->clock->now();
        $store = $this->app->store();
        $prev = $this->row($slot);
        $connections = max(0, (int) ($report['connections'] ?? 0));
        $emptySince = $connections > 0 ? null : ($prev['empty_since'] ?? null) ?? $now;
        $this->save($slot, [
            'state' => ($prev['draining'] ?? 0) ? 'draining' : 'ready',
            'ready_at' => $prev['ready_at'] ?? $now,
            'last_report' => $now,
            'connections' => $connections,
            'empty_since' => $emptySince,
            'info' => [
                'startedAt' => (int) ($report['startedAt'] ?? 0),
                'presence' => array_map('intval', (array) ($report['presence'] ?? [])),
                'rooms' => array_slice((array) ($report['rooms'] ?? []), 0, 200),
            ],
        ]);

        $deltas = [];
        foreach ((array) ($report['reactions'] ?? []) as $r) {
            if (is_array($r) && isset($r['item'])) $deltas[(string) $r['item']] = array_map('intval', (array) ($r['counts'] ?? []));
        }
        $this->app->trends()->applyItemReactions($deltas);
        $voiceDeltas = [];
        foreach ((array) ($report['voiceReactions'] ?? []) as $r) {
            if (is_array($r) && isset($r['voice'])) $voiceDeltas[(string) $r['voice']] = array_map('intval', (array) ($r['counts'] ?? []));
        }
        $this->app->trends()->applyVoiceReactions($voiceDeltas);

        foreach (array_slice((array) ($report['candidates'] ?? []), 0, 100) as $c) {
            if (!is_array($c) || !isset($c['id'], $c['text'])) continue;
            $store->query(
                'INSERT INTO highlights(uid, channel, lang, sub, name, country, text, likes, status, at, created, updated)
                 VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT(uid) DO UPDATE SET likes = MAX(likes, excluded.likes)',
                [mb_substr((string) $c['id'], 0, 64), mb_substr((string) ($c['channel'] ?? 'main'), 0, 32), ($c['lang'] ?? 'en') === 'de' ? 'de' : 'en',
                    mb_substr((string) ($c['sub'] ?? ''), 0, 64), mb_substr((string) ($c['name'] ?? ''), 0, 30), mb_substr((string) ($c['country'] ?? ''), 0, 2),
                    mb_substr((string) $c['text'], 0, 280), (int) ($c['likes'] ?? 0), 'candidate', (int) ($c['at'] ?? $now * 1000), $now, $now],
            );
        }
        foreach (array_slice((array) ($report['reports'] ?? []), 0, 100) as $r) {
            if (!is_array($r) || !isset($r['msg'], $r['by'])) continue;
            $store->query(
                'INSERT OR IGNORE INTO chat_reports(msg, text, author, reporter, reason, status, at, created) VALUES(?, ?, ?, ?, ?, ?, ?, ?)',
                [mb_substr((string) $r['msg'], 0, 64), mb_substr((string) ($r['text'] ?? ''), 0, 280), mb_substr((string) ($r['sub'] ?? ''), 0, 64),
                    mb_substr((string) $r['by'], 0, 64), mb_substr((string) ($r['reason'] ?? ''), 0, 60), 'open', (int) ($r['at'] ?? $now * 1000), $now],
            );
        }

        $removed = array_map(fn($r) => (string) $r['msg'], $store->all('SELECT msg FROM removed_messages WHERE time >= ?', [$now - 3600]));
        return [
            'ok' => true,
            'bans' => $this->app->identities()->bannedIds(),
            'removed' => $removed,
            'config' => [
                'maxMessageLength' => 280,
                'roomCapacity' => 100,
                'slowModeMs' => 3000,
                'blocklist' => array_values(array_map('strval', (array) ($store->get('chat_blocklist') ?? []))),
                'highlightLikes' => 5,
            ],
            'drain' => (bool) ($this->row($slot)['draining'] ?? false),
        ];
    }

    // --- maintenance (every minute, jobs lock) -----------------------------------------------

    /**
     * Reap nodes that are idle, silent or too old; drain one when the others
     * have room. Returns what it did.
     *
     * @return array<string,string>
     */
    public function maintain(): array
    {
        if ($this->driver() !== 'hcloud') return [];
        $c = $this->app->config;
        $now = $this->app->clock->now();
        $idle = $c->int('REALTIME_IDLE_MINUTES', 10) * 60;
        $silent = $c->int('REALTIME_SILENT_MINUTES', 3) * 60;
        $maxLife = $c->int('REALTIME_MAX_LIFETIME_HOURS', 12) * 3600;
        $did = [];
        $ready = [];
        foreach ($this->slots() as $slot) {
            $row = $this->row($slot['slot']);
            if ($row === null || $row['state'] === 'off') continue;
            $why = null;
            $created = (int) ($row['created_at'] ?? $now);
            if ($row['state'] === 'booting' && $now - $created > 300) $why = 'boot timeout';
            elseif (in_array($row['state'], ['ready', 'draining'], true) && $now - (int) $row['last_report'] > $silent) $why = 'silent';
            elseif ($row['empty_since'] !== null && $now - (int) $row['empty_since'] > $idle && $now - (int) ($row['last_wake'] ?? 0) > $idle) $why = 'idle';
            elseif ($now - $created > $maxLife && (int) $row['connections'] === 0) $why = 'lifetime';
            elseif ($row['state'] === 'draining' && (int) $row['connections'] === 0) $why = 'drained';
            if ($why !== null) {
                $this->destroy($slot['slot'], (int) ($row['server_id'] ?? 0), $why);
                $did[$slot['slot']] = 'deleted: ' . $why;
            } elseif ($row['state'] === 'ready') {
                $ready[] = $row;
            }
        }
        // Scale in: with two or more nodes and room to spare, drain the emptiest.
        $capacity = $c->int('REALTIME_NODE_CAPACITY', 800);
        if (count($ready) > 1 && array_sum(array_map(fn($r) => (int) $r['connections'], $ready)) < $capacity / 2) {
            usort($ready, fn($a, $b) => (int) $a['connections'] <=> (int) $b['connections']);
            $this->save((string) $ready[0]['slot'], ['draining' => 1, 'state' => 'draining']);
            $did[(string) $ready[0]['slot']] = 'draining';
        }
        return $did;
    }

    private function destroy(string $slot, int $serverId, string $why): void
    {
        try {
            if ($serverId > 0) $this->app->cloud()->deleteServer($serverId);
            $this->save($slot, ['state' => 'off', 'server_id' => null, 'connections' => 0, 'draining' => 0, 'empty_since' => null, 'error' => '']);
            $this->app->store()->audit('realtime', 'Node deleted', "$slot ($why)");
        } catch (\Throwable $e) {
            $this->save($slot, ['error' => substr('delete failed: ' . $e->getMessage(), 0, 200)]);
        }
    }

    /** Write a minimal JSON file other tools can read (e.g. /mod status). @return array<string,mixed> */
    public function status(): array
    {
        return ['driver' => $this->driver(), 'slots' => array_column($this->slots(), 'slot'), 'nodes' => $this->all()];
    }
}

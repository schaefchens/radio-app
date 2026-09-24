<?php
declare(strict_types=1);

use Arche\Realtime\HetznerCloud;

final class FakeCloud extends HetznerCloud
{
    /** @var list<array{0:string,1:string,2:?array}> */
    public array $calls = [];
    public array $servers = [];

    protected function token(): string
    {
        return 'fake';
    }

    public function request(string $method, string $path, ?array $body = null): array
    {
        $this->calls[] = [$method, $path, $body];
        if ($method === 'GET' && str_starts_with($path, '/servers')) return ['servers' => $this->servers];
        if ($method === 'GET' && str_starts_with($path, '/images')) {
            return ['images' => [['id' => 77, 'status' => 'available', 'architecture' => 'arm', 'labels' => ['version' => 'abc123']]]];
        }
        if ($method === 'POST' && $path === '/servers') {
            $this->servers[] = ['id' => 501, 'status' => 'initializing', 'labels' => $body['labels']];
            return ['server' => ['id' => 501]];
        }
        if ($method === 'DELETE') {
            $this->servers = [];
            return [];
        }
        return [];
    }
}

function keys(): array
{
    $kp = sodium_crypto_sign_keypair();
    return ['TOKEN_SIGNING_KEY' => base64_encode(sodium_crypto_sign_secretkey($kp)), 'TOKEN_PUBLIC_KEY' => base64_encode(sodium_crypto_sign_publickey($kp))];
}

function signedReport(Arche\App $app, string $slot, array $report): array
{
    $body = json_encode($report);
    $ts = $app->clock->now();
    return [['x-arche-node' => $slot, 'x-arche-timestamp' => (string) $ts, 'x-arche-signature' => hash_hmac('sha256', "$ts.$body", str_repeat('n', 40))], $body];
}

test('realtime: tokens are Ed25519-signed, base64url, and carry the public id only', function () {
    $app = TestKit::app(keys());
    [$d, $s] = device();
    $me = $app->identities()->resolve($d, $s, true);
    $token = $app->tokens()->issue($me, 'main');
    check(preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $token) === 1, 'wire form');
    $payload = $app->tokens()->verify($token);
    eq($payload['sub'], $me['public_id'], 'sub is the public id');
    eq($payload['ch'], 'main', 'channel');
    check(!str_contains($token . json_encode($payload), (string) $me['id']) || strlen((string) $me['id']) < 3, 'no internal id');
    TestKit::clock($app)->advance(601_000);
    eq($app->tokens()->verify($token), null, 'expires');
});

test('realtime: reports need a fresh valid signature and are ingested idempotently', function () {
    $app = TestKit::app(keys());
    $nodes = $app->nodes();
    $report = ['v' => 1, 'node' => 'local', 'startedAt' => 1, 'at' => 2, 'connections' => 3, 'draining' => false, 'rooms' => [],
        'presence' => ['main' => 3], 'reactions' => [], 'voiceReactions' => [],
        'candidates' => [['id' => 'm1', 'channel' => 'main', 'lang' => 'en', 'sub' => 'abc', 'name' => 'Maria', 'country' => 'DE', 'text' => 'Praise God!', 'likes' => 5, 'at' => 1]],
        'reports' => [['msg' => 'm2', 'text' => 'spam', 'sub' => 'x', 'by' => 'y', 'reason' => 'spam', 'at' => 1]]];
    [$h, $body] = signedReport($app, 'local', $report);
    eq($nodes->authenticate($h, $body), 'local', 'valid');
    $bad = $h;
    $bad['x-arche-signature'] = str_repeat('0', 64);
    eq($nodes->authenticate($bad, $body), null, 'bad signature');
    $old = $h;
    $old['x-arche-timestamp'] = (string) ($app->clock->now() - 600);
    eq($nodes->authenticate($old, $body), null, 'stale timestamp');
    $nodes->ingest('local', $report);
    $out = $nodes->ingest('local', $report); // re-sent after a lost response
    eq((int) $app->store()->value('SELECT COUNT(*) FROM highlights'), 1, 'candidate once');
    eq((int) $app->store()->value('SELECT COUNT(*) FROM chat_reports'), 1, 'report once');
    eq($app->presence()->listeners('main'), 3, 'node presence counts as listeners');
    check($out['ok'] === true && isset($out['config']['roomCapacity']), 'response shape');
});

test('realtime: static driver hands out the dev URL with a token', function () {
    $app = TestKit::app(keys() + ['REALTIME_STATIC_URL' => 'ws://localhost:8787/ws']);
    [$d, $s] = device();
    $w = $app->wake()->handle($app->identities()->resolve($d, $s, true), 'main');
    eq([$w['status'], $w['url']], ['ready', 'ws://localhost:8787/ws'], 'ready');
    check(isset($w['token']), 'token');
});

test('realtime: hcloud wake creates one node, ready at its first report, reaped when idle', function () {
    $slots = json_encode([['slot' => 'rt1', 'host' => 'rt1.radio.example', 'ipv4' => 11, 'ipv6' => 12, 'volume' => 13]]);
    $app = TestKit::app(keys() + ['REALTIME_DRIVER' => 'hcloud', 'REALTIME_SLOTS' => $slots, 'REALTIME_FIREWALL_ID' => '99',
        'SITE_BASE_URL' => 'https://radio.example', 'REALTIME_ACME_EMAIL' => 'ops@example.org']);
    $cloud = new FakeCloud($app);
    $app->set('cloud', $cloud);
    [$d, $s] = device();
    $me = $app->identities()->resolve($d, $s, true);

    eq($app->wake()->handle($me, 'main')['status'], 'starting', 'first wake starts a node');
    $create = array_values(array_filter($cloud->calls, fn($c) => $c[0] === 'POST'));
    eq(count($create), 1, 'one create call');
    $body = $create[0][2];
    eq([$body['server_type'], $body['image'], $body['volumes'], $body['automount']], ['cax11', 77, [13], false], 'server from the snapshot with the slot volume');
    eq($body['public_net']['ipv4'], 11, 'slot primary IPv4');
    check(!str_contains($body['user_data'], '{{'), 'cloud-init fully rendered');
    check(str_contains($body['user_data'], 'arche-realtime:abc123'), 'image tag from the snapshot label');
    eq($app->wake()->handle($me, 'main')['status'], 'starting', 'polls read state, no second create');
    eq(count(array_filter($cloud->calls, fn($c) => $c[0] === 'POST')), 1, 'still one create');

    $app->nodes()->ingest('rt1', ['v' => 1, 'connections' => 0, 'presence' => []]);
    $w = $app->wake()->handle($me, 'main');
    eq([$w['status'], $w['url']], ['ready', 'wss://rt1.radio.example/ws'], 'ready after the first report');

    TestKit::clock($app)->advance(11 * 60_000);
    $app->nodes()->ingest('rt1', ['v' => 1, 'connections' => 0, 'presence' => []]);
    $did = $app->nodes()->maintain();
    check(str_starts_with($did['rt1'] ?? '', 'deleted'), 'idle node deleted: ' . json_encode($did));
    eq($app->nodes()->row('rt1')['state'], 'off', 'slot is off again');
});

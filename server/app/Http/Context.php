<?php
declare(strict_types=1);

namespace Arche\Http;

use Arche\ApiError;
use Arche\App;

/** Per-request helpers: who is calling, and which channel they mean. */
final class Context
{
    /** @var array<string,mixed>|null|false false = not resolved yet */
    private array|null|false $identity = false;

    public function __construct(public readonly App $app, public readonly Request $req) {}

    public function deviceId(): string
    {
        return $this->req->header('x-arche-id');
    }

    /** The device's presence/reaction key; no identity row is created. */
    public function deviceKey(): string
    {
        $id = $this->deviceId();
        if (!preg_match('/^[0-9a-f-]{36}$/', $id)) throw new ApiError(401, 'bad_identity');
        return $this->app->identities()->deviceKey($id);
    }

    /** @return array<string,mixed>|null */
    public function identity(bool $create = false): ?array
    {
        if ($this->identity !== false && ($this->identity !== null || !$create)) return $this->identity;
        $id = $this->req->header('x-arche-id');
        $secret = $this->req->header('x-arche-secret');
        if ($id === '' || $secret === '') return $this->identity = null;
        return $this->identity = $this->app->identities()->resolve($id, $secret, $create);
    }

    /** @return array<string,mixed> */
    public function requireIdentity(): array
    {
        $i = $this->identity(true);
        if ($i === null) throw new ApiError(401, 'identity_required');
        if ($i['banned']) throw new ApiError(403, 'banned');
        return $i;
    }

    /** @return array<string,mixed> */
    public function requireRole(string $role): array
    {
        $i = $this->requireIdentity();
        $rank = ['listener' => 0, 'moderator' => 1, 'admin' => 2];
        if (empty($i['cred_key']) || ($rank[$i['role']] ?? 0) < $rank[$role]) throw new ApiError(403, 'forbidden');
        return $i;
    }

    /** @return array<string,mixed> */
    public function channel(?string $slug = null): array
    {
        $slug ??= (string) ($this->req->input('channel') ?? $this->req->query['channel'] ?? '');
        $catalog = $this->app->catalog();
        $c = $slug !== '' ? $catalog->channelBySlug($slug) : $catalog->mainChannel();
        if ($c === null || !(int) $c['active']) throw new ApiError(404, 'unknown_channel');
        return $c;
    }

    /** @return array<string,mixed> */
    public function channelById(int $id): array
    {
        return $this->app->catalog()->channel($id) ?? throw new ApiError(404, 'unknown_channel');
    }

    public function actor(): string
    {
        $i = $this->identity();
        return $i === null ? 'anonymous' : 'user:' . $i['public_id'];
    }
}

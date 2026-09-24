<?php
declare(strict_types=1);

namespace Arche\Identity;

use Arche\ApiError;
use Arche\App;
use Arche\Support\Ids;

/**
 * Anonymous-first identity.
 *
 * Every install mints a device id + secret locally and sends them as
 * X-Arche-Id / X-Arche-Secret. The server keeps only HMACs (with a pepper) and
 * creates the row lazily, on the first request that needs one. A 12-word
 * passphrase is an optional *credential* attached to an identity later:
 *
 *   claim  (device A, new phrase)  → the credential is stored on A's row
 *   login  (device B, same phrase) → B's row becomes an alias of A's
 *
 * Rows never move; everything resolves through `canonical_id` (one hop). The
 * phrase itself never reaches the server — the client derives credId and
 * credSecret from it (BIP39 seed), and the secret is kept only as Argon2id,
 * checked at claim/login, never per request. Roles are granted only to
 * identities that have a credential, so a cleared browser cannot take a
 * moderator's rights with it or hand them to someone else.
 */
final class Identities
{
    public function __construct(private App $app) {}

    public function pepper(): string
    {
        $p = $this->app->config->get('IDENTITY_PEPPER');
        if ($p !== '') return $p;
        // Dev convenience only: a stable per-database pepper. Production gets
        // one from `npm run init-secrets`.
        $stored = $this->app->store()->get('identity_pepper');
        if (is_string($stored)) return $stored;
        $fresh = Ids::hex(32);
        $this->app->store()->set('identity_pepper', $fresh);
        return $fresh;
    }

    private function mac(string $kind, string $value): string
    {
        return hash_hmac('sha256', $kind . ':' . $value, $this->pepper());
    }

    public static function validDevice(string $id, string $secret): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id)
            && (bool) preg_match('/^[0-9a-f]{64}$/', $secret);
    }

    /** The device's key for presence and reactions — no row needed. */
    public function deviceKey(string $deviceId): string
    {
        return substr($this->mac('device', $deviceId), 0, 32);
    }

    /**
     * The canonical identity for a device, or null for an unknown device when
     * $create is false. A known device with the wrong secret is refused.
     *
     * @return array<string,mixed>|null
     */
    public function resolve(string $deviceId, string $secret, bool $create): ?array
    {
        if (!self::validDevice($deviceId, $secret)) throw new ApiError(401, 'bad_identity');
        $store = $this->app->store();
        $key = $this->mac('device', $deviceId);
        $row = $store->one('SELECT * FROM identities WHERE device_key = ?', [$key]);
        if ($row !== null) {
            if (!hash_equals((string) $row['device_secret'], $this->mac('secret', $secret))) throw new ApiError(401, 'bad_identity');
            $now = $this->app->clock->now();
            if ($now - (int) $row['last_seen'] > 300) $store->update('identities', ['last_seen' => $now], 'id = ?', [$row['id']]);
            return $this->canonical($row);
        }
        if (!$create) return null;
        // New identities per address and day: enough to stop a script filling
        // the table, not so few that a church Wi-Fi or a mobile carrier's
        // shared address (CGNAT) locks real listeners out.
        $perDay = $this->app->config->int('IDENTITIES_PER_IP_DAY', 300);
        if (!$this->app->rateLimit()->hit('ident:' . $this->app->rateLimit()->ipKey(), $perDay, 86400)) throw new ApiError(429, 'rate_limited');
        $now = $this->app->clock->now();
        $id = $store->insert('identities', [
            'public_id' => Ids::short(10),
            'device_key' => $key,
            'device_secret' => $this->mac('secret', $secret),
            'created' => $now,
            'last_seen' => $now,
        ]);
        return $this->get($id);
    }

    /** @return array<string,mixed>|null */
    public function get(int $id): ?array
    {
        $row = $this->app->store()->one('SELECT * FROM identities WHERE id = ?', [$id]);
        return $row === null ? null : $this->canonical($row);
    }

    /** @return array<string,mixed>|null */
    public function byPublicId(string $publicId): ?array
    {
        $row = $this->app->store()->one('SELECT * FROM identities WHERE public_id = ?', [$publicId]);
        return $row === null ? null : $this->canonical($row);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function canonical(array $row): array
    {
        if ($row['canonical_id'] !== null) {
            $root = $this->app->store()->one('SELECT * FROM identities WHERE id = ?', [(int) $row['canonical_id']]);
            if ($root !== null) $row = $root;
        }
        $row['id'] = (int) $row['id'];
        $row['banned'] = (int) $row['banned'];
        return $row;
    }

    /**
     * Attach a passphrase credential to the calling device's identity.
     *
     * @param array<string,mixed> $identity canonical identity of the device
     * @return array<string,mixed>
     */
    public function claim(array $identity, string $credId, string $credSecret): array
    {
        self::checkCredential($credId, $credSecret);
        if (!empty($identity['cred_key'])) throw new ApiError(409, 'already_claimed');
        $key = $this->mac('cred', $credId);
        $store = $this->app->store();
        if ($store->one('SELECT id FROM identities WHERE cred_key = ?', [$key])) throw new ApiError(409, 'passphrase_in_use');
        $store->update('identities', [
            'cred_key' => $key,
            'cred_hash' => password_hash($credSecret, PASSWORD_ARGON2ID),
        ], 'id = ?', [$identity['id']]);
        $store->audit('identity', 'Passphrase claimed', (string) $identity['public_id']);
        return $this->get((int) $identity['id']) ?? $identity;
    }

    /**
     * Log a device in with a passphrase: the device's own row becomes an alias
     * of the passphrase identity. Its earlier submissions stay where they are.
     *
     * @return array<string,mixed> the passphrase identity
     */
    public function login(string $deviceId, string $deviceSecret, string $credId, string $credSecret): array
    {
        self::checkCredential($credId, $credSecret);
        $limiter = $this->app->rateLimit();
        if (!$limiter->hit('login:' . $limiter->ipKey(), 10, 3600)) throw new ApiError(429, 'rate_limited');
        $store = $this->app->store();
        $root = $store->one('SELECT * FROM identities WHERE cred_key = ?', [$this->mac('cred', $credId)]);
        if ($root === null || !password_verify($credSecret, (string) $root['cred_hash'])) throw new ApiError(401, 'unknown_passphrase');
        $device = $this->resolve($deviceId, $deviceSecret, true);
        $deviceRow = $store->one('SELECT * FROM identities WHERE device_key = ?', [$this->mac('device', $deviceId)]);
        if ($deviceRow !== null && (int) $deviceRow['id'] !== (int) $root['id']) {
            if (!empty($deviceRow['cred_key'])) throw new ApiError(409, 'device_has_other_passphrase');
            $store->update('identities', ['canonical_id' => (int) $root['id']], 'id = ?', [$deviceRow['id']]);
            // Anything aliased to the device row follows it to the root.
            $store->update('identities', ['canonical_id' => (int) $root['id']], 'canonical_id = ?', [$deviceRow['id']]);
        }
        unset($device);
        $store->audit('identity', 'Passphrase login', (string) $root['public_id']);
        return $this->get((int) $root['id']) ?? throw new ApiError(500, 'identity_lost');
    }

    private static function checkCredential(string $credId, string $credSecret): void
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-8[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $credId)
            || !preg_match('/^[0-9a-f]{64}$/', $credSecret)) {
            throw new ApiError(422, 'bad_credential');
        }
    }

    /** @param array<string,mixed> $identity @param array<string,mixed> $fields @return array<string,mixed> */
    public function update(array $identity, array $fields): array
    {
        $set = [];
        if (array_key_exists('name', $fields)) {
            $name = trim((string) preg_replace('/[\p{C}<>]/u', '', (string) $fields['name']));
            if (mb_strlen($name) > 30) throw new ApiError(422, 'name_too_long');
            $set['display_name'] = $name;
        }
        if (array_key_exists('country', $fields)) {
            $c = strtoupper(trim((string) $fields['country']));
            if ($c !== '' && !preg_match('/^[A-Z]{2}$/', $c)) throw new ApiError(422, 'bad_country');
            $set['country'] = $c;
        }
        if (array_key_exists('lang', $fields)) {
            $set['lang'] = in_array($fields['lang'], ['en', 'de'], true) ? $fields['lang'] : 'en';
        }
        if ($set) $this->app->store()->update('identities', $set, 'id = ?', [$identity['id']]);
        return $this->get((int) $identity['id']) ?? $identity;
    }

    public function setRole(string $publicId, string $role, string $actor): array
    {
        if (!in_array($role, ['listener', 'moderator', 'admin'], true)) throw new ApiError(422, 'bad_role');
        $identity = $this->byPublicId($publicId) ?? throw new ApiError(404, 'not_found');
        if ($role !== 'listener' && empty($identity['cred_key'])) throw new ApiError(409, 'needs_passphrase');
        $this->app->store()->update('identities', ['role' => $role], 'id = ?', [$identity['id']]);
        $this->app->store()->audit($actor, 'Role changed', $publicId . ' → ' . $role);
        return $this->get((int) $identity['id']) ?? $identity;
    }

    public function setBanned(string $publicId, bool $banned, string $actor): array
    {
        $identity = $this->byPublicId($publicId) ?? throw new ApiError(404, 'not_found');
        if ($identity['role'] === 'admin') throw new ApiError(409, 'cannot_ban_admin');
        $this->app->store()->update('identities', ['banned' => $banned ? 1 : 0], 'id = ? OR canonical_id = ?', [$identity['id'], $identity['id']]);
        $this->app->store()->audit($actor, $banned ? 'Banned' : 'Unbanned', $publicId);
        return $this->get((int) $identity['id']) ?? $identity;
    }

    /** @return list<string> public ids that are banned (for realtime nodes) */
    public function bannedIds(): array
    {
        return array_map(fn($r) => (string) $r['public_id'], $this->app->store()->all('SELECT public_id FROM identities WHERE banned = 1 AND canonical_id IS NULL'));
    }

    /**
     * What the client may see about an identity: never the internal id, never
     * the HMACs.
     *
     * @param array<string,mixed> $i
     * @return array<string,mixed>
     */
    public static function publicView(array $i): array
    {
        return [
            'id' => (string) $i['public_id'],
            'name' => (string) $i['display_name'],
            'country' => (string) $i['country'],
            'lang' => (string) $i['lang'],
            'role' => (string) $i['role'],
            'claimed' => !empty($i['cred_key']),
            'banned' => (bool) $i['banned'],
        ];
    }

    /** Unclaimed devices silent for 60 days, with nothing that points at them. */
    public function purgeInactive(int $days = 60): int
    {
        return $this->app->store()->query(
            'DELETE FROM identities WHERE cred_key IS NULL AND canonical_id IS NULL AND last_seen < ?
             AND id NOT IN (SELECT identity_id FROM submissions) AND id NOT IN (SELECT canonical_id FROM identities WHERE canonical_id IS NOT NULL)',
            [$this->app->clock->now() - $days * 86400],
        )->rowCount();
    }
}

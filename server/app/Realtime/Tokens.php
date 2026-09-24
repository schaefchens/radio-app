<?php
declare(strict_types=1);

namespace Arche\Realtime;

use Arche\ApiError;
use Arche\App;
use Arche\Identity\Identities;

/**
 * Join tokens for the realtime nodes: Ed25519-signed by PHP, verified by the
 * node with the public key only (a compromised node cannot mint tokens).
 * Wire form: base64url(json) "." base64url(signature over the first part).
 */
final class Tokens
{
    public function __construct(private App $app) {}

    private static function b64(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private static function unb64(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }

    private function secretKey(): string
    {
        $sk = base64_decode($this->app->config->get('TOKEN_SIGNING_KEY'), true);
        if ($sk === false || strlen($sk) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) throw new ApiError(503, 'realtime_not_configured');
        return $sk;
    }

    /** @param array<string,mixed> $identity */
    public function issue(array $identity, string $channel, int $ttl = 600): string
    {
        $now = $this->app->clock->now();
        $view = Identities::publicView($identity);
        $payload = [
            'v' => 1,
            'sub' => $view['id'],
            'name' => $view['name'] !== '' ? $view['name'] : 'Listener',
            'country' => $view['country'],
            'lang' => $view['lang'],
            'role' => $view['role'],
            'ch' => $channel,
            'iat' => $now,
            'exp' => $now + $ttl,
        ];
        $p = self::b64(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return $p . '.' . self::b64(sodium_crypto_sign_detached($p, $this->secretKey()));
    }

    /** Verification, as the node does it — for tests and diagnostics. @return array<string,mixed>|null */
    public function verify(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) return null;
        $pk = sodium_crypto_sign_publickey_from_secretkey($this->secretKey());
        if (!sodium_crypto_sign_verify_detached(self::unb64($parts[1]), $parts[0], $pk)) return null;
        $data = json_decode(self::unb64($parts[0]), true);
        return is_array($data) && ($data['exp'] ?? 0) > $this->app->clock->now() ? $data : null;
    }
}

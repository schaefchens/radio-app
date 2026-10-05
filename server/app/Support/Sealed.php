<?php
declare(strict_types=1);

namespace Arche\Support;

/**
 * Secrets the database keeps for us — a host's own API key — sealed with a
 * key derived from the identity pepper (sodium secretbox). The daily
 * `VACUUM INTO` copies then hold nothing readable, and neither does a leaked
 * database without the .env. The pepper cannot change anyway (every device
 * id hangs on it); if it ever did, a sealed value no longer opens and the
 * host asks for its key again. In dev the pepper may live in the database
 * itself (Identities::pepper()), which makes this an obfuscation there.
 */
final class Sealed
{
    private const PREFIX = 'v1:';

    public function __construct(#[\SensitiveParameter] private string $pepper) {}

    public function seal(#[\SensitiveParameter] string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key()));
    }

    /** The plain value, or null for anything that does not open (tampered, another pepper, not sealed). */
    public function open(string $sealed): ?string
    {
        if (!str_starts_with($sealed, self::PREFIX)) return null;
        $raw = base64_decode(substr($sealed, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return null;
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key(),
        );
        return $plain === false ? null : $plain;
    }

    private function key(): string
    {
        return hash_hmac('sha256', 'arche/host-api-key/v1', $this->pepper, true);
    }
}

<?php
declare(strict_types=1);

namespace Arche\Ai;

use Arche\Support\HttpResponse;

/**
 * A voice provider said no (or did not answer). The message is short and
 * never carries a key: it ends up in jobs.last_error, hosts.last_error and
 * the audit log moderators read.
 */
final class VoiceError extends \RuntimeException
{
    /** Provider codes that mean the account or the setup, not the moment. */
    private const LASTING_CODES = ['quota_exceeded', 'insufficient_quota', 'invalid_api_key', 'missing_permissions',
        'voice_not_found', 'model_not_found', 'payment_required', 'detected_unusual_activity', 'max_character_limit_exceeded'];

    public function __construct(
        public readonly string $provider,
        public readonly int $status,
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * Whether waiting a few minutes cannot help: a quota used up, a key that
     * is wrong or lacks a permission, a voice or model the provider does not
     * know. A 429 is temporary unless its code says the quota is gone.
     */
    public function lasting(): bool
    {
        if (in_array($this->reason, self::LASTING_CODES, true)) return true;
        return in_array($this->status, [400, 401, 402, 403, 404, 422], true);
    }

    public static function fromResponse(string $provider, HttpResponse $r, #[\SensitiveParameter] string $key = ''): self
    {
        $data = $r->json() ?? [];
        // OpenAI: {error: {code, type, message}}; ElevenLabs: {detail: {status|code, message}}
        // or, for a request it could not read, {detail: [{type, msg}]}.
        $err = is_array($data['error'] ?? null) ? $data['error'] : [];
        $detail = $data['detail'] ?? null;
        $first = is_array($detail) && array_is_list($detail) ? ($detail[0] ?? []) : (is_array($detail) ? $detail : []);
        $reason = (string) ($err['code'] ?? $err['type'] ?? $first['status'] ?? $first['code'] ?? $first['type'] ?? '');
        $said = (string) ($err['message'] ?? $first['message'] ?? $first['msg'] ?? (is_string($detail) ? $detail : ''));
        $label = $provider === 'elevenlabs' ? 'ElevenLabs' : 'OpenAI';
        $text = trim("$label HTTP {$r->status} " . $reason . ($said !== '' ? ': ' . $said : ''));
        return new self($provider, $r->status, preg_replace('/[^a-z0-9_.-]/i', '', $reason) ?? '', self::redact($text, $key));
    }

    /** No answer, or one that was not audio. */
    public static function broken(string $provider, string $why, #[\SensitiveParameter] string $key = ''): self
    {
        $label = $provider === 'elevenlabs' ? 'ElevenLabs' : 'OpenAI';
        return new self($provider, 0, 'no_answer', self::redact("$label: $why", $key));
    }

    /** At most 200 characters, without the key or anything shaped like one (OpenAI echoes a masked key). */
    public static function redact(string $text, #[\SensitiveParameter] string $key = ''): string
    {
        if ($key !== '') $text = str_replace($key, '[key]', $text);
        // OpenAI's sk-…/sk-proj-…, ElevenLabs' sk_… and its older bare 32 hex digits.
        $text = (string) preg_replace(['/\bsk[-_][A-Za-z0-9*_-]{12,}/', '/\b[a-f0-9]{32,}\b/'], '[key]', $text);
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $text)), 0, 200);
    }
}

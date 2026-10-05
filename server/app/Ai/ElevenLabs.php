<?php
declare(strict_types=1);

namespace Arche\Ai;

use Arche\App;

/**
 * ElevenLabs, for hosts that speak with it: the clip itself (one network call,
 * like every job phase), and for /mod the account's voices, models and the
 * characters left. Never called in stub mode (Voice) — the station's own free
 * account is a small one, and dev and tests must not use it up.
 */
final class ElevenLabs
{
    private const BASE = 'https://api.elevenlabs.io';

    public function __construct(private App $app) {}

    /**
     * @param array<string,mixed> $settings Hosts::settings() for ElevenLabs
     * @return string mp3 bytes
     */
    public function speech(#[\SensitiveParameter] string $key, string $model, string $voice, string $text, string $lang, array $settings): string
    {
        $body = [
            'text' => $text,
            'model_id' => $model,
            'voice_settings' => [
                'stability' => (float) $settings['stability'],
                'similarity_boost' => (float) $settings['similarity'],
                'style' => (float) $settings['style'],
                'use_speaker_boost' => (bool) $settings['speaker_boost'],
                'speed' => (float) $settings['speed'],
            ],
        ];
        // Some models refuse a language code (eleven_multilingual_v2 answers 400): a setting.
        if ($settings['language']) $body['language_code'] = $lang;
        $r = $this->app->http()->postJson(
            self::BASE . '/v1/text-to-speech/' . rawurlencode($voice) . '?output_format=mp3_44100_128',
            $body,
            ['xi-api-key' => $key, 'Accept' => 'audio/mpeg'],
            30,
        );
        if ($r->status !== 200) throw VoiceError::fromResponse('elevenlabs', $r, $key);
        if ($r->body === '') throw VoiceError::broken('elevenlabs', 'an empty answer', $key);
        return $r->body;
    }

    /**
     * The voices the account can use (its own, saved and premade ones), for
     * the host editor's list.
     *
     * @return list<array{id:string,name:string,category:string,labels:string,languages:list<string>}>
     */
    public function voices(#[\SensitiveParameter] string $key): array
    {
        $out = [];
        $token = '';
        // Three pages at most: an account with hundreds of saved voices still answers in seconds.
        for ($page = 0; $page < 3; $page++) {
            $r = $this->app->http()->get(self::BASE . '/v2/voices?page_size=100' . ($token !== '' ? '&next_page_token=' . rawurlencode($token) : ''), ['xi-api-key' => $key], 10);
            if ($r->status !== 200) throw VoiceError::fromResponse('elevenlabs', $r, $key);
            $data = $r->json() ?? [];
            foreach ((array) ($data['voices'] ?? []) as $v) {
                if (!is_array($v) || !is_string($v['voice_id'] ?? null)) continue;
                $labels = array_filter(array_map('strval', array_intersect_key((array) ($v['labels'] ?? []), array_flip(['accent', 'gender', 'age', 'descriptive', 'use_case']))));
                $langs = [];
                foreach ((array) ($v['verified_languages'] ?? []) as $l) {
                    if (is_array($l) && is_string($l['language'] ?? null)) $langs[strtolower(substr($l['language'], 0, 2))] = true;
                }
                $out[] = [
                    'id' => $v['voice_id'],
                    'name' => mb_substr((string) ($v['name'] ?? $v['voice_id']), 0, 80),
                    'category' => (string) ($v['category'] ?? ''),
                    'labels' => mb_substr(implode(' · ', $labels), 0, 120),
                    'languages' => array_keys($langs),
                ];
            }
            $token = (string) ($data['next_page_token'] ?? '');
            if (empty($data['has_more']) || $token === '') break;
        }
        return $out;
    }

    /** @return list<array{id:string,name:string,languages:list<string>,cost:float}> models that speak */
    public function models(#[\SensitiveParameter] string $key): array
    {
        $r = $this->app->http()->get(self::BASE . '/v1/models', ['xi-api-key' => $key], 10);
        if ($r->status !== 200) throw VoiceError::fromResponse('elevenlabs', $r, $key);
        $out = [];
        foreach ((array) ($r->json() ?? []) as $m) {
            if (!is_array($m) || !is_string($m['model_id'] ?? null) || ($m['can_do_text_to_speech'] ?? true) === false) continue;
            $langs = [];
            foreach ((array) ($m['languages'] ?? []) as $l) {
                if (is_array($l) && is_string($l['language_id'] ?? null)) $langs[strtolower(substr($l['language_id'], 0, 2))] = true;
            }
            $out[] = [
                'id' => $m['model_id'],
                'name' => mb_substr((string) ($m['name'] ?? $m['model_id']), 0, 80),
                'languages' => array_keys($langs),
                'cost' => (float) ($m['model_rates']['character_cost_multiplier'] ?? $m['token_cost_factor'] ?? 1),
            ];
        }
        return $out;
    }

    /**
     * What the plan allows this month: characters used and the limit, and
     * when it starts over. Null when the key may not read it (a restricted key).
     *
     * @return array{used:int,limit:int,resets:int,tier:string}|null
     */
    public function account(#[\SensitiveParameter] string $key): ?array
    {
        $r = $this->app->http()->get(self::BASE . '/v1/user/subscription', ['xi-api-key' => $key], 10);
        if ($r->status !== 200) return null;
        $d = $r->json() ?? [];
        return [
            'used' => (int) ($d['character_count'] ?? 0),
            'limit' => (int) ($d['character_limit'] ?? 0),
            'resets' => (int) ($d['next_character_count_reset_unix'] ?? 0),
            'tier' => (string) ($d['tier'] ?? ''),
        ];
    }
}

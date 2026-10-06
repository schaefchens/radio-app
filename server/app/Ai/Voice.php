<?php
declare(strict_types=1);

namespace Arche\Ai;

use Arche\App;
use Arche\Host\Hosts;
use Arche\Support\BudgetExceeded;

/**
 * One clip in a host's voice (Host\Hosts): OpenAI or ElevenLabs, with the
 * host's model, voice, direction, settings and key. In stub mode no provider
 * is called — every host speaks the bundled clip — so dev, tests and e2e
 * never spend a character of the station's small ElevenLabs account.
 *
 * Usage is recorded per host and day (`tts:host:<id>`, characters as input
 * "tokens"): what a host's daily cap counts. OpenAI's cost counts against
 * AI_DAILY_BUDGET_USD whoever's key it is; ElevenLabs is paid in characters.
 */
final class Voice
{
    /** Micro-USD per character, by OpenAI model (gpt-4o-mini-tts ≈ $0.015 a minute ≈ 900 characters). */
    private const OPENAI_MICROS = ['gpt-4o-mini-tts' => 17, 'tts-1-hd' => 30, 'tts-1' => 15];
    /** A model not listed is priced high on purpose: the budget must not be too generous. */
    private const OPENAI_UNKNOWN = 30;

    public function __construct(private App $app) {}

    /**
     * @param array<string,mixed> $host decoded (Hosts::get), or a draft from /mod's "Try voice"
     * @param ?string $key the key to use instead of the host's (an unsaved one being tried)
     * @param ?string $usageKind what it counts as (Host\Lines records under its own kind,
     *                           never against the host's daily cap for moments on air)
     * @return array{bytes:string,provider:string}
     * @throws VoiceError the provider said no, or did not answer
     * @throws BudgetExceeded the tick's time ran out mid-call (not the host's fault)
     */
    public function speak(array $host, string $text, string $lang, #[\SensitiveParameter] ?string $key = null, ?string $usageKind = null): array
    {
        $provider = (string) $host['provider'];
        // Asked of a worker as a task (Host\Workers::request): a call here would go to OpenAI by mistake.
        if (self::async($host)) throw new \LogicException('A worker voice is a task, not a call');
        $voice = Hosts::voiceFor($host, $lang);
        $settings = Hosts::settings($provider, (array) $host['settings']);
        if ($this->app->config->stubAi()) {
            $bytes = $this->app->openai()->speech('', 'stub', $voice ?: 'coral', $text, '');
            $this->record($host, $text, 0, $usageKind);
            return ['bytes' => $bytes, 'provider' => 'stub'];
        }
        $key ??= $this->app->hosts()->key($host);
        if ($key === '') throw new VoiceError($provider, 0, 'no_key', ($provider === 'elevenlabs' ? 'ElevenLabs' : 'OpenAI') . ': no key');
        if ($voice === '') throw new VoiceError($provider, 0, 'voice_not_found', ($provider === 'elevenlabs' ? 'ElevenLabs' : 'OpenAI') . ': no voice');
        try {
            $bytes = $provider === 'elevenlabs'
                ? $this->app->elevenLabs()->speech($key, (string) $host['model'], $voice, $text, $lang, $settings)
                : $this->app->openai()->speech($key, (string) $host['model'], $voice, $text, $this->direction($host, $lang), (float) $settings['speed']);
        } catch (VoiceError | BudgetExceeded $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            // The call's timeout is what was left of the tick: cut short, it is the tick's.
            if ($this->app->budget->left() < 1.5) throw new BudgetExceeded('Time budget used up during a voice call; the next tick continues.');
            throw VoiceError::broken($provider, 'no answer (' . $e->getMessage() . ')', $key);
        }
        $this->record($host, $text, $provider === 'openai' ? self::micros((string) $host['model']) : 0, $usageKind);
        return ['bytes' => $bytes, 'provider' => $provider];
    }

    /** Whether this host's clips come from a worker (Host\Workers): queued, not called. @param array<string,mixed> $host */
    public static function async(array $host): bool
    {
        return ($host['provider'] ?? '') === 'worker';
    }

    /**
     * What OpenAI's gpt-4o-mini-tts is told besides the text: the language
     * (spoken natively, never with the other one's accent), then the host's
     * own direction.
     *
     * @param array<string,mixed> $host
     */
    private function direction(array $host, string $lang): string
    {
        $line = $lang === 'de' ? 'Sprich natürliches Deutsch.' : 'Speak natural English.';
        return trim($line . ' ' . trim((string) ($host['instructions'] ?? '')));
    }

    private static function micros(string $model): int
    {
        foreach (self::OPENAI_MICROS as $name => $micros) {
            if ($model === $name || str_starts_with($model, $name . '-')) return $micros;
        }
        return self::OPENAI_UNKNOWN;
    }

    /** @param array<string,mixed> $host */
    private function record(array $host, string $text, int $microsPerChar, ?string $kind = null): void
    {
        $chars = mb_strlen($text);
        if ((int) ($host['id'] ?? 0) <= 0) return;
        $this->app->usage()->record($kind ?? Hosts::usageKind((int) $host['id']), $chars, 0, $chars * $microsPerChar);
    }
}

<?php
declare(strict_types=1);

namespace Arche\Ai;

use Arche\App;

/**
 * Picks the text-to-speech provider for one clip.
 *
 * OpenAI is the default. ElevenLabs is strictly opt-in: TTS_PROVIDER must say
 * so, a voice id must exist for the language, and the day's characters must
 * stay under ELEVENLABS_MAX_CHARS_PER_DAY (0 = never). The account behind the
 * key is a free one whose quota a few host breaks would exhaust, so any
 * failure there falls back to OpenAI instead of costing a retry. In stub mode
 * neither is called.
 */
final class Voice
{
    public function __construct(private App $app) {}

    /**
     * @param array<string,mixed> $channel
     * @return array{bytes:string,provider:string}
     */
    public function speak(string $text, string $lang, array $channel, string $style): array
    {
        if ($this->elevenLabsAllowed($text, $lang)) {
            try {
                return ['bytes' => $this->elevenLabs($text, $lang), 'provider' => 'elevenlabs'];
            } catch (\Throwable $e) {
                $this->app->store()->audit('voice', 'ElevenLabs failed, using OpenAI', substr($e->getMessage(), 0, 200));
            }
        }
        $voice = (string) ($channel['host_voice_' . $lang] ?? 'coral') ?: 'coral';
        $instructions = trim(($lang === 'de'
            ? 'Sprich natürliches Deutsch, warm und ruhig, wie eine christliche Radiomoderatorin. '
            : 'Speak natural English, warm and calm, like a Christian radio host. ') . $style);
        return ['bytes' => $this->app->openai()->tts($text, $voice, $instructions), 'provider' => 'openai'];
    }

    /** Which voice a host break gets by default ('elevenlabs' only when fully opted in). */
    public function provider(): string
    {
        $c = $this->app->config;
        $optedIn = !$c->stubAi() && $c->get('TTS_PROVIDER') === 'elevenlabs' && $c->get('ELEVENLABS_API_KEY') !== ''
            && $c->int('ELEVENLABS_MAX_CHARS_PER_DAY', 0) > 0;
        return $optedIn ? 'elevenlabs' : ($c->stubAi() ? 'stub' : 'openai');
    }

    private function elevenLabsAllowed(string $text, string $lang): bool
    {
        $c = $this->app->config;
        if ($c->stubAi() || $c->get('TTS_PROVIDER') !== 'elevenlabs') return false;
        if ($c->get('ELEVENLABS_API_KEY') === '' || $c->get('ELEVENLABS_VOICE_' . strtoupper($lang)) === '') return false;
        $cap = $c->int('ELEVENLABS_MAX_CHARS_PER_DAY', 0);
        if ($cap <= 0) return false;
        $used = (int) $this->app->store()->value(
            "SELECT COALESCE(SUM(input_tokens), 0) FROM ai_usage WHERE day = ? AND kind = 'tts:elevenlabs'",
            [gmdate('Y-m-d', $this->app->clock->now())],
        );
        return $used + mb_strlen($text) <= $cap;
    }

    private function elevenLabs(string $text, string $lang): string
    {
        $c = $this->app->config;
        $voiceId = rawurlencode($c->get('ELEVENLABS_VOICE_' . strtoupper($lang)));
        $r = $this->app->http()->postJson(
            'https://api.elevenlabs.io/v1/text-to-speech/' . $voiceId . '?output_format=mp3_44100_128',
            ['text' => $text, 'model_id' => $c->get('ELEVENLABS_MODEL'), 'language_code' => $lang],
            ['xi-api-key' => $c->get('ELEVENLABS_API_KEY'), 'Accept' => 'audio/mpeg'],
            30,
        );
        if ($r->status !== 200 || $r->body === '') throw new \RuntimeException('ElevenLabs HTTP ' . $r->status);
        // Characters are what the quota counts; recorded as input "tokens".
        $this->app->usage()->record('tts:elevenlabs', mb_strlen($text), 0, 0);
        return $r->body;
    }
}

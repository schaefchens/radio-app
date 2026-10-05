<?php
declare(strict_types=1);

namespace Arche\Ai;

use Arche\App;

/**
 * Text-to-speech and transcription. Adapted from bible-assistant's curl
 * helpers, with its 120–180 s timeouts replaced by the tick budget: one TTS
 * clip is one job phase and must fit in what is left of the tick.
 */
class OpenAi
{
    public function __construct(protected App $app) {}

    protected function key(): string
    {
        return $this->app->config->get('OPENAI_KEY') ?: $this->app->config->get('OPENAI_API_KEY');
    }

    /** @return string mp3 bytes */
    public function tts(string $text, string $voice, string $instructions): string
    {
        if ($this->key() === '') throw new \RuntimeException('OPENAI_KEY missing');
        $r = $this->app->http()->postJson('https://api.openai.com/v1/audio/speech', [
            'model' => $this->app->config->get('TTS_MODEL'),
            'voice' => $voice,
            'input' => $text,
            'instructions' => $instructions,
            'response_format' => 'mp3',
        ], ['Authorization' => 'Bearer ' . $this->key()], 30);
        if ($r->status !== 200 || $r->body === '') {
            throw new \RuntimeException('TTS failed with HTTP ' . $r->status);
        }
        $this->app->usage()->recordTts($text);
        return $r->body;
    }

    /**
     * One clip in a host's voice, with the host's model and key (the
     * station's when the host has none). Usage is the caller's (Voice
     * records it per host). Voice direction is a gpt-4o-mini-tts thing: the
     * tts-1 models refuse `instructions`. A custom voice ("voice_…") goes as
     * an object.
     *
     * @return string mp3 bytes
     */
    public function speech(#[\SensitiveParameter] string $key, string $model, string $voice, string $text, string $instructions, float $speed = 1.0): string
    {
        $body = [
            'model' => $model,
            'voice' => str_starts_with($voice, 'voice_') ? ['id' => $voice] : $voice,
            'input' => $text,
            'response_format' => 'mp3',
        ];
        if ($instructions !== '' && !str_starts_with($model, 'tts-1')) $body['instructions'] = $instructions;
        if (abs($speed - 1.0) > 0.001) $body['speed'] = $speed;
        $r = $this->app->http()->postJson('https://api.openai.com/v1/audio/speech', $body, ['Authorization' => 'Bearer ' . $key], 30);
        if ($r->status !== 200) throw VoiceError::fromResponse('openai', $r, $key);
        if ($r->body === '') throw VoiceError::broken('openai', 'an empty answer', $key);
        return $r->body;
    }

    public function transcribe(string $file, string $lang): string
    {
        if ($this->key() === '') throw new \RuntimeException('OPENAI_KEY missing');
        $r = $this->app->http()->request('POST', 'https://api.openai.com/v1/audio/transcriptions', [
            'Authorization' => 'Bearer ' . $this->key(),
        ], [
            'model' => $this->app->config->get('STT_MODEL'),
            'language' => $lang,
            'response_format' => 'json',
            'file' => new \CURLFile($file, 'audio/mpeg', 'contribution.mp3'),
        ], 40);
        $data = $r->json();
        if ($r->status !== 200 || !is_array($data) || !isset($data['text'])) {
            throw new \RuntimeException('Transcription failed with HTTP ' . $r->status);
        }
        return trim((string) $data['text']);
    }
}

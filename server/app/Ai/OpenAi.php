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

<?php
declare(strict_types=1);

namespace Arche\Ai;

/** AI_MODE=stub: a bundled short MP3 for every voice, a fixed transcript. */
final class StubOpenAi extends OpenAi
{
    public int $ttsCalls = 0;

    public function tts(string $text, string $voice, string $instructions): string
    {
        $this->ttsCalls++;
        $file = $this->app->config->root . '/resources/stub-voice.mp3';
        $bytes = is_file($file) ? file_get_contents($file) : false;
        if ($bytes === false) throw new \RuntimeException('resources/stub-voice.mp3 missing');
        return $bytes;
    }

    public function transcribe(string $file, string $lang): string
    {
        return $lang === 'de'
            ? 'Ich möchte erzählen, wie Gott mir in einer schweren Zeit geholfen hat.'
            : 'I want to share how God helped me through a hard time.';
    }
}

<?php
declare(strict_types=1);

namespace Arche\Audio;

/**
 * MP3 inspection without ffmpeg (the webhosting has none): getID3 reads the
 * frame headers, which is enough for the duration and to refuse anything that
 * is not really an MP3.
 */
final class Mp3
{
    /** @return array{ok:bool,ms:int,error:string} */
    public static function inspect(string $file): array
    {
        if (!is_file($file) || filesize($file) < 128) return ['ok' => false, 'ms' => 0, 'error' => 'empty'];
        $info = (new \getID3())->analyze($file);
        $format = $info['fileformat'] ?? '';
        $ms = (int) round(((float) ($info['playtime_seconds'] ?? 0)) * 1000);
        if ($format !== 'mp3' || $ms <= 0) return ['ok' => false, 'ms' => 0, 'error' => 'not_mp3'];
        return ['ok' => true, 'ms' => $ms, 'error' => ''];
    }
}

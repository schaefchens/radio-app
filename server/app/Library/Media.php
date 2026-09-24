<?php
declare(strict_types=1);

namespace Arche\Library;

use Arche\ApiError;
use Arche\App;
use Arche\Support\Files;

/**
 * Files the program points at under /media: host audio, jingles, listener
 * contributions, stage images and cached thumbnails.
 *
 * Every name is ours (<hash>.<ext>), never an uploaded name, and images are
 * re-encoded through GD so nothing but pixels reaches the web root. Unapproved
 * uploads stay in the private data dir until moderation publishes them.
 */
final class Media
{
    public function __construct(private App $app) {}

    public function dir(string $sub = ''): string
    {
        return rtrim($this->app->publicPath('media/' . $sub), '/');
    }

    /** Write $bytes under /media/$sub/$name and return its URL path. */
    public function put(string $sub, string $name, string $bytes): string
    {
        // Mixed case: YouTube ids name the thumbnails.
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{2,80}$/', $name) || preg_match('/\.ph/i', $name)) {
            throw new \InvalidArgumentException('Unsafe media name');
        }
        Files::write($this->dir($sub) . '/' . $name, $bytes);
        return '/media/' . trim($sub, '/') . '/' . $name;
    }

    public function path(string $url): ?string
    {
        if (!str_starts_with($url, '/media/') || str_contains($url, '..')) return null;
        return $this->app->publicPath(ltrim($url, '/'));
    }

    public function delete(?string $url): void
    {
        if ($url === null) return;
        $p = $this->path($url);
        if ($p !== null && is_file($p)) @unlink($p);
        // Gone here means gone at the edge too (queued; purged in the jobs phase).
        $this->app->cdn()->forget($url);
    }

    /**
     * Cache a YouTube thumbnail locally (served from our origin, so a page
     * that has not been joined yet loads nothing from Google — GDPR).
     */
    public function cacheThumb(string $ytId): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $ytId)) return null;
        $name = $ytId . '.jpg';
        if (is_file($this->dir('thumbs') . '/' . $name)) return '/media/thumbs/' . $name;
        // Not tied to AI_MODE: thumbnails are no AI cost. Tests switch them off.
        if (!$this->app->config->bool('THUMBS', true)) return null;
        try {
            $r = $this->app->http()->get('https://i.ytimg.com/vi/' . $ytId . '/hqdefault.jpg', [], 8);
        } catch (\Throwable) {
            return null;
        }
        if ($r->status !== 200) return null;
        $jpg = self::reencode($r->body, 320, 180, 'jpg');
        return $jpg === null ? null : $this->put('thumbs', $name, $jpg);
    }

    /** Store an uploaded stage image (any GD-readable format) as WebP. */
    public function storeImage(string $tmpFile, string $sub, int $w, int $h): string
    {
        $bytes = @file_get_contents($tmpFile);
        if ($bytes === false || strlen($bytes) > 8_000_000) throw new ApiError(422, 'invalid_image');
        $webp = self::reencode($bytes, $w, $h, 'webp');
        if ($webp === null) throw new ApiError(422, 'invalid_image');
        return $this->put($sub, substr(hash('sha256', $webp), 0, 16) . '.webp', $webp);
    }

    /** Cover-crop to $w × $h and encode. Returns null for anything GD rejects. */
    public static function reencode(string $bytes, int $w, int $h, string $format): ?string
    {
        $src = @imagecreatefromstring($bytes);
        if ($src === false) return null;
        $sw = imagesx($src);
        $sh = imagesy($src);
        $scale = max($w / $sw, $h / $sh);
        $cw = (int) round($w / $scale);
        $ch = (int) round($h / $scale);
        $dst = imagecreatetruecolor($w, $h);
        imagecopyresampled($dst, $src, 0, 0, intdiv($sw - $cw, 2), intdiv($sh - $ch, 2), $w, $h, $cw, $ch);
        ob_start();
        $ok = $format === 'webp' ? imagewebp($dst, null, 82) : imagejpeg($dst, null, 82);
        $out = (string) ob_get_clean();
        return $ok && $out !== '' ? $out : null;
    }
}

<?php
declare(strict_types=1);

namespace Arche\Http;

final class Response
{
    /** @param array<string,mixed>|list<mixed> $data @param array<string,string> $headers */
    public function __construct(
        public readonly array $data,
        public readonly int $status = 200,
        public readonly array $headers = [],
        /** A private file sent as the body instead of JSON. */
        public readonly ?string $file = null,
    ) {}

    /** A file from the private data dir, for the requester only (never cached). */
    public static function file(string $path, string $contentType): self
    {
        return new self([], 200, ['Content-Type' => $contentType], $path);
    }

    public function send(): void
    {
        http_response_code($this->status);
        if ($this->file !== null) {
            header('Content-Type: ' . ($this->headers['Content-Type'] ?? 'application/octet-stream'));
            header('Cache-Control: private, no-store');
            header('X-Content-Type-Options: nosniff');
            header('Content-Disposition: inline');
            header('Content-Length: ' . (string) filesize($this->file));
            readfile($this->file);
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        foreach ($this->headers as $k => $v) header($k . ': ' . $v);
        $body = json_encode($this->data ?: new \stdClass(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        header('Content-Length: ' . strlen((string) $body));
        echo $body;
    }
}

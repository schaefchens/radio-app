<?php
declare(strict_types=1);

namespace Arche\Http;

use Arche\ApiError;

final class Request
{
    /** @var array<string,mixed>|null */
    private ?array $json = null;

    /**
     * @param array<string,string> $query
     * @param array<string,string> $headers lower-cased names
     * @param array<string,mixed> $post
     * @param array<string,mixed> $files
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $headers = [],
        public readonly string $body = '',
        public readonly array $post = [],
        public readonly array $files = [],
        public readonly string $ip = '',
    ) {}

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
        }
        // Under PHP-FPM behind Apache the Authorization header only arrives via
        // the SetEnvIf in /api/.htaccess and /.htaccess; both spellings are read.
        if (!isset($headers['authorization'])) {
            $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
            if ($auth !== '') $headers['authorization'] = (string) $auth;
        }
        if (isset($_SERVER['CONTENT_TYPE'])) $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');
        $body = '';
        if (!str_starts_with($headers['content-type'] ?? '', 'multipart/')) {
            $body = (string) file_get_contents('php://input', false, null, 0, 1_048_576);
        }
        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path,
            array_map('strval', $_GET),
            $headers,
            $body,
            $_POST,
            $_FILES,
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        );
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    /** @return array<string,mixed> */
    public function json(): array
    {
        if ($this->json !== null) return $this->json;
        if ($this->post) return $this->json = $this->post;
        if (trim($this->body) === '') return $this->json = [];
        $d = json_decode($this->body, true);
        if (!is_array($d)) throw new ApiError(400, 'bad_json');
        return $this->json = $d;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->json()[$key] ?? $default;
    }

    /** Path of an uploaded file, or null. */
    public function file(string $field): ?string
    {
        $f = $this->files[$field] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
        $tmp = (string) ($f['tmp_name'] ?? '');
        return $tmp !== '' && is_file($tmp) ? $tmp : null;
    }
}

<?php
declare(strict_types=1);

namespace Arche\Realtime;

use Arche\App;

/**
 * Minimal Hetzner Cloud API client (after enoch's Cloud.php): curl through the
 * app's HttpClient, so timeouts come from the tick budget; 204 on DELETE is a
 * success, a 4xx is an error the caller must not blindly retry.
 */
class HetznerCloud
{
    public function __construct(protected App $app) {}

    protected function token(): string
    {
        return $this->app->config->get('HETZNER_CLOUD_TOKEN');
    }

    public function configured(): bool
    {
        return $this->token() !== '';
    }

    /** @param array<string,mixed>|null $body @return array<string,mixed> */
    public function request(string $method, string $path, ?array $body = null): array
    {
        if (!$this->configured()) throw new \RuntimeException('HETZNER_CLOUD_TOKEN missing');
        $r = $this->app->http()->request($method, 'https://api.hetzner.cloud/v1' . $path, [
            'Authorization' => 'Bearer ' . $this->token(),
            'Content-Type' => 'application/json',
        ], $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 12);
        if ($r->status === 204) return [];
        $data = $r->json() ?? [];
        if ($r->status === 404 && $method === 'GET') return ['not_found' => true];
        if ($r->status < 200 || $r->status >= 300) {
            $code = preg_replace('/[^a-z0-9_]/i', '', (string) ($data['error']['code'] ?? 'request_failed'));
            throw new \RuntimeException("Hetzner HTTP {$r->status} ($code)");
        }
        return $data;
    }

    /** @return list<array<string,mixed>> */
    public function servers(string $labelSelector): array
    {
        return $this->request('GET', '/servers?' . http_build_query(['label_selector' => $labelSelector, 'per_page' => 50]))['servers'] ?? [];
    }

    /** Newest snapshot with these labels and the architecture of $serverType. @return array<string,mixed>|null */
    public function newestSnapshot(string $labelSelector, string $serverType): ?array
    {
        $arch = str_starts_with($serverType, 'cax') ? 'arm' : 'x86';
        $images = $this->request('GET', '/images?' . http_build_query([
            'type' => 'snapshot', 'label_selector' => $labelSelector, 'per_page' => 50, 'sort' => 'created:desc',
        ]))['images'] ?? [];
        foreach ($images as $img) {
            if (($img['status'] ?? '') === 'available' && ($img['architecture'] ?? $arch) === $arch) return $img;
        }
        return null;
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function createServer(array $body): array
    {
        return $this->request('POST', '/servers', $body)['server'] ?? [];
    }

    public function deleteServer(int $id): void
    {
        $this->request('DELETE', '/servers/' . $id);
    }
}

<?php
declare(strict_types=1);

namespace Arche\Support;

/**
 * The one place outbound HTTP happens (OpenAI, YouTube, Hetzner, oEmbed).
 * Timeouts always come from the Budget, and there are no silent retries: a
 * failed call fails the job phase, which the lease retries on a later tick.
 * Tests replace this with a fake.
 */
class HttpClient
{
    public function __construct(private Budget $budget) {}

    /**
     * @param array<string,string> $headers
     * @param string|array<string,mixed>|null $body array = multipart form
     */
    public function request(
        string $method,
        string $url,
        array $headers = [],
        string|array|null $body = null,
        int $timeout = 20,
    ): HttpResponse {
        $ch = curl_init($url);
        $h = [];
        foreach ($headers as $k => $v) $h[] = $k . ': ' . $v;
        $respHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(5, $this->budget->remaining($timeout)),
            CURLOPT_TIMEOUT => $this->budget->remaining($timeout),
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_HEADERFUNCTION => function ($_ch, string $line) use (&$respHeaders): int {
                $p = strpos($line, ':');
                if ($p !== false) $respHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                return strlen($line);
            },
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        if ($raw === false) {
            throw new \RuntimeException('HTTP request failed: ' . ($error ?: 'no response'));
        }
        return new HttpResponse($status, (string) $raw, $respHeaders);
    }

    /** @param array<string,mixed> $payload @param array<string,string> $headers */
    public function postJson(string $url, array $payload, array $headers = [], int $timeout = 20): HttpResponse
    {
        return $this->request('POST', $url, ['Content-Type' => 'application/json'] + $headers, Files::json($payload), $timeout);
    }

    /** @param array<string,string> $headers */
    public function get(string $url, array $headers = [], int $timeout = 10): HttpResponse
    {
        return $this->request('GET', $url, $headers, null, $timeout);
    }
}

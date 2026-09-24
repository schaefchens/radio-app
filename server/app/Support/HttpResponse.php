<?php
declare(strict_types=1);

namespace Arche\Support;

final class HttpResponse
{
    /** @param array<string,string> $headers lower-cased names */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
    ) {}

    /** @return array<mixed>|null */
    public function json(): ?array
    {
        $d = json_decode($this->body, true);
        return is_array($d) ? $d : null;
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}

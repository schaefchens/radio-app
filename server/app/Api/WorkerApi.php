<?php
declare(strict_types=1);

namespace Arche\Api;

use Arche\ApiError;
use Arche\Http\Context;

/**
 * What voice workers call (Host\Workers, worker/README.md): a Mac of our own
 * with no public address checks in for a task, uploads the clip or says it
 * could not. Its key comes in X-Arche-Worker-Key. None of these routes runs a
 * tick: a worker polls every few seconds, and the listeners' requests and the
 * cron keep the program going.
 */
final class WorkerApi
{
    public function __construct(private Context $c) {}

    /** @return array<string,mixed> */
    private function worker(): array
    {
        return $this->c->app->workers()->authenticate($this->c->req->headers, $this->c->req->ip);
    }

    public function poll(): array
    {
        $w = $this->worker();
        return $this->c->app->workers()->poll($w, $this->c->req->json());
    }

    /** Multipart, never JSON: a JSON body is cut at 1 MiB, a long clip is more. @param array<string,string> $a */
    public function audio(array $a): array
    {
        $w = $this->worker();
        return $this->c->app->workers()->complete($w, $this->taskId($a), $this->c->req->file('audio'));
    }

    /** @param array<string,string> $a */
    public function fail(array $a): array
    {
        $w = $this->worker();
        $in = $this->c->req->json();
        return $this->c->app->workers()->fail($w, $this->taskId($a), (string) ($in['error'] ?? ''), ($in['retry'] ?? true) !== false);
    }

    /** @param array<string,string> $a */
    private function taskId(array $a): int
    {
        $id = (int) ($a['id'] ?? 0);
        if ($id <= 0) throw new ApiError(404, 'not_found');
        return $id;
    }
}

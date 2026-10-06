<?php
declare(strict_types=1);

namespace Arche\Ai;

/**
 * AI_MODE=stub: no network, deterministic answers, so the whole pipeline can
 * run in tests and offline. A test can install its own responder per kind
 * (e.g. to make moderation reject) with `respond()`.
 */
final class StubText implements TextModel
{
    /** @var array<string,\Closure(string,string):?array<string,mixed>> */
    private array $responders = [];
    /** @var list<array{kind:string,user:string}> */
    public array $calls = [];

    /** @param \Closure(string $system, string $user):?array<string,mixed> $fn */
    public function respond(string $kind, \Closure $fn): void
    {
        $this->responders[$kind] = $fn;
    }

    public function provider(): string
    {
        return 'stub';
    }

    public function json(
        string $kind,
        string $role,
        string $system,
        string $user,
        array $schema,
        int $maxTokens = 4096,
        string $effort = 'low',
        int $timeout = 40,
    ): TextResult {
        $this->calls[] = ['kind' => $kind, 'user' => $user];
        if (isset($this->responders[$kind])) {
            $data = ($this->responders[$kind])($system, $user);
            // A test's own failure (a timeout is 'error'), or its answer.
            if ($data instanceof TextResult) return $data;
            return new TextResult($data, $data === null ? 'refusal' : 'ok', 'stub');
        }
        return new TextResult($this->default($kind, $user), 'ok', 'stub');
    }

    /** @return array<string,mixed> */
    private function default(string $kind, string $user): array
    {
        return match (true) {
            // Recorded lines (Host\Lines): as many as asked, each new (a line written twice is dropped).
            $kind === 'lines_write' => ['lines' => $this->lines($user)],
            $kind === 'lines_pick' => ['id' => $this->firstEnum($user)],
            str_starts_with($kind, 'host') => [
                'en' => ['text' => 'You are listening to ARCHE. Stay with us for more worship.'],
                'de' => ['text' => 'Ihr hört ARCHE. Bleibt dran für mehr Lobpreis.'],
            ],
            $kind === 'moderate_song', $kind === 'moderate_preaching', $kind === 'moderate_testimony_video', $kind === 'moderate_mission',
            $kind === 'moderate_film', $kind === 'moderate_audio', $kind === 'moderate_prayer', $kind === 'moderate_intercession' => [
                'safe' => true,
                'christian' => true,
                'program_fit' => true,
                'message_ok' => true,
                'verdict' => 'approve',
                'reason' => 'none',
                'themes' => ['worship'],
                'moods' => ['uplifting'],
                'languages' => ['en'],
                'caption_en' => 'A listener shares a story of faith.',
                'caption_de' => 'Ein Hörer erzählt von seinem Glauben.',
                'host_context' => 'A listener shares a story of faith.',
                'summary' => 'stub',
                'note' => 'Stub verdict.',
            ],
            $kind === 'moderate_highlights' => ['approved' => $this->idsIn($user)],
            default => [],
        };
    }

    /**
     * The lines a writer is asked for ("Write N …"), new each time: marked by
     * the prompt, which lists the lines written before (a request is its own
     * process on a web server, so a count of calls starts over every time).
     *
     * @return list<array<string,mixed>>
     */
    private function lines(string $user): array
    {
        $n = preg_match('/Write (\d+) different lines/', $user, $m) === 1 ? (int) $m[1] : 2;
        $call = substr(md5($user), 0, 6) . count($this->calls);
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = [
                'en' => ['text' => "Good to have you here with us on ARCHE, moment $call.$i."],
                'de' => ['text' => "Schön, dass du hier bei uns auf ARCHE bist, Moment $call.$i."],
                'time' => 'any',
                'mood' => 'warm',
            ];
        }
        return $out;
    }

    /** The first line offered to a pick (its id), as the model would answer one. */
    private function firstEnum(string $user): string
    {
        return preg_match('/"id":\s*"([^"]+)"/', $user, $m) === 1 ? $m[1] : '';
    }

    /** @return list<string> */
    private function idsIn(string $user): array
    {
        preg_match_all('/"id":\s*"([^"]+)"/', $user, $m);
        return $m[1];
    }
}

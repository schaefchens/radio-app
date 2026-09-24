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
            return new TextResult($data, $data === null ? 'refusal' : 'ok', 'stub');
        }
        return new TextResult($this->default($kind, $user), 'ok', 'stub');
    }

    /** @return array<string,mixed> */
    private function default(string $kind, string $user): array
    {
        return match (true) {
            str_starts_with($kind, 'host') => [
                'en' => ['text' => 'You are listening to ARCHE. Stay with us for more worship.'],
                'de' => ['text' => 'Ihr hört ARCHE. Bleibt dran für mehr Lobpreis.'],
            ],
            $kind === 'moderate_song', $kind === 'moderate_audio', $kind === 'moderate_prayer' => [
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

    /** @return list<string> */
    private function idsIn(string $user): array
    {
        preg_match_all('/"id":\s*"([^"]+)"/', $user, $m);
        return $m[1];
    }
}

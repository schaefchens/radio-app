<?php
declare(strict_types=1);

namespace Arche\Ai;

/**
 * AI_MODE=stub: research that answers at once, without the network, so a
 * look-up finishes in the tick it started (tests and e2e never wait). A test
 * can give its own answer per call with `respond()` (null: a failed call).
 */
final class StubWebResearch extends WebResearch
{
    /** @var (\Closure(string $input): (array<string,mixed>|null))|null */
    private ?\Closure $responder = null;
    /** @var list<string> the inputs sent */
    public array $calls = [];
    /** @var array<string,string> */
    private array $inputs = [];

    /** @param \Closure(string $input): (array<string,mixed>|null) $fn */
    public function respond(\Closure $fn): void
    {
        $this->responder = $fn;
    }

    public function configured(): bool
    {
        return true;
    }

    public function start(string $name, string $instructions, string $input, array $schema, int $maxSearches = 6): string
    {
        $this->calls[] = $input;
        $id = 'stub-research-' . count($this->calls);
        $this->inputs[$id] = $input;
        return $id;
    }

    public function answer(string $id): Answer
    {
        $input = $this->inputs[$id] ?? '';
        $data = $this->responder !== null ? ($this->responder)($input) : self::fixed();
        if ($data === null) return new Answer('failed', reason: 'refusal');
        $sources = array_values(array_filter(array_map(fn($f) => is_array($f) ? (string) ($f['source'] ?? '') : '', (array) ($data['facts'] ?? []))));
        return new Answer('done', $data, sources: [...$sources, ...array_map('strval', (array) ($data['sources'] ?? []))], model: 'stub');
    }

    public function cancel(string $id): void {}

    public function forget(string $id): void {}

    /** @return array<string,mixed> */
    public static function fixed(): array
    {
        return [
            // Not identified: a stub never renames a library item.
            'identity' => ['identified' => false, 'title' => '', 'artist' => '', 'original' => '', 'writers' => [], 'year' => '',
                'artist_background' => 'A Christian worship band.', 'christian_artist' => 'yes'],
            'bible' => ['Psalm 23'],
            'facts' => [['en' => 'The song was written for a small church choir.', 'de' => 'Das Lied wurde für einen kleinen Gemeindechor geschrieben.', 'source' => 'https://example.org/song']],
            'content_notes' => 'A worship song about trusting God.',
            'public_domain' => ['is' => false, 'why' => '', 'url' => ''],
            'sources' => ['https://example.org/song'],
        ];
    }
}

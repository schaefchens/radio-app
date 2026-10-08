<?php
declare(strict_types=1);

namespace Arche\Ai;

/**
 * AI_MODE=stub: listening that answers at once with a Christian, biblical
 * song, without the network. A test can give its own answer per video with
 * `respond()` (null: a failed call).
 */
final class StubVideoListener extends VideoListener
{
    /** @var (\Closure(string $ytId): (array<string,mixed>|null))|null */
    private ?\Closure $responder = null;
    /** @var list<string> the videos listened to */
    public array $calls = [];
    /** @var array<string,string> */
    private array $videos = [];

    /** @param \Closure(string $ytId): (array<string,mixed>|null) $fn */
    public function respond(\Closure $fn): void
    {
        $this->responder = $fn;
    }

    public function configured(): bool
    {
        return true;
    }

    public function start(string $ytId, string $system, string $text, array $schema): string
    {
        $this->calls[] = $ytId;
        $id = 'stub-listen-' . count($this->calls);
        $this->videos[$id] = $ytId;
        return $id;
    }

    public function answer(string $id): Answer
    {
        $yt = $this->videos[$id] ?? '';
        $data = $this->responder !== null ? ($this->responder)($yt) : self::fixed();
        return $data === null ? new Answer('failed', reason: 'empty') : new Answer('done', $data, model: 'stub');
    }

    public function cancel(string $id): void {}

    public function forget(string $id): void {}

    /** @return array<string,mixed> */
    public static function fixed(): array
    {
        return [
            'heard' => true, 'kind_heard' => 'song', 'languages' => ['en'],
            'message_en' => 'The song says that God stays near in every storm.',
            'message_de' => 'Das Lied sagt, dass Gott in jedem Sturm nahe bleibt.',
            'summary_en' => 'A worship song about God being near in hard times.',
            'summary_de' => 'Ein Lobpreislied darüber, dass Gott in schweren Zeiten nahe ist.',
            'addressed_to' => 'God', 'themes' => ['trust', 'worship'], 'moods' => ['calm', 'hopeful'], 'energy' => 'calm', 'style' => 'worship',
            'quotes' => [['text' => 'You are near in every storm', 'at' => '0:42']], 'bible_refs' => ['Psalm 23'],
            'christian' => 'yes', 'christian_why' => 'A worship song addressed to God.', 'biblical' => 'yes', 'concerns' => [],
            'explicit' => false, 'age' => 'all', 'fits' => ['worship', 'quiet'], 'speaker' => '', 'points' => [],
        ];
    }
}

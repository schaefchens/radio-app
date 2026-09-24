<?php
declare(strict_types=1);

namespace Arche\Ai;

use Arche\App;

/**
 * What the AI calls cost, per UTC day and kind, and the daily ceiling.
 *
 * Prices are list prices at the time of writing (USD per million tokens for
 * the text models; per character / per second estimates for OpenAI audio).
 * They only have to be good enough to stop a runaway loop, not to reconcile
 * an invoice.
 */
final class Usage
{
    /** @var array<string,array{0:float,1:float}> input, output USD per 1M tokens */
    private const OPENAI = [
        'gpt-5-nano' => [0.05, 0.4],
        'gpt-5-mini' => [0.25, 2.0],
        'gpt-5' => [1.25, 10.0],
        'gpt-4.1-nano' => [0.1, 0.4],
        'gpt-4.1-mini' => [0.4, 1.6],
        'gpt-4.1' => [2.0, 8.0],
        'gpt-4o-mini' => [0.15, 0.6],
        'gpt-4o' => [2.5, 10.0],
    ];
    /** A model not listed above is priced high on purpose: the cap must not be too generous. */
    private const OPENAI_UNKNOWN = [5.0, 30.0];
    /** @var array<string,array{0:float,1:float}> input, output USD per 1M tokens */
    private const CLAUDE = [
        'claude-fable-5-1' => [10.0, 50.0],
        'claude-opus-5-5' => [4.0, 20.0],
        'claude-opus-5' => [5.0, 25.0],
        'claude-sonnet-5' => [2.0, 10.0],
        'claude-haiku-4-5' => [1.0, 5.0],
    ];
    /** gpt-4o-mini-tts ≈ $0.015 per minute of speech ≈ 900 characters. */
    private const TTS_MICROS_PER_CHAR = 17;
    /** gpt-4o-transcribe ≈ $0.006 per minute. */
    private const STT_MICROS_PER_SECOND = 100;

    public function __construct(private App $app) {}

    private function day(): string
    {
        return gmdate('Y-m-d', $this->app->clock->now());
    }

    public function claudeCost(string $model, int $in, int $out): int
    {
        $p = self::CLAUDE[$model] ?? self::CLAUDE['claude-opus-5'];
        return (int) round($in * $p[0] + $out * $p[1]); // micro-USD: tokens × USD/1M
    }

    /**
     * OpenAI answers with a dated snapshot ("gpt-5-mini-2025-08-07"): the
     * longest listed name it starts with sets the price.
     */
    public function openaiCost(string $model, int $in, int $out): int
    {
        $price = self::OPENAI_UNKNOWN;
        $best = 0;
        foreach (self::OPENAI as $name => $p) {
            $exact = $model === $name || str_starts_with($model, $name . '-');
            if ($exact && strlen($name) > $best) {
                $best = strlen($name);
                $price = $p;
            }
        }
        return (int) round($in * $price[0] + $out * $price[1]);
    }

    public function record(string $kind, int $in, int $out, int $costMicros): void
    {
        $this->app->store()->query(
            'INSERT INTO ai_usage(day, kind, calls, input_tokens, output_tokens, cost_micros) VALUES(?, ?, 1, ?, ?, ?)
             ON CONFLICT(day, kind) DO UPDATE SET calls = calls + 1, input_tokens = input_tokens + excluded.input_tokens,
               output_tokens = output_tokens + excluded.output_tokens, cost_micros = cost_micros + excluded.cost_micros',
            [$this->day(), $kind, $in, $out, $costMicros],
        );
    }

    public function recordTts(string $text): void
    {
        $this->record('tts', 0, 0, mb_strlen($text) * self::TTS_MICROS_PER_CHAR);
    }

    public function recordStt(int $ms): void
    {
        $this->record('stt', 0, 0, intdiv($ms, 1000) * self::STT_MICROS_PER_SECOND);
    }

    public function spentTodayMicros(): int
    {
        return (int) $this->app->store()->value('SELECT COALESCE(SUM(cost_micros), 0) FROM ai_usage WHERE day = ?', [$this->day()]);
    }

    public function callsToday(string $kind): int
    {
        return (int) $this->app->store()->value('SELECT COALESCE(SUM(calls), 0) FROM ai_usage WHERE day = ? AND kind = ?', [$this->day(), $kind]);
    }

    public function withinBudget(): bool
    {
        $cap = $this->app->config->float('AI_DAILY_BUDGET_USD', 5.0);
        return $this->spentTodayMicros() < (int) round($cap * 1_000_000);
    }

    /** @return list<array<string,mixed>> the last $days days, for /mod status */
    public function recent(int $days = 7): array
    {
        return $this->app->store()->all(
            'SELECT day, kind, calls, input_tokens, output_tokens, cost_micros FROM ai_usage WHERE day >= ? ORDER BY day DESC, kind',
            [gmdate('Y-m-d', $this->app->clock->now() - ($days - 1) * 86400)],
        );
    }
}

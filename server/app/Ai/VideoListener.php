<?php
declare(strict_types=1);

namespace Arche\Ai;

use Arche\App;
use Arche\Support\BudgetExceeded;

/**
 * A model that watches and listens to a public YouTube video by its link —
 * Google's Gemini API, the one route YouTube allows: captions belong to the
 * video's owner, and fetching a video's text any other way breaks YouTube's
 * terms. It answers in a JSON schema what is sung or said; it never writes
 * the words out (a court found that reproducing lyrics infringes, LG München I,
 * 2025, and Gemini blocks it anyway). A video takes far longer than a tick:
 * a background interaction, started now and asked for on a later tick, then
 * deleted. Only the video's link and public data are ever sent.
 */
class VideoListener
{
    private const URL = 'https://generativelanguage.googleapis.com/v1beta/interactions';
    /** USD per 1M tokens, input and output (thinking included), paid tier. */
    private const PRICES = [
        'gemini-3.8-flash' => [0.75, 3.75, 1.50, 7.50],
        'gemini-3.7-flash' => [0.75, 3.75, 1.50, 7.50],
        'gemini-3.6-flash' => [0.75, 3.75, 1.50, 7.50],
        'gemini-3.5-flash' => [1.50, 9.00, 1.50, 9.00],
        'gemini-3.5-flash-lite' => [0.30, 2.50, 0.30, 2.50],
    ];
    /** From 2027-01-01 the 3.6–3.8 Flash models cost twice as much (the later pair above). */
    private const PRICE_CHANGE = 1798761600;
    /** A model not listed is priced high on purpose: the budget must not be too generous. */
    private const UNKNOWN = [2.0, 10.0, 2.0, 10.0];

    public function __construct(protected App $app) {}

    public function configured(): bool
    {
        return $this->app->config->get('GEMINI_API_KEY') !== '';
    }

    public function model(): string
    {
        return $this->app->config->get('GEMINI_MODEL', 'gemini-3.8-flash');
    }

    /**
     * @param array<string,mixed> $schema
     * @throws \RuntimeException when Google does not take the call (the job lease tries again)
     */
    public function start(string $ytId, string $system, string $text, array $schema): string
    {
        $r = $this->app->http()->postJson(self::URL, [
            'model' => $this->model(),
            'system_instruction' => $system,
            'input' => [
                // Low resolution: what is sung or said matters, not the picture (about 100 tokens a second).
                ['type' => 'video', 'uri' => 'https://www.youtube.com/watch?v=' . $ytId, 'resolution' => 'low'],
                ['type' => 'text', 'text' => $text],
            ],
            'response_format' => ['type' => 'text', 'mime_type' => 'application/json', 'schema' => $schema],
            'generation_config' => ['thinking_level' => 'low', 'max_output_tokens' => 4096],
            'background' => true,
        ], $this->headers(), 15);
        $data = $r->json();
        $id = is_array($data) ? ($data['id'] ?? null) : null;
        if ($r->status !== 200 || !is_string($id)) {
            $err = is_array($data) ? ($data['error'] ?? $data[0]['error'] ?? []) : [];
            throw new \RuntimeException('Listening not started: HTTP ' . $r->status . ' ' . mb_substr((string) ($err['message'] ?? ''), 0, 200));
        }
        return $id;
    }

    public function answer(string $id): Answer
    {
        try {
            $r = $this->app->http()->get(self::URL . '/' . rawurlencode($id), $this->headers(), 10);
        } catch (BudgetExceeded $e) {
            throw $e;
        } catch (\Throwable) {
            return new Answer('running');
        }
        if ($r->status === 429 || $r->status >= 500) return new Answer('running');
        $d = $r->json();
        if ($r->status !== 200 || !is_array($d)) return new Answer('failed', reason: 'http_' . $r->status);
        $status = (string) ($d['status'] ?? '');
        if (in_array($status, ['queued', 'in_progress'], true)) return new Answer('running');

        $text = '';
        foreach ((array) ($d['steps'] ?? $d['outputs'] ?? []) as $step) {
            if (!is_array($step)) continue;
            if (($step['type'] ?? '') === 'model_output' || isset($step['content'])) {
                foreach ((array) ($step['content'] ?? []) as $c) {
                    if (is_array($c) && ($c['type'] ?? 'text') === 'text') $text .= (string) ($c['text'] ?? '');
                }
            } elseif (($step['type'] ?? '') === 'text') {
                $text .= (string) ($step['text'] ?? '');
            }
        }
        $model = (string) ($d['model'] ?? $this->model());
        $usage = is_array($d['usage'] ?? null) ? $d['usage'] : [];
        $in = (int) ($usage['total_input_tokens'] ?? 0);
        // Thinking is billed as output.
        $out = (int) ($usage['total_output_tokens'] ?? 0) + (int) ($usage['total_thought_tokens'] ?? 0);
        $cost = $this->cost($model, $in, $out);
        if ($status !== 'completed') return new Answer('failed', reason: $status ?: 'unknown', costMicros: $cost, model: $model, in: $in, out: $out);
        $parsed = json_decode($text, true);
        return is_array($parsed)
            ? new Answer('done', $parsed, costMicros: $cost, model: $model, in: $in, out: $out)
            // Gemini stops an answer that reads like a published text (RECITATION): no data, said so.
            : new Answer('failed', reason: $text === '' ? 'empty' : 'unparsable', costMicros: $cost, model: $model, in: $in, out: $out);
    }

    public function cancel(string $id): void
    {
        $this->quietly('POST', self::URL . '/' . rawurlencode($id) . '/cancel');
    }

    public function forget(string $id): void
    {
        $this->quietly('DELETE', self::URL . '/' . rawurlencode($id));
    }

    /** Micro-USD for a call: tokens × USD per 1M. */
    public function cost(string $model, int $in, int $out): int
    {
        $p = self::UNKNOWN;
        foreach (self::PRICES as $name => $price) {
            if ($model === $name || str_starts_with($model, $name . '-')) $p = $price;
        }
        [$pin, $pout] = $this->app->clock->now() >= self::PRICE_CHANGE ? [$p[2], $p[3]] : [$p[0], $p[1]];
        return (int) round($in * $pin + $out * $pout);
    }

    private function quietly(string $method, string $url): void
    {
        try {
            $this->app->http()->request($method, $url, $this->headers(), $method === 'POST' ? '{}' : null, 5);
        } catch (BudgetExceeded $e) {
            throw $e;
        } catch (\Throwable) {
            // Stored interactions expire on their own; nothing depends on this.
        }
    }

    /** @return array<string,string> */
    private function headers(): array
    {
        return ['x-goog-api-key' => $this->app->config->get('GEMINI_API_KEY'), 'Content-Type' => 'application/json'];
    }
}

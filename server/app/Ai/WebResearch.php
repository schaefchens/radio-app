<?php
declare(strict_types=1);

namespace Arche\Ai;

use Arche\App;
use Arche\Support\BudgetExceeded;

/**
 * Web research through OpenAI's Responses API: the model searches the web and
 * answers in a strict JSON schema. A search takes half a minute or more, longer
 * than a tick may wait, so it runs in background mode: start() returns at once
 * with an id, answer() asks again on a later tick. `store: true` keeps the
 * answer past the ten minutes a background answer otherwise lives (a tick
 * comes once a minute); forget() deletes it once read. Only public data about
 * a video is ever sent.
 *
 * With a schema, OpenAI leaves the inline citations empty: the pages the
 * search consulted come back as the search calls' sources instead, and the
 * caller checks every fact's page against them.
 */
class WebResearch
{
    private const URL = 'https://api.openai.com/v1/responses';
    /** $10 per 1,000 searches, on every model (the pages read are billed as input tokens). */
    private const SEARCH_MICROS = 10_000;

    public function __construct(protected App $app) {}

    public function configured(): bool
    {
        return $this->app->config->openaiKey() !== '';
    }

    public function model(): string
    {
        return $this->app->config->get('OPENAI_RESEARCH_MODEL', 'gpt-6.1-sol');
    }

    /**
     * @param array<string,mixed> $schema
     * @throws \RuntimeException when OpenAI does not take the call (the job lease tries again)
     */
    public function start(string $name, string $instructions, string $input, array $schema, int $maxSearches = 6): string
    {
        $r = $this->app->http()->postJson(self::URL, [
            'model' => $this->model(),
            'background' => true,
            'store' => true,
            // Low is enough for looking things up; the docs advise against less with search.
            'reasoning' => ['effort' => 'low'],
            'tools' => [['type' => 'web_search']],
            'max_tool_calls' => $maxSearches,
            'include' => ['web_search_call.action.sources'],
            'instructions' => $instructions,
            'input' => $input,
            'text' => ['format' => ['type' => 'json_schema', 'name' => $name, 'strict' => true, 'schema' => $schema]],
        ], $this->headers(), 15);
        $data = $r->json();
        if ($r->status !== 200 || !is_array($data) || !is_string($data['id'] ?? null)) {
            throw new \RuntimeException('Research not started: HTTP ' . $r->status . ' ' . mb_substr((string) ($data['error']['message'] ?? ''), 0, 200));
        }
        return $data['id'];
    }

    public function answer(string $id): Answer
    {
        try {
            $r = $this->app->http()->get(self::URL . '/' . rawurlencode($id) . '?include[]=web_search_call.action.sources', $this->headers(), 10);
        } catch (BudgetExceeded $e) {
            throw $e;
        } catch (\Throwable) {
            // Our side or theirs unreachable: the answer waits there; ask on the next tick.
            return new Answer('running');
        }
        $d = $r->json();
        if ($r->status === 429 || $r->status >= 500) return new Answer('running');
        if ($r->status !== 200 || !is_array($d)) return new Answer('failed', reason: 'http_' . $r->status);
        $status = (string) ($d['status'] ?? '');
        if (in_array($status, ['queued', 'in_progress'], true)) return new Answer('running');

        $sources = [];
        $searches = 0;
        $text = '';
        $refused = false;
        foreach ((array) ($d['output'] ?? []) as $item) {
            if (!is_array($item)) continue;
            if (($item['type'] ?? '') === 'web_search_call') {
                $action = is_array($item['action'] ?? null) ? $item['action'] : [];
                if (($action['type'] ?? 'search') === 'search') $searches++;
                // An opened page is a source as much as a search result.
                if (is_string($action['url'] ?? null)) $sources[] = $action['url'];
                foreach ((array) ($action['sources'] ?? []) as $s) {
                    if (is_array($s) && is_string($s['url'] ?? null)) $sources[] = $s['url'];
                }
            }
            if (($item['type'] ?? '') === 'message') {
                foreach ((array) ($item['content'] ?? []) as $c) {
                    if (!is_array($c)) continue;
                    if (($c['type'] ?? '') === 'output_text') $text .= (string) ($c['text'] ?? '');
                    if (($c['type'] ?? '') === 'refusal') $refused = true;
                }
            }
        }
        $model = (string) ($d['model'] ?? $this->model());
        $usage = is_array($d['usage'] ?? null) ? $d['usage'] : [];
        $in = (int) ($usage['input_tokens'] ?? 0);
        $out = (int) ($usage['output_tokens'] ?? 0);
        $cached = (int) ($usage['input_tokens_details']['cached_tokens'] ?? 0);
        $cost = $this->app->usage()->openaiCost($model, $in, $out, $cached) + $searches * self::SEARCH_MICROS;
        $sources = array_values(array_unique($sources));
        if ($status !== 'completed') return new Answer('failed', reason: $status ?: 'unknown', sources: $sources, costMicros: $cost, model: $model, in: $in, out: $out);
        if ($refused) return new Answer('failed', reason: 'refusal', sources: $sources, costMicros: $cost, model: $model, in: $in, out: $out);
        $parsed = json_decode($text, true);
        return is_array($parsed)
            ? new Answer('done', $parsed, sources: $sources, costMicros: $cost, model: $model, in: $in, out: $out)
            : new Answer('failed', reason: 'unparsable', sources: $sources, costMicros: $cost, model: $model, in: $in, out: $out);
    }

    /** Stop a call that took too long (best effort: it may have finished meanwhile). */
    public function cancel(string $id): void
    {
        $this->quietly('POST', self::URL . '/' . rawurlencode($id) . '/cancel');
    }

    /** Delete a stored answer once read: it is ours to keep, not OpenAI's. */
    public function forget(string $id): void
    {
        $this->quietly('DELETE', self::URL . '/' . rawurlencode($id));
    }

    private function quietly(string $method, string $url): void
    {
        try {
            $this->app->http()->request($method, $url, $this->headers(), $method === 'POST' ? '{}' : null, 5);
        } catch (BudgetExceeded $e) {
            throw $e;
        } catch (\Throwable) {
            // Stored answers expire on their own; nothing depends on this.
        }
    }

    /** @return array<string,string> */
    private function headers(): array
    {
        return ['Authorization' => 'Bearer ' . $this->app->config->openaiKey(), 'Content-Type' => 'application/json'];
    }
}

<?php
declare(strict_types=1);

namespace Arche\Ai;

use Arche\App;
use Arche\Support\BudgetExceeded;

/**
 * Structured calls to OpenAI's Chat Completions API — the text model when the
 * station has an OpenAI key and no Anthropic one (Config::textProvider). The
 * same contract as Claude: the answer is constrained by a strict JSON schema,
 * the timeout comes from the tick's Budget, nothing is retried in-line (the
 * job lease does that on a later tick), and every failure is a TextResult
 * without data.
 */
final class OpenAiText implements TextModel
{
    private const URL = 'https://api.openai.com/v1/chat/completions';

    public function __construct(private App $app) {}

    public function provider(): string
    {
        return 'openai';
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
        $c = $this->app->config;
        $model = $c->get($role === 'host' ? 'OPENAI_HOST_MODEL' : 'OPENAI_MODERATION_MODEL');
        $key = $c->openaiKey();
        if ($key === '') return new TextResult(null, 'no_key', $model);
        if (!$this->app->usage()->withinBudget()) return new TextResult(null, 'budget', $model);

        $body = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => ['name' => self::schemaName($kind), 'strict' => true, 'schema' => $schema],
            ],
            // Reasoning tokens count here too; the answers are a few sentences.
            'max_completion_tokens' => $maxTokens,
        ];
        // Reasoning models take an effort; the others reject the parameter.
        if (self::reasons($model)) $body['reasoning_effort'] = $effort;

        try {
            $r = $this->app->http()->postJson(self::URL, $body, ['Authorization' => 'Bearer ' . $key], $timeout);
        } catch (BudgetExceeded $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Timeouts and refused connections: the lease retries on a later tick.
            $this->app->store()->audit('openai', 'Call failed: ' . $kind, substr($e->getMessage(), 0, 300));
            return new TextResult(null, 'error', $model);
        }
        $data = $r->json();
        if ($r->status !== 200 || !is_array($data)) {
            // 429 (rate or quota), 5xx, and 400 for a request this code got
            // wrong. OpenAI's message is logged, never the request: it may
            // hold what a listener wrote.
            $why = is_array($data) ? (string) ($data['error']['message'] ?? '') : '';
            $this->app->store()->audit('openai', 'Call failed: ' . $kind, 'HTTP ' . $r->status . ($why !== '' ? ': ' . mb_substr($why, 0, 250) : ''));
            return new TextResult(null, 'error', $model);
        }

        $answered = (string) ($data['model'] ?? $model);
        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];
        $in = (int) ($usage['prompt_tokens'] ?? 0);
        $out = (int) ($usage['completion_tokens'] ?? 0);
        $this->app->usage()->record('text:' . $kind, $in, $out, $this->app->usage()->openaiCost($answered, $in, $out));

        $choice = is_array($data['choices'][0] ?? null) ? $data['choices'][0] : [];
        $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];
        $finish = (string) ($choice['finish_reason'] ?? '');
        if (!empty($message['refusal']) || $finish === 'content_filter') return new TextResult(null, 'refusal', $answered);
        if ($finish === 'length') return new TextResult(null, 'max_tokens', $answered);
        $parsed = json_decode((string) ($message['content'] ?? ''), true);
        return is_array($parsed) ? new TextResult($parsed, 'ok', $answered) : new TextResult(null, 'unparsable', $answered);
    }

    /** gpt-5… and o… take `reasoning_effort`; gpt-4… and the gpt-5 chat models reject it. */
    public static function reasons(string $model): bool
    {
        return (bool) preg_match('/^(o\d|gpt-5)/', $model) && !str_contains($model, 'chat');
    }

    /** OpenAI wants a schema name of letters, digits, `_` and `-`, at most 64. */
    private static function schemaName(string $kind): string
    {
        return substr((string) preg_replace('/[^A-Za-z0-9_-]/', '_', $kind), 0, 64) ?: 'answer';
    }
}

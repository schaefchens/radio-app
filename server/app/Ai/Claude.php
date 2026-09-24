<?php
declare(strict_types=1);

namespace Arche\Ai;

use Anthropic\Client;
use Arche\App;
use Arche\Support\BudgetExceeded;
use GuzzleHttp\Client as Guzzle;

/**
 * Structured calls to the Messages API through the official SDK — the text
 * model whenever ANTHROPIC_KEY is set (see Config::textProvider).
 *
 * Three things are deliberate:
 *   - The HTTP client is built per call with a hard timeout from the tick's
 *     Budget, and the SDK's own retries are off. On the webhosting a hung call
 *     would otherwise outlive the request; a failed call is retried by the job
 *     lease on a later tick instead.
 *   - Opus 5 / Fable get server-side `fallbacks: 'default'`, so a classifier
 *     decline is re-run on the recommended model instead of coming back as a
 *     refusal. `refusal` and `max_tokens` are still handled — as "no data".
 *   - Output is constrained with a JSON schema (`output_config.format`), so the
 *     callers never parse prose.
 */
final class Claude implements TextModel
{
    public function __construct(private App $app) {}

    public function provider(): string
    {
        return 'anthropic';
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
        $model = $this->app->config->get($role === 'host' ? 'HOST_MODEL' : 'MODERATION_MODEL');
        $key = $this->app->config->anthropicKey();
        if ($key === '') return new TextResult(null, 'no_key', $model);
        if (!$this->app->usage()->withinBudget()) return new TextResult(null, 'budget', $model);

        try {
            $seconds = $this->app->budget->remaining($timeout);
            $client = new Client(apiKey: $key, requestOptions: [
                'maxRetries' => 0,
                'timeout' => (float) $seconds,
                'transporter' => new Guzzle(['timeout' => $seconds, 'connect_timeout' => 5]),
            ]);
            $fallback = self::supportsFallbacks($model);
            $message = $client->beta->messages->create(
                maxTokens: $maxTokens,
                messages: [['role' => 'user', 'content' => $user]],
                model: $model,
                system: [['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']]],
                outputConfig: ['effort' => $effort, 'format' => ['type' => 'json_schema', 'schema' => $schema]],
                fallbacks: $fallback ? 'default' : null,
                betas: $fallback ? ['server-side-fallback-2026-07-01'] : null,
            );
        } catch (BudgetExceeded $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Rate limits (429), overload (529), timeouts and 5xx all land here.
            // The message is logged without the request, which may hold listener text.
            $this->app->store()->audit('claude', 'Call failed: ' . $kind, substr($e->getMessage(), 0, 300));
            return new TextResult(null, 'error', $model);
        }

        $usage = $message->usage;
        $in = (int) ($usage->inputTokens ?? 0) + (int) ($usage->cacheCreationInputTokens ?? 0) + (int) ($usage->cacheReadInputTokens ?? 0);
        $out = (int) ($usage->outputTokens ?? 0);
        $this->app->usage()->record('text:' . $kind, $in, $out, $this->app->usage()->claudeCost($model, $in, $out));

        $stop = (string) (is_object($message->stopReason ?? null) ? $message->stopReason->value : ($message->stopReason ?? ''));
        if ($stop === 'refusal' || $stop === 'max_tokens') return new TextResult(null, $stop, (string) $message->model);

        foreach ($message->content as $block) {
            if (($block->type ?? '') !== 'text') continue;
            $data = json_decode((string) $block->text, true);
            if (is_array($data)) return new TextResult($data, 'ok', (string) $message->model);
        }
        return new TextResult(null, 'unparsable', (string) $message->model);
    }

    public static function supportsFallbacks(string $model): bool
    {
        return $model === 'claude-opus-5' || str_starts_with($model, 'claude-fable-5');
    }
}

<?php
declare(strict_types=1);

use Arche\Ai\Claude;
use Arche\Ai\OpenAiText;
use Arche\Program\SubmissionWindow;
use Arche\Support\HttpClient;
use Arche\Support\HttpResponse;

/** Records every request and answers from a queue (a Throwable is thrown, like curl failing). */
final class FakeHttp extends HttpClient
{
    /** @var list<array{method:string,url:string,headers:array<string,string>,body:mixed}> */
    public array $sent = [];
    /** @var list<HttpResponse|Throwable> */
    public array $answers = [];

    public function __construct() {}

    public function request(string $method, string $url, array $headers = [], string|array|null $body = null, int $timeout = 20): HttpResponse
    {
        $this->sent[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        $next = array_shift($this->answers) ?? new HttpResponse(500, '{}');
        if ($next instanceof Throwable) throw $next;
        return $next;
    }

    /** @return array<string,mixed> the JSON body of request $i */
    public function body(int $i): array
    {
        return json_decode((string) ($this->sent[$i]['body'] ?? ''), true) ?: [];
    }
}

/** A Chat Completions answer as OpenAI sends it. @param array<string,mixed> $content */
function openaiAnswer(array $content, string $finish = 'stop', ?string $refusal = null): HttpResponse
{
    return new HttpResponse(200, (string) json_encode([
        'id' => 'chatcmpl-test',
        'object' => 'chat.completion',
        'model' => 'gpt-5-mini-2025-08-07',
        'choices' => [[
            'index' => 0,
            'finish_reason' => $finish,
            'message' => ['role' => 'assistant', 'content' => $refusal === null ? json_encode($content) : null, 'refusal' => $refusal],
        ]],
        'usage' => ['prompt_tokens' => 1200, 'completion_tokens' => 300],
    ]));
}

const LIVE_NO_KEYS = ['AI_MODE' => 'live', 'ANTHROPIC_KEY' => '', 'OPENAI_KEY' => '', 'ELEVENLABS_API_KEY' => ''];

test('ai: the text model is whichever key the station has — OpenAI alone is enough', function () {
    eq(TestKit::app()->config->textProvider(), 'stub', 'stub mode');
    $none = TestKit::app(LIVE_NO_KEYS);
    eq($none->config->textProvider(), '', 'no key: no text model');
    check(!$none->hostBreaks()->available(), 'no key: no host breaks (templates are not planned either)');
    check(!SubmissionWindow::featureOn($none, 'prayer'), 'no key: submissions are not offered — they could only fail closed');

    $openai = TestKit::app(['OPENAI_KEY' => 'sk-test'] + LIVE_NO_KEYS);
    eq($openai->config->textProvider(), 'openai', 'only OpenAI');
    check($openai->text() instanceof OpenAiText, 'OpenAI writes and moderates');
    check($openai->hostBreaks()->available(), 'the host speaks with OpenAI alone');
    check(SubmissionWindow::featureOn($openai, 'prayer') && SubmissionWindow::featureOn($openai, 'story'), 'prayers and recordings are offered');

    check(TestKit::app(['OPENAI_KEY' => 'sk-test', 'ANTHROPIC_KEY' => 'ak-test'] + LIVE_NO_KEYS)->text() instanceof Claude, 'auto prefers Claude');
    eq(TestKit::app(['AI_TEXT_PROVIDER' => 'openai', 'OPENAI_KEY' => 'sk-test', 'ANTHROPIC_KEY' => 'ak-test'] + LIVE_NO_KEYS)->config->textProvider(), 'openai', 'unless told otherwise');
    eq(TestKit::app(['AI_TEXT_PROVIDER' => 'anthropic', 'OPENAI_KEY' => 'sk-test'] + LIVE_NO_KEYS)->config->textProvider(), '', 'a named provider without its key is none');
});

test('ai: OpenAI answers are schema-bound JSON; refusals, cut-offs and errors are "no data"', function () {
    $app = TestKit::app(['OPENAI_KEY' => 'sk-test'] + LIVE_NO_KEYS);
    $http = new FakeHttp();
    $app->set('http', $http);
    $schema = ['type' => 'object', 'properties' => ['en' => ['type' => 'string']], 'required' => ['en'], 'additionalProperties' => false];

    $http->answers[] = openaiAnswer(['en' => 'Welcome back.']);
    $r = $app->text()->json('host_break', 'host', 'You are Hope.', 'Say hello.', $schema);
    eq($r->data, ['en' => 'Welcome back.'], 'the JSON object');
    eq($http->sent[0]['url'], 'https://api.openai.com/v1/chat/completions', 'Chat Completions');
    eq($http->sent[0]['headers']['Authorization'] ?? '', 'Bearer sk-test', 'the key as a Bearer header');
    $body = $http->body(0);
    eq($body['model'], 'gpt-5-mini', 'the host model');
    eq($body['messages'][0], ['role' => 'system', 'content' => 'You are Hope.'], 'the system prompt');
    eq($body['response_format']['type'], 'json_schema', 'structured output');
    eq($body['response_format']['json_schema']['strict'], true, 'strict');
    eq($body['response_format']['json_schema']['schema'], $schema, 'our schema, unchanged');
    eq($body['reasoning_effort'], 'low', 'an effort for a reasoning model');
    eq((int) $app->store()->value("SELECT cost_micros FROM ai_usage WHERE kind = 'text:host_break'"), 900, 'cost from the dated snapshot\'s price');

    $http->answers[] = openaiAnswer([], 'stop', 'I can’t help with that.');
    eq($app->text()->json('moderate_prayer', 'moderation', 's', 'u', $schema)->reason, 'refusal', 'a refusal is an answer without data');
    eq($http->body(1)['model'], 'gpt-5-mini', 'the moderation model');

    $http->answers[] = openaiAnswer(['en' => 'cut'], 'length');
    eq($app->text()->json('host_break', 'host', 's', 'u', $schema)->reason, 'max_tokens', 'cut off');

    $http->answers[] = new HttpResponse(429, (string) json_encode(['error' => ['message' => 'Rate limit reached for requests', 'type' => 'requests']]));
    eq($app->text()->json('host_break', 'host', 's', 'u', $schema)->reason, 'error', 'rate limited (the lease retries later)');
    $logged = (string) $app->store()->value("SELECT detail FROM audit WHERE actor = 'openai' ORDER BY id DESC LIMIT 1");
    check(str_contains($logged, 'HTTP 429') && str_contains($logged, 'Rate limit'), 'OpenAI\'s reason is logged');

    $http->answers[] = new RuntimeException('HTTP request failed: Operation timed out');
    eq($app->text()->json('host_break', 'host', 's', 'u', $schema)->reason, 'error', 'a timeout');

    $older = TestKit::app(['OPENAI_KEY' => 'sk-test', 'OPENAI_HOST_MODEL' => 'gpt-4.1-mini'] + LIVE_NO_KEYS);
    $http2 = new FakeHttp();
    $older->set('http', $http2);
    $http2->answers[] = openaiAnswer(['en' => 'Hi.']);
    $older->text()->json('host_break', 'host', 's', 'u', $schema);
    check(!array_key_exists('reasoning_effort', $http2->body(0)), 'no effort for a model that rejects it');

    $broke = TestKit::app(['OPENAI_KEY' => 'sk-test', 'AI_DAILY_BUDGET_USD' => '0'] + LIVE_NO_KEYS);
    $http3 = new FakeHttp();
    $broke->set('http', $http3);
    eq($broke->text()->json('host_break', 'host', 's', 'u', $schema)->reason, 'budget', 'over the daily budget');
    eq(count($http3->sent), 0, 'nothing is sent');
});

test('ai: with OpenAI alone the host speaks both languages and a prayer is judged', function () {
    $app = TestKit::app(['OPENAI_KEY' => 'sk-test'] + LIVE_NO_KEYS);
    $http = new FakeHttp();
    $app->set('http', $http);
    $ch = TestKit::main($app);

    $http->answers[] = openaiAnswer(['en' => ['text' => 'Welcome to ARCHE.'], 'de' => ['text' => 'Willkommen bei ARCHE.']]);
    $written = $app->hostWriter()->write(['id' => 0, 'kind' => 'break', 'channel_id' => $ch['id']], ['kind' => 'break']);
    eq($written['source'], 'openai', 'written by OpenAI');
    eq($written['texts'], ['en' => 'Welcome to ARCHE.', 'de' => 'Willkommen bei ARCHE.'], 'in both station languages');

    [$d, $s] = device();
    $me = $app->identities()->resolve($d, $s, true);
    $sub = $app->submissions()->submitPrayer($me, $ch, ['text' => 'Please pray for my mother, she is in hospital.', 'name' => 'Ana', 'place' => 'Lisbon', 'consent_air' => true]);
    $http->answers[] = openaiAnswer(['safe' => true, 'christian' => true, 'program_fit' => true, 'message_ok' => true, 'verdict' => 'approve',
        'themes' => ['prayer'], 'moods' => ['hope'], 'languages' => ['en']]);
    runJobs($app);
    eq($app->submissions()->byPublicId($sub['id'])['status'], 'approved', 'judged by OpenAI');
    eq($http->body(1)['response_format']['json_schema']['name'], 'moderate_prayer', 'with the moderation schema');
});

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
        'model' => 'gpt-5.4-mini-2026-03-17',
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
    eq($body['model'], 'gpt-6.1-sol', 'the host model');
    eq($body['messages'][0], ['role' => 'system', 'content' => 'You are Hope.'], 'the system prompt');
    eq($body['response_format']['type'], 'json_schema', 'structured output');
    eq($body['response_format']['json_schema']['strict'], true, 'strict');
    eq($body['response_format']['json_schema']['schema'], $schema, 'our schema, unchanged');
    eq($body['reasoning_effort'], 'low', 'an effort for a reasoning model');
    eq((int) $app->store()->value("SELECT cost_micros FROM ai_usage WHERE kind = 'text:host_break'"), 2250, 'cost from the dated snapshot\'s price');

    $http->answers[] = openaiAnswer([], 'stop', 'I can’t help with that.');
    eq($app->text()->json('moderate_prayer', 'moderation', 's', 'u', $schema)->reason, 'refusal', 'a refusal is an answer without data');
    eq($http->body(1)['model'], 'gpt-5.4-mini', 'the moderation model');

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

    foreach (['gpt-6.1-sol' => true, 'gpt-6-luna' => true, 'gpt-5.4-mini' => true, 'o4-mini' => true, 'gpt-4o' => false, 'gpt-4.1-mini' => false, 'gpt-5-chat-latest' => false] as $m => $takes) {
        eq(OpenAiText::reasons($m), $takes, "an effort for $m: " . ($takes ? 'yes' : 'no'));
    }
    // The show so far makes long prompts whose start OpenAI has cached: 8,000 of 10,000 at a tenth of the price.
    eq($app->usage()->openaiCost('gpt-6.1-sol-2026-09-01', 10_000, 500, 8_000), 2_000 * 2 + 8_000 / 10 + 500 * 10, 'cached input priced as such');
    eq($app->usage()->openaiCost('gpt-6.1-sol', 10_000, 500), 10_000 * 2 + 500 * 10, 'none cached: the full price');

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

    $http->answers[] = openaiAnswer(['en' => ['text' => 'Welcome to ARCHE.'], 'de' => ['text' => 'Willkommen bei ARCHE.'], 'delivery' => 'Warm and bright.']);
    $written = $app->hostWriter()->write(['id' => 0, 'kind' => 'break', 'channel_id' => $ch['id']], ['kind' => 'break']);
    eq($written['source'], 'openai', 'written by OpenAI');
    eq($written['texts'], ['en' => 'Welcome to ARCHE.', 'de' => 'Willkommen bei ARCHE.'], 'in both station languages');
    eq($written['delivery'], 'Warm and bright.', 'and how it should sound');
    eq([$http->body(0)['model'], $http->body(0)['reasoning_effort']], ['gpt-6.1-sol', 'low'], 'by the host model, thinking little by default (HOST_EFFORT)');
    eq($http->body(0)['response_format']['json_schema']['schema']['required'], ['en', 'de', 'delivery'], 'the delivery last, written after the words');
    foreach (['medium' => 'medium', 'xhigh' => 'low'] as $setting => $sent) {
        $e = TestKit::app(['OPENAI_KEY' => 'sk-test', 'HOST_EFFORT' => $setting] + LIVE_NO_KEYS);
        $eh = new FakeHttp();
        $e->set('http', $eh);
        $eh->answers[] = openaiAnswer(['en' => ['text' => 'Hi.'], 'de' => ['text' => 'Hallo.'], 'delivery' => '']);
        $e->hostWriter()->write(['id' => 0, 'kind' => 'break', 'channel_id' => $ch['id']], ['kind' => 'break']);
        eq($eh->body(0)['reasoning_effort'], $sent, "HOST_EFFORT=$setting: $sent");
    }

    [$d, $s] = device();
    $me = $app->identities()->resolve($d, $s, true);
    $sub = $app->submissions()->submitPrayer($me, $ch, ['text' => 'Please pray for my mother, she is in hospital.', 'name' => 'Ana', 'place' => 'Lisbon', 'consent_air' => true]);
    $http->answers[] = openaiAnswer(['safe' => true, 'christian' => true, 'program_fit' => true, 'message_ok' => true, 'verdict' => 'approve',
        'themes' => ['prayer'], 'moods' => ['hope'], 'languages' => ['en']]);
    runJobs($app);
    eq($app->submissions()->byPublicId($sub['id'])['status'], 'approved', 'judged by OpenAI');
    eq($http->body(1)['response_format']['json_schema']['name'], 'moderate_prayer', 'with the moderation schema');
});

test('ai: recordings are transcribed by gpt-transcribe, which takes `languages` and never `language` beside it', function () {
    $app = TestKit::app(['OPENAI_KEY' => 'sk-test'] + LIVE_NO_KEYS);
    $http = new FakeHttp();
    $app->set('http', $http);
    $file = $app->config->root . '/resources/stub-voice.mp3';
    $http->answers[] = new HttpResponse(200, '{"text":" Gott ist treu. "}');
    eq($app->openai()->transcribe($file, 'de'), 'Gott ist treu.', 'the text, trimmed');
    $sent = $http->sent[0];
    eq($sent['url'], 'https://api.openai.com/v1/audio/transcriptions', 'the transcription endpoint');
    eq([$sent['body']['model'], $sent['body']['languages[]'] ?? null, array_key_exists('language', $sent['body'])], ['gpt-transcribe', 'de', false], 'the language as an item of the list only');

    $older = TestKit::app(['OPENAI_KEY' => 'sk-test', 'STT_MODEL' => 'gpt-4o-transcribe'] + LIVE_NO_KEYS);
    $older->set('http', $http);
    $http->answers[] = new HttpResponse(200, '{"text":"ok"}');
    $older->openai()->transcribe($file, 'en');
    eq([$http->sent[1]['body']['language'] ?? null, array_key_exists('languages[]', $http->sent[1]['body'])], ['en', false], 'an older model keeps its single language');
});

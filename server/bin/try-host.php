<?php
declare(strict_types=1);

// One real host break through the production code path (the text model's
// script — Claude or OpenAI, see AI_TEXT_PROVIDER — and TTS in every station
// language), without touching the program. For checking the live AI setup
// after changing keys or models. Costs one text call + TTS.
//
//   docker compose --env-file docker/compose.env exec -e ARCHE_PUBLIC_DIR=/var/www/site \
//     -e ARCHE_DATA_DIR=/var/www/site/_arche/var php php /srv/server/bin/try-host.php
if (PHP_SAPI !== 'cli') exit(1);
putenv('AI_MODE=live');
$app = require dirname(__DIR__) . '/app/bootstrap.php';
$channel = $app->catalog()->mainChannel();
$program = $app->catalog()->program((int) $channel['fallback_program_id']);
$context = [
    'kind' => 'announce',
    'host_name' => $channel['host_name'],
    'program' => ['title' => ['en' => $program['title_en'], 'de' => $program['title_de']], 'subtitle' => ['en' => $program['subtitle_en'], 'de' => $program['subtitle_de']], 'themes' => $program['themes']],
    'time_of_day_de' => 'Abend',
    'previous' => ['title' => 'Battle Belongs', 'artist' => 'Phil Wickham'],
    'next' => ['title' => '10,000 Reasons', 'artist' => 'Matt Redman'],
    'request' => ['name' => 'Jenny', 'place' => 'München', 'message' => 'Für meinen Mann – wir haben heute geheiratet!'],
];
$t0 = microtime(true);
$written = $app->hostWriter()->write(['id' => 0, 'kind' => 'announce', 'channel_id' => $channel['id']], $context);
printf("script (%s, %.1f s)\n", $written['source'], microtime(true) - $t0);
foreach ($written['texts'] as $lang => $text) {
    echo "  [$lang] $text\n";
    $t1 = microtime(true);
    $voice = $app->voice()->speak($text, $lang, $channel, '');
    $tmp = tempnam(sys_get_temp_dir(), 'tts');
    file_put_contents($tmp, $voice['bytes']);
    $mp3 = Arche\Audio\Mp3::inspect($tmp);
    printf("  voice %s: %d bytes, %.1f s audio, %.1f s to make\n", $voice['provider'], strlen($voice['bytes']), $mp3['ms'] / 1000, microtime(true) - $t1);
    @unlink($tmp);
}
foreach ($app->usage()->recent(1) as $u) printf("usage %s: %d calls, %d in / %d out tokens, $%.4f\n", $u['kind'], $u['calls'], $u['input_tokens'], $u['output_tokens'], $u['cost_micros'] / 1e6);

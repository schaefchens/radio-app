<?php
declare(strict_types=1);

// Re-read duration and thumbnail from YouTube for every song in the library
// (local/Docker maintenance; on the host use /mod instead). With --names also
// title/artist from YouTube's title — but only where an item still carries
// YouTube's names as its look-up saw them (Library\Knowledge): a moderator's
// own names and the clean ones a look-up gave are kept.
if (PHP_SAPI !== 'cli') exit(1);
$app = require dirname(__DIR__) . '/app/bootstrap.php';
$names = in_array('--names', $argv, true);
foreach ($app->library()->search('', 'song', 500) as $item) {
    $v = $app->youtube()->video((string) $item['yt_id']);
    if (!$v['ok']) {
        echo "skip {$item['yt_id']}: {$v['error']}\n";
        continue;
    }
    $set = ['duration_ms' => $v['duration_ms'], 'thumb' => $app->media()->cacheThumb((string) $item['yt_id'])];
    $known = $app->knowledge()->get((string) $item['yt_id']);
    $youtubes = $known !== null && $item['title'] === $known['yt_title'] && $item['artist'] === $known['yt_artist'];
    if ($names && $youtubes) {
        [$artist, $title] = Arche\Library\YouTube::splitTitle($v['title'], $v['channel']);
        $set += ['title' => mb_substr($title, 0, 120), 'artist' => mb_substr($artist, 0, 120)];
    }
    $app->store()->update('library_items', $set, 'id = ?', [$item['id']]);
    echo "{$item['yt_id']}  " . ($set['artist'] ?? $item['artist']) . ' – ' . ($set['title'] ?? $item['title']) . ($names && !$youtubes ? '  (names kept)' : '') . "\n";
}

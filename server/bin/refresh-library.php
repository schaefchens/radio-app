<?php
declare(strict_types=1);

// Re-read title/artist/duration/thumbnail from YouTube for every song in the
// library (local/Docker maintenance; on the host use /mod instead).
if (PHP_SAPI !== 'cli') exit(1);
$app = require dirname(__DIR__) . '/app/bootstrap.php';
foreach ($app->library()->search('', 'song', 500) as $item) {
    $v = $app->youtube()->video((string) $item['yt_id']);
    if (!$v['ok']) {
        echo "skip {$item['yt_id']}: {$v['error']}\n";
        continue;
    }
    [$artist, $title] = Arche\Library\YouTube::splitTitle($v['title'], $v['channel']);
    $app->store()->update('library_items', [
        'title' => $title, 'artist' => $artist, 'duration_ms' => $v['duration_ms'],
        'thumb' => $app->media()->cacheThumb((string) $item['yt_id']),
    ], 'id = ?', [$item['id']]);
    echo "{$item['yt_id']}  $artist – $title\n";
}

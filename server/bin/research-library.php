<?php
declare(strict_types=1);

// Look this station's library up for real — OpenAI's web search and Gemini
// listening to each video (Library\Knowledge) — while the local stack runs
// with AI_MODE=stub. A CLI has no tick limit: it starts a few look-ups at a
// time and waits for their answers itself, without queued jobs (the stack's
// own stub ticks would answer those with made-up data). About 10 cents a song,
// 15 a sermon, 30 a film; the station's look-up budget is raised for the run.
//   npm run library:research -- [--limit=N] [--again] [--yt=ID,ID] [--kinds=song,preaching] [--budget=USD]
// --again: look up what is known already too (the stub's made-up records).
if (PHP_SAPI !== 'cli') exit(1);

use Arche\Library\Knowledge;

$opt = getopt('', ['limit:', 'again', 'yt:', 'kinds:', 'budget:', 'at-once:']);
putenv('AI_MODE=live');
/** @var Arche\App $app */
$app = require dirname(__DIR__) . '/app/bootstrap.php';
$k = $app->knowledge();
if (!$app->research()->configured() || !$app->listener()->configured()) {
    fwrite(STDERR, "OPENAI_KEY and GEMINI_API_KEY must both be in the .env.\n");
    exit(1);
}
$budget = (float) ($opt['budget'] ?? 20);
if ($k->settings()['budget_usd'] < $budget) {
    $k->saveSettings(['budget_usd' => $budget], 'cli');
    echo "The station's look-up budget is now \${$budget} a day.\n";
}

$kinds = isset($opt['kinds']) ? array_values(array_intersect(Knowledge::KINDS, explode(',', (string) $opt['kinds']))) : Knowledge::KINDS;
$only = isset($opt['yt']) ? array_filter(explode(',', (string) $opt['yt'])) : [];
$items = array_values(array_filter(
    $app->store()->all('SELECT id, yt_id, kind, title, artist FROM library_items WHERE yt_id IS NOT NULL AND active = 1 ORDER BY plays DESC, id'),
    fn($i) => in_array($i['kind'], $kinds, true) && (!$only || in_array($i['yt_id'], $only, true)),
));
$known = $k->many(array_column($items, 'yt_id'));
$todo = array_values(array_filter($items, fn($i) => isset($opt['again']) || $only || ($known[$i['yt_id']]['state'] ?? '') !== 'ready'));
if (isset($opt['limit'])) $todo = array_slice($todo, 0, max(0, (int) $opt['limit']));
$atOnce = max(1, min(8, (int) ($opt['at-once'] ?? 4)));
printf("%d of %d library items to look up, %d at a time.\n", count($todo), count($items), $atOnce);

$running = [];
$spent = $k->spentToday();
$done = 0;
while ($todo || $running) {
    while ($todo && count($running) < $atOnce) {
        $item = array_shift($todo);
        $app->budget->restart(60);
        // Started afresh: whatever was known (or made up) is replaced when the answers come; a look-up
        // a crash left open has its calls cancelled first.
        $old = $k->get((string) $item['yt_id']);
        if ($old !== null && in_array($old['state'], ['queued', 'working'], true)) $k->giveUp((int) $old['id'], 'started again');
        $app->store()->update('video_knowledge', ['state' => 'failed'], 'yt_id = ?', [$item['yt_id']]);
        $row = $k->ensure((string) $item['yt_id'], (string) $item['kind'], 80, true, false);
        if ($row === null) continue;
        $next = step($k, (int) $row['id'], 'start');
        if ($next !== null) $running[(int) $row['id']] = $item;
        else report($k, $item);
    }
    if (!$running) break;
    sleep(10);
    foreach ($running as $id => $item) {
        $app->budget->restart(60);
        if (step($k, $id, 'wait') === null) {
            unset($running[$id]);
            report($k, $item);
        }
    }
}
printf("Done: about $%.2f for the look-ups.\n", ($k->spentToday() - $spent) / 1e6);

/**
 * One phase; what the station's runner would retry on a later tick (YouTube or a
 * provider unreachable) ends this item's look-up here, said so — the rest go on.
 */
function step(Knowledge $k, int $id, string $phase): ?string
{
    try {
        return $k->runPhase(['ref_id' => $id, 'phase' => $phase]);
    } catch (Throwable $e) {
        $k->giveUp($id, $e->getMessage());
        return null;
    }
}

/** @param array<string,mixed> $item */
function report(Knowledge $k, array $item): void
{
    $row = $k->get((string) $item['yt_id']);
    $a = $row['analysis'] ?? [];
    printf("%-11s %-6s christian %-7s biblical %-7s facts %d  %s | %s%s\n",
        $item['yt_id'], $row['state'] ?? '?', $a['christian'] ?? '-', $a['biblical'] ?? '-', count($row['research']['facts'] ?? []),
        mb_substr((string) $item['title'], 0, 45), mb_substr((string) $item['artist'], 0, 25),
        ($row['error'] ?? '') !== '' ? '  [' . $row['error'] . ']' : '');
}

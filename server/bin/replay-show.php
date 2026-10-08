<?php
declare(strict_types=1);

// A real show, written again: the host moments of a stretch of the
// published program, rewritten by today's writer (its prompt, the show so
// far, the model) next to what aired — to judge a change to the host's
// words before it goes live. Reads only the public minute and day files any
// listener loads, and calls the writer once per moment the model wrote
// (gpt-6.1-sol: about a cent each). It never touches a station's database:
// it runs on a throwaway one.
//
//   docker compose --env-file docker/compose.env run --rm --no-deps -v "$PWD/.data/replay:/out" php \
//     php /srv/server/bin/replay-show.php --from=2026-10-07T14:00 --to=2026-10-07T18:00 --out=/out
//
// Options (times UTC): --from, --to; --channel (main); --site (SITE_BASE_URL);
// --model (OPENAI_HOST_MODEL), --effort (HOST_EFFORT); --instructions, the
// hosts' standing voice direction; --max, moments at most; --stub, the stub
// writer instead of the model (a dry run that costs nothing); --knowledge, a
// station's database or a setup file whose look-ups (Library\Knowledge) the
// writer uses on air — replay the same stretch with and without to compare:
//   … replay-show.php … --knowledge=/var/www/site/_arche/var/arche.sqlite
//
// What the minute files do not carry is guessed: a request's dedication,
// a recording's summary, the prayer hour's counts and intake, a program's
// themes. Writes /out/replay.txt (what aired and what is written now, side
// by side) and /out/cases.json for the voice worker's lab (worker/README.md):
// each moment's words as aired with the host's standing direction, and as
// now written with its delivery.
if (PHP_SAPI !== 'cli') exit(1);

use Arche\Host\HostWriter;
use Arche\Host\Speech;

$opt = getopt('', ['from:', 'to:', 'channel:', 'site:', 'model:', 'effort:', 'out:', 'instructions:', 'max:', 'stub', 'knowledge:']);
$from = strtotime(($opt['from'] ?? '') . ' UTC');
$to = strtotime(($opt['to'] ?? '') . ' UTC');
if (!$from || !$to || $to <= $from || $to - $from > 12 * 3600) {
    fwrite(STDERR, "Give --from and --to (UTC, at most 12 hours apart), e.g. --from=2026-10-07T14:00 --to=2026-10-07T18:00\n");
    exit(2);
}
putenv('AI_MODE=' . (isset($opt['stub']) ? 'stub' : 'live'));
if (isset($opt['model'])) putenv('OPENAI_HOST_MODEL=' . $opt['model']);
if (isset($opt['effort'])) putenv('HOST_EFFORT=' . $opt['effort']);
$tmp = sys_get_temp_dir() . '/arche-replay-' . getmypid();
@mkdir("$tmp/data", 0700, true);
@mkdir("$tmp/site", 0755, true);
putenv("ARCHE_DATA_DIR=$tmp/data");
putenv("ARCHE_PUBLIC_DIR=$tmp/site");
/** @var Arche\App $app */
$app = require dirname(__DIR__) . '/app/bootstrap.php';
$channelSlug = (string) ($opt['channel'] ?? 'main');
$site = rtrim((string) ($opt['site'] ?? $app->config->get('SITE_BASE_URL')), '/');
$out = rtrim((string) ($opt['out'] ?? $tmp), '/');
$max = (int) ($opt['max'] ?? 200);
$standing = (string) ($opt['instructions'] ?? 'Warm and natural faithful christian speaker with gentle tone.');
// The station's voices as the owner set them on 2026-10-06 (Faith: Sohee; Grace: Serena; Hope: OpenAI coral).
$voices = ['faith' => ['worker', 'Sohee'], 'grace' => ['worker', 'Serena'], 'hope' => ['openai', 'coral']];

// What a station looked up about its songs (Library\Knowledge): copied into the throwaway database, on air.
if (isset($opt['knowledge'])) {
    $src = (string) $opt['knowledge'];
    $rows = str_ends_with($src, '.json')
        ? (array) ((json_decode((string) @file_get_contents($src), true) ?: [])['tables']['video_knowledge'] ?? [])
        : (new PDO('sqlite:' . $src, null, null, [Pdo\Sqlite::ATTR_OPEN_FLAGS => Pdo\Sqlite::OPEN_READONLY]))
            ->query("SELECT * FROM video_knowledge WHERE state = 'ready'")->fetchAll(PDO::FETCH_ASSOC);
    // Only the columns this one knows: a database a branch migrated may have others.
    $columns = array_flip(array_column($app->store()->all('PRAGMA table_info(video_knowledge)'), 'name'));
    foreach ($rows as $r) {
        unset($r['id']);
        $app->store()->insert('video_knowledge', array_intersect_key($r, $columns));
    }
    $app->knowledge()->saveSettings(['air' => true], 'replay');
    fwrite(STDERR, count($rows) . " looked-up songs and videos from $src\n");
}

$get = static function (string $url): ?array {
    $c = curl_init($url);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false]);
    $body = curl_exec($c);
    $ok = curl_getinfo($c, CURLINFO_RESPONSE_CODE) === 200 && is_string($body);
    return $ok ? (json_decode((string) $body, true) ?: null) : null;
};

// --- the program as listeners loaded it ------------------------------------------------------------
$items = [];
$programs = [];
$zone = new DateTimeZone('Europe/Berlin');
// A minute file names what plays in its next three minutes: one every two leaves no gap.
for ($t = $from; $t <= $to; $t += 120) {
    $slot = $get(sprintf('%s/program/%s/slots/%s/%s.json', $site, $channelSlug, gmdate('Ymd', $t), gmdate('Hi', $t)));
    if ($slot === null) continue;
    foreach ((array) ($slot['programs'] ?? []) as $slug => $p) $programs[$slug] ??= $p;
    foreach ((array) ($slot['items'] ?? []) as $it) {
        if (is_array($it) && isset($it['id'])) $items[$it['id']] = $it;
    }
}
if (!$items) {
    fwrite(STDERR, "No minute files found at $site for that time (they are kept 48 hours).\n");
    exit(1);
}
foreach (array_unique([gmdate('Y-m-d', $from), gmdate('Y-m-d', $to)]) as $date) {
    $day = $get("$site/program/$channelSlug/days/$date.json");
    if ($day === null) continue;
    $zone = new DateTimeZone((string) ($day['tz'] ?? 'Europe/Berlin'));
    foreach ((array) ($day['programs'] ?? []) as $slug => $p) $programs[$slug] = $p + ($programs[$slug] ?? []);
}
$items = array_values($items);
usort($items, fn($a, $b) => $a['start'] <=> $b['start']);
$programIds = array_flip(array_values(array_unique(array_column($items, 'p'))));

/** A minute file's item as a timeline item (ShowLog, HostWriter::songRef/followedBy read those). */
$asItem = static function (array $it, int $i, array $texts = []) use ($programIds): array {
    $type = (string) $it['type'];
    $payload = match ($type) {
        // `yt`: what was looked up about it is found by it (--knowledge).
        'song' => ['kind' => (string) ($it['kind'] ?? 'song'), 'yt' => (string) ($it['yt'] ?? ''), 'title' => (string) ($it['title'] ?? ''), 'artist' => (string) ($it['artist'] ?? ''), 'request' => $it['request'] ?? null],
        'host' => ['kind' => (string) ($it['kind'] ?? ''), 'text' => $texts ?: (array) ($it['text'] ?? []), 'host' => $it['host'] ?? null],
        'contrib' => ['kind' => (string) ($it['kind'] ?? ''), 'opening' => !empty($it['opening'])],
        default => [],
    };
    return ['type' => $type, 'state' => 'committed', 'seq' => (float) $i, 'start_ms' => (int) $it['start'], 'est_start' => (int) $it['start'],
        'dur_ms' => (int) ($it['dur'] ?? 0), 'program_id' => ($programIds[$it['p'] ?? ''] ?? 0) + 1, 'submission_id' => null, 'payload' => $payload];
};
$requested = static fn(?array $it): bool => $it !== null && $it['type'] === 'song' && !empty($it['request']);

// --- each host moment, written again --------------------------------------------------------------
$main = $app->catalog()->mainChannel();
$new = [];
$report = [];
$cases = [];
$spent = 0;
foreach ($items as $i => $it) {
    if ($it['type'] !== 'host' || $spent >= $max) continue;
    $kind = (string) ($it['kind'] ?? '');
    $prog = (array) ($programs[$it['p']] ?? []);
    $name = (string) ($it['host']['name'] ?? 'Faith');
    [$provider, $voice] = $voices[mb_strtolower($name)] ?? ['worker', 'Sohee'];
    $host = ['id' => 0, 'name' => $name, 'about_en' => (string) ($it['host']['about']['en'] ?? ''), 'about_de' => (string) ($it['host']['about']['de'] ?? ''),
        'style' => '', 'provider' => $provider, 'model' => $provider === 'worker' ? 'qwen3-tts-1.7b-customvoice' : 'gpt-4o-mini-tts',
        'voices' => ['en' => $voice, 'de' => $voice], 'instructions' => $standing, 'settings' => []];
    $prev = $i > 0 ? $items[$i - 1] : null;
    $next = $items[$i + 1] ?? null;
    $at = (int) $it['start'];
    $local = (new DateTimeImmutable('@' . intdiv($at, 1000)))->setTimezone($zone);
    $old = array_filter((array) ($it['text'] ?? []), fn($t) => trim((string) $t) !== '');

    if (in_array($kind, [...HostWriter::READINGS, 'opening'], true)) {
        // People's own words: read as written; only the way they are spoken is new.
        $written = ['texts' => $old, 'delivery' => Speech::fixedDelivery($kind), 'source' => 'listener', 'seconds' => 0.0];
    } else {
        // The same frame the live writer starts every moment with (HostWriter::frame).
        $row = ['title_en' => $prog['title']['en'] ?? '', 'title_de' => $prog['title']['de'] ?? '', 'themes' => [], 'settings' => ['format' => $prog['format'] ?? 'music']];
        foreach (['description', 'subtitle'] as $f) {
            foreach (['en', 'de'] as $l) $row["{$f}_$l"] = (string) ($prog[$f][$l] ?? '');
        }
        $ctx = $app->hostWriter()->frame(['kind' => $kind, 'program_id' => ($programIds[$it['p']] ?? 0) + 1], $name, $row, $main, $at,
            $prev !== null ? $asItem($prev, $i - 1) : null, $next !== null ? $asItem($next, $i + 1) : null);
        if ($kind === 'outro' && $next !== null && ($next['p'] ?? '') !== $it['p']) $ctx['after'] = $programs[$next['p']]['title'] ?? null;
        if ($kind === 'announce' && $requested($next)) $ctx['request'] = ['name' => $next['request']['name'] ?? '', 'place' => $next['request']['place'] ?? '', 'message' => ''];
        if (in_array($kind, ['announce', 'contrib', 'break', 'outro'], true) && $requested($prev)) {
            $ctx['previous_request'] = ['kind' => 'song request', 'name' => $prev['request']['name'] ?? '', 'place' => $prev['request']['place'] ?? '', 'message' => '', 'song' => $ctx['previous']];
        }
        if (!empty($it['notice']['name'])) {
            $ctx['previous_group'] = ['name' => $it['notice']['name'], 'about' => $it['notice']['text'] ?? [], 'find' => array_values(array_unique(array_column((array) ($it['notice']['links'] ?? []), 'kind')))];
        }
        if (($prog['format'] ?? '') === 'prayer') {
            // The prayer hour's counts, from what aired after this moment in the same hour.
            $ctx['format'] = 'prayer hour';
            $readings = 0;
            $opendoors = false;
            for ($j = $i + 1; isset($items[$j]) && ($items[$j]['p'] ?? '') === $it['p'] && $items[$j]['type'] === 'host' && ($items[$j]['kind'] ?? '') === 'reading'; $j++) {
                $readings++;
                $opendoors = $opendoors || str_contains(implode(' ', (array) ($items[$j]['text'] ?? [])), 'Open Doors');
            }
            if ($kind === 'present') $ctx += ['requests' => $readings - ($opendoors ? 1 : 0)] + ($opendoors ? ['opendoors' => true] : []);
            if (in_array($kind, ['intro', 'prayertime', 'encourage'], true)) $ctx['intake'] = $kind === 'intro' ? 'open' : ['requests' => 'open', 'prayers' => 'open'];
        }

        // The show so far: this program's items before the moment, with the words written anew so far.
        $start = $i;
        while ($start > 0 && ($items[$start - 1]['p'] ?? '') === $it['p']) $start--;
        $show = [];
        for ($j = $start; $j < $i; $j++) {
            $x = $asItem($items[$j], $j, $new[$items[$j]['id']] ?? []);
            if ($x['type'] === 'host') {
                $k = $x['payload']['kind'];
                $flags = match (true) {
                    $k === 'announce' => ['request' => ['replayed']],
                    $k === 'contrib' => ['contribution' => ['replayed']],
                    $requested($items[$j - 1] ?? null) => ['previous_request' => ['replayed']],
                    default => [],
                };
                $x['break'] = ['kind' => $k, 'state' => 'ready', 'context' => $flags, 'texts' => [], 'source' => 'openai'];
            }
            $show[] = $x;
        }
        $before = [];
        for ($j = $start - 1; $j >= 0 && count($before) < 2; $j--) {
            if (!in_array($items[$j]['type'], ['song', 'host'], true)) continue;
            $x = $asItem($items[$j], $j, $new[$items[$j]['id']] ?? []);
            if ($x['type'] === 'host') $x['break'] = ['kind' => $x['payload']['kind'], 'state' => 'ready', 'context' => [], 'texts' => [], 'source' => 'openai'];
            array_unshift($before, $x);
        }
        $memory = $app->showLog()->memory($before, $show, (int) $items[$start]['start'], $at, $zone, $name);

        $app->budget->restart(60);
        $t0 = microtime(true);
        $hb = ['id' => 0, 'kind' => $kind, 'channel_id' => (int) $main['id'], 'program_id' => null, 'context' => []];
        $written = $app->hostWriter()->write($hb, $ctx, $host, $memory) + ['seconds' => microtime(true) - $t0];
        // A fact told rests, as on air: the next moments tell another or none.
        $ref = $ctx['fact_refs'][$written['fact']] ?? null;
        if ($written['fact'] !== '' && is_array($ref)) $app->knowledge()->told((string) $ref['yt'], (int) $ref['i'], $at);
        $new[$it['id']] = $written['texts'];
        $spent++;
    }

    $line = sprintf("%s  %-11s %-18s %s%s\n", $local->format('H:i'), $kind, mb_substr((string) ($prog['title']['en'] ?? ''), 0, 18), $written['source'],
        $written['seconds'] > 0 ? sprintf(', %.1f s', $written['seconds']) : '');
    foreach (['en', 'de'] as $l) {
        if (isset($old[$l])) $line .= "  aired $l: {$old[$l]}\n";
        if (isset($written['texts'][$l]) && ($written['texts'][$l] !== ($old[$l] ?? null))) $line .= "  new   $l: {$written['texts'][$l]}\n";
    }
    $line .= '  delivery: ' . ($written['delivery'] ?: '(none)') . (($written['fact'] ?? '') !== '' ? "  · told the {$written['fact']} song's fact" : '') . "\n";
    $report[] = $line;
    echo $line, "\n";

    $id = $local->format('Hi') . '-' . $kind;
    foreach (['en', 'de'] as $l) {
        $v = $host['voices'][$l];
        if (isset($old[$l])) $cases[] = ['id' => "$id-$l-aired", 'lang' => $l, 'voice' => $v, 'text' => $old[$l], 'instruct' => $standing];
        if (isset($written['texts'][$l])) {
            $cases[] = ['id' => "$id-$l-new", 'lang' => $l, 'voice' => $v, 'text' => Speech::forVoice($written['texts'][$l], $l, $written['source'] === 'listener'),
                'instruct' => Speech::direction($standing, $written['delivery'])];
        }
    }
}

@mkdir($out, 0755, true);
file_put_contents("$out/replay.txt", implode("\n", $report));
file_put_contents("$out/cases.json", json_encode(['cases' => $cases], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$cost = array_sum(array_column($app->usage()->recent(1), 'cost_micros')) / 1e6;
printf("%d moments written again (%s, effort %s), about $%.3f. Side by side: %s/replay.txt; voice lab cases: %s/cases.json\n",
    $spent, $app->config->get('OPENAI_HOST_MODEL'), $app->config->get('HOST_EFFORT', 'low'), $cost, $out, $out);

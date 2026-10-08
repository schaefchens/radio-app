<?php
declare(strict_types=1);

namespace Arche\Host;

use Arche\ApiError;
use Arche\App;
use Arche\Library\Library;
use Arche\Plan\Catalog;
use Arche\Program\PrayerHour;

/**
 * "Test a moment" in /mod › Hosts: a moment of a real program, written by the
 * real writer with the editor's unsaved host, the station's own songs and
 * sample listeners, so a host can be tuned by ear before it airs. Each test
 * is added to a test show the editor keeps, and the next moment remembers it,
 * as on air (Host\ShowLog).
 *
 * Nothing of it reaches the program: no host break, no timeline item, only
 * the writer's usage (`host_try_<kind>`) and, when it is spoken, the voice's
 * (ModApi::hostTry).
 *
 * The test show comes from the editor, so only its moments, words and song
 * ids are taken. Whether a moment named a listener — and so is only
 * summarized in a later memory (ShowLog::quotable) — is the moment's own,
 * decided here, never the editor's to say.
 */
final class Scenarios
{
    /** Earlier test moments a test show keeps; its memory is cut at ShowLog::MAX_CHARS anyway. */
    public const EARLIER_MAX = 10;
    /** Efforts a test may use: `high` cannot finish within one request. */
    public const EFFORTS = ['low', 'medium'];
    /** Words of an earlier moment taken at most (a prayer hour's welcome runs to 1,100). */
    public const TEXT_MAX = 1200;

    /** A test's moment => the writer's kind ('video' is the program's own format). */
    private const KINDS = [
        'intro' => 'intro', 'break' => 'break', 'break_group' => 'break', 'reaction' => 'break', 'announce' => 'announce',
        'suggestion' => 'announce', 'video' => 'video', 'contrib' => 'contrib', 'invite' => 'prayer', 'reading' => 'reading',
        'intercession' => 'intercession', 'present' => 'present', 'prayertime' => 'prayertime', 'encourage' => 'encourage', 'outro' => 'outro',
    ];
    /** On air these named a listener: a later memory only summarizes them. */
    private const NAMING = ['announce' => 'request', 'suggestion' => 'request', 'contrib' => 'contribution', 'reaction' => 'previous_request'];
    /** How long things take in the test show's made-up time (ms). */
    private const SONG_MS = 240_000;
    private const HOST_MS = 30_000;

    /** Sample listeners (made up), as the forms send them. */
    private const ANNA = ['name' => 'Anna', 'place' => 'Köln', 'message' => 'Für meine Oma Gisela, die heute 90 wird – danke für alles!'];
    private const JONAS = ['name' => 'Jonas', 'place' => 'Hamburg', 'message' => 'Diese Botschaft hat mir in einer schweren Zeit Mut gemacht.'];
    private const MARIA = ['kind' => 'testimony', 'name' => 'Maria', 'place' => 'Graz', 'summary' => 'Maria erzählt, wie sie nach einem schweren Jahr wieder Hoffnung gefunden hat.'];
    /** People's own words, read out word for word: one per station language. */
    private const READ = [
        'reading' => [
            'de' => ['Lena aus Dresden', 'Bitte betet für meinen Bruder Tim, der seit einer Woche im Krankenhaus liegt. Dass er gesund wird und wir als Familie Kraft haben.'],
            'en' => ['Daniel from Leeds', 'Please pray for my mum, who starts a new treatment on Monday. That she feels peace and that the doctors find the right way.'],
        ],
        'intercession' => [
            'de' => ['Lena aus Dresden', 'Danke, Jesus, dass du bei Tim bist. Schenk ihm Kraft, Ruhe und Heilung, und gib seiner Familie Frieden.'],
            'en' => ['Daniel from Leeds', 'Lord, thank you that you know every worry. Please give my mum and everyone who is ill your peace.'],
        ],
    ];
    /** A group for "after a song of a group", when the library has none that wants it. */
    private const GROUP = ['name' => 'Grace Chapel', 'about' => ['en' => 'A church in Accra, Ghana, that shares its worship and preaching.', 'de' => 'Eine Gemeinde in Accra, Ghana, die ihren Lobpreis und ihre Predigten teilt.'], 'find' => ['youtube', 'website']];

    public function __construct(private App $app) {}

    /**
     * Every program of the station, with the moments a test can be in it.
     *
     * @return list<array{id:int,channel:string,title:array{en:string,de:string},format:string,moments:list<string>}>
     */
    public function list(): array
    {
        $catalog = $this->app->catalog();
        $out = [];
        foreach ($catalog->channels(false) as $ch) {
            foreach ($catalog->programs((int) $ch['id']) as $p) {
                $out[] = ['id' => (int) $p['id'], 'channel' => (string) ($ch['name_en'] ?? $ch['slug'] ?? ''), 'title' => ['en' => (string) $p['title_en'], 'de' => (string) $p['title_de']],
                    'format' => self::format($p), 'moments' => self::moments($p)];
            }
        }
        return $out;
    }

    /**
     * The moments a program has: its format's, and what listeners may send it.
     *
     * @param array<string,mixed> $program
     * @return list<string>
     */
    public static function moments(array $program): array
    {
        $format = self::format($program);
        $allowed = array_map('strval', (array) ($program['allowed'] ?? []));
        if ($format === 'prayer') return ['intro', 'present', 'reading', 'prayertime', 'intercession', 'encourage', 'outro'];
        if (Catalog::isVideoFormat($format)) {
            return ['intro', 'video', 'break', 'break_group', ...(in_array(Catalog::VIDEO_FORMATS[$format], $allowed, true) ? ['suggestion'] : []), 'outro'];
        }
        return [
            'intro', 'break', 'break_group',
            ...(in_array('song', $allowed, true) ? ['announce', 'reaction'] : []),
            ...(array_intersect(['story', 'testimony', 'greeting'], $allowed) ? ['contrib'] : []),
            ...(in_array('prayer', $allowed, true) ? ['reading', 'invite'] : []),
            'outro',
        ];
    }

    /**
     * A test moment, written as it would air.
     *
     * @param array<string,mixed> $host the editor's host with its unsaved changes (Hosts::draft)
     * @param array<mixed> $in program_id, moment, previous_id, next_id, effort, earlier (the test show)
     * @return array<string,mixed>
     */
    public function write(array $host, array $in): array
    {
        $catalog = $this->app->catalog();
        $program = $catalog->program((int) ($in['program_id'] ?? 0)) ?? throw new ApiError(422, 'scenario_program');
        $moment = (string) ($in['moment'] ?? '');
        if (!in_array($moment, self::moments($program), true)) throw new ApiError(422, 'scenario_moment');
        $channel = $catalog->channel((int) $program['channel_id']) ?? $catalog->mainChannel();
        $format = self::format($program);
        $kind = self::kind($moment, $format);
        $name = (string) $host['name'];
        $now = $this->app->clock->nowMs();
        $prayerHour = PrayerHour::applies($program);

        [$prev, $next, $group] = $this->around($moment, $program, $in);
        $hb = ['id' => 0, 'kind' => $kind, 'channel_id' => (int) $channel['id'], 'program_id' => (int) $program['id'], 'context' => []];
        // A test shows how the host tells a fact whenever the moment may tell one, whatever the air told last.
        $ctx = $this->app->hostWriter()->frame($hb, $name, $program, $channel, $now, $prev, $next, true);
        $ctx += $this->people($moment, $format, $ctx, $group);
        if ($prayerHour) $ctx += $this->prayerHour($kind, $program);
        if ($kind === 'outro' && ($after = $this->after($program)) !== null) $ctx['after'] = $after;
        $show = $this->show($moment, $kind, $program, $channel, $name, $this->earlier((array) ($in['earlier'] ?? []), $format), $prev, $now);

        $t0 = microtime(true);
        if (in_array($kind, HostWriter::READINGS, true)) {
            // People's own words: a lead-in and the text, never the model — as they air.
            $texts = [];
            foreach ($this->app->config->stationLangs() as $i => $l) {
                [$who, $text] = self::READ[$kind][$l] ?? self::READ[$kind]['en'];
                $texts[$l] = Templates::leadIn($kind === 'reading' ? 'request' : 'prayer', $l, $i, $who) . ' ' . $text;
            }
            $written = ['texts' => $texts, 'source' => 'listener', 'delivery' => Speech::fixedDelivery($kind)];
        } else {
            $effort = in_array($in['effort'] ?? null, self::EFFORTS, true) ? (string) $in['effort'] : null;
            $written = $this->app->hostWriter()->write($hb, $ctx, $host, $show, $effort, 'host_try_' . $kind);
        }
        $theirs = $written['source'] === 'listener';
        $spoken = [];
        foreach ($written['texts'] as $l => $t) $spoken[$l] = Speech::forVoice((string) $t, (string) $l, $theirs);
        return [
            'moment' => $moment,
            'kind' => $kind,
            'texts' => $written['texts'],
            'delivery' => $written['delivery'],
            'source' => $written['source'],
            'theirs' => $theirs,
            'seconds' => round(microtime(true) - $t0, 1),
            'spoken' => $spoken,
            'songs' => ['previous' => self::view($prev), 'next' => self::view($next)],
            'given' => ['moment' => HostWriter::forModel($ctx), 'show' => $show],
        ];
    }

    /** @param array<string,mixed> $program */
    private static function format(array $program): string
    {
        return (string) ($program['settings']['format'] ?? 'music');
    }

    private static function kind(string $moment, string $format): string
    {
        return $moment === 'video' ? $format : self::KINDS[$moment];
    }

    /**
     * What plays either side of the moment: the songs picked in the editor
     * (library ids), else random ones of the kind the moment is about — a
     * video of a video program's own kind where one is introduced or just
     * ended — and the group a "group" moment presents.
     *
     * @param array<string,mixed> $program
     * @param array<mixed> $in
     * @return array{0:?array<string,mixed>,1:?array<string,mixed>,2:?array<string,mixed>}
     */
    private function around(string $moment, array $program, array $in): array
    {
        $format = self::format($program);
        $video = Catalog::isVideoFormat($format);
        if (PrayerHour::applies($program)) {
            // The prayer hour: a song before its welcome (the program before), prayer music
            // or a song after it; silence after an encouragement; its other moments stand alone.
            $collect = (array) ($program['settings']['prayer']['collect'] ?? []);
            return match ($moment) {
                'intro' => [$this->song((int) ($in['previous_id'] ?? 0), 'song', $program),
                    (int) ($collect['songs'] ?? 0) > 0 ? $this->song((int) ($in['next_id'] ?? 0), 'song', $program) : self::item('bed', $program, 300_000), null],
                'encourage', 'intercession' => [null, self::item('silence', $program, 60_000), null],
                'outro' => [null, $this->song((int) ($in['next_id'] ?? 0), 'song', $program), null],
                default => [null, null, null],
            };
        }
        $before = $video && in_array($moment, ['break', 'break_group', 'outro'], true) ? $format : 'song';
        $after = $video && in_array($moment, ['intro', 'video', 'suggestion'], true) ? $format : 'song';
        $group = null;
        $prev = null;
        if ($moment === 'break_group') {
            // A group of the library that wants its moment, with one of its items before it; else a sample one.
            $gid = (int) ($this->app->store()->value('SELECT id FROM library_groups WHERE notice = 1 AND blocked = 0 ORDER BY RANDOM() LIMIT 1') ?? 0);
            $notice = $gid > 0 ? $this->app->groups()->notice($gid) : null;
            if ($notice !== null) {
                $group = ['name' => $notice['name'], 'about' => $notice['text'], 'find' => array_values(array_unique(array_map(fn(array $l) => (string) $l['kind'], $notice['links'])))];
                if ((int) ($in['previous_id'] ?? 0) <= 0) {
                    $row = $this->app->store()->one('SELECT * FROM library_items WHERE group_id = ? AND active = 1 ORDER BY RANDOM() LIMIT 1', [$gid]);
                    if ($row !== null) $prev = self::fromLibrary(Library::decode($row), $program);
                }
            }
            $group ??= self::GROUP;
        }
        $prev ??= $this->song((int) ($in['previous_id'] ?? 0), $before, $program);
        $next = $moment === 'outro' ? null : $this->song((int) ($in['next_id'] ?? 0), $after, $program);
        return [$prev, $next, $group];
    }

    /**
     * A library item as a timeline item: the one picked, else a random active
     * one of the kind (null when the library has none).
     *
     * @param array<string,mixed> $program
     * @return array<string,mixed>|null
     */
    private function song(int $id, string $kind, array $program): ?array
    {
        $row = $id > 0 ? $this->app->library()->get($id) : null;
        if ($row === null) {
            $raw = $this->app->store()->one('SELECT * FROM library_items WHERE kind = ? AND active = 1 ORDER BY RANDOM() LIMIT 1', [$kind]);
            $row = $raw !== null ? Library::decode($raw) : null;
        }
        return $row !== null ? self::fromLibrary($row, $program) : null;
    }

    /**
     * @param array<string,mixed> $row a decoded library item
     * @param array<string,mixed> $program
     * @return array<string,mixed>
     */
    private static function fromLibrary(array $row, array $program): array
    {
        $item = self::item('song', $program, (int) ($row['duration_ms'] ?? self::SONG_MS) ?: self::SONG_MS, [
            'kind' => (string) $row['kind'], 'title' => (string) $row['title'], 'artist' => (string) $row['artist'],
        ]);
        $item['library_id'] = (int) $row['id'];
        return $item;
    }

    /**
     * A timeline item of the test show (ShowLog::memory and HostWriter::frame read these).
     *
     * @param array<string,mixed> $program
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private static function item(string $type, array $program, int $durMs, array $payload = []): array
    {
        return ['type' => $type, 'state' => 'committed', 'program_id' => (int) $program['id'], 'submission_id' => null, 'library_id' => null,
            'start_ms' => 0, 'est_start' => 0, 'dur_ms' => $durMs, 'payload' => $payload];
    }

    /**
     * The listeners a moment is about — made up, as the forms would send them.
     *
     * @param array<string,mixed> $ctx the moment's frame
     * @param array<string,mixed>|null $group
     * @return array<string,mixed>
     */
    private function people(string $moment, string $format, array $ctx, ?array $group): array
    {
        return match ($moment) {
            'announce' => ['request' => self::ANNA],
            'suggestion' => ['request' => self::JONAS + ['type' => $format]],
            'reaction' => ['previous_request' => ['kind' => 'song request'] + self::ANNA + ['song' => $ctx['previous']]],
            'contrib' => ['contribution' => self::MARIA],
            'invite' => ['requests' => 2],
            'break_group' => $group !== null ? ['previous_group' => $group] : [],
            default => [],
        };
    }

    /**
     * What a prayer hour's moment knows besides (HostWriter::prayerHour): the
     * collection as the program sets it, intake open, a few requests and
     * prayers, Open Doors when the program reads it.
     *
     * @param array<string,mixed> $program
     * @return array<string,mixed>
     */
    private function prayerHour(string $kind, array $program): array
    {
        $s = (array) ($program['settings']['prayer'] ?? []);
        $collect = (array) ($s['collect'] ?? []);
        $open = ['requests' => 'open', 'prayers' => 'open'];
        return ['format' => 'prayer hour'] + match ($kind) {
            'intro' => ['collect' => ['songs' => (int) ($collect['songs'] ?? 0), 'minutes' => (int) ($collect['minutes'] ?? 10), 'music' => (int) ($collect['bed_id'] ?? 0) > 0], 'intake' => 'open'],
            'present' => ['requests' => 3] + (!empty($s['opendoors']) ? ['opendoors' => true] : []),
            'prayertime', 'encourage' => ['requests' => 3, 'intake' => $open],
            'outro' => ['requests' => 3, 'prayers' => 2, 'prayed_along' => 7],
            default => [],
        };
    }

    /**
     * Another program of the channel, for an outro to point to.
     *
     * @param array<string,mixed> $program
     * @return array{en:string,de:string}|null
     */
    private function after(array $program): ?array
    {
        foreach ($this->app->catalog()->programs((int) $program['channel_id'], true) as $p) {
            if ((int) $p['id'] !== (int) $program['id']) return ['en' => (string) $p['title_en'], 'de' => (string) $p['title_de']];
        }
        return null;
    }

    /**
     * The test show as the editor sent it, taken apart: per moment its id,
     * the song ids either side and its words in the station's languages —
     * nothing else, and no more than EARLIER_MAX of them.
     *
     * @param array<mixed> $earlier
     * @return list<array{moment:string,kind:string,previous_id:int,next_id:int,texts:array<string,string>}>
     */
    private function earlier(array $earlier, string $format): array
    {
        $out = [];
        foreach (array_slice(array_values($earlier), -self::EARLIER_MAX) as $e) {
            if (!is_array($e) || !isset(self::KINDS[$e['moment'] ?? ''])) continue;
            $texts = [];
            foreach ($this->app->config->stationLangs() as $l) {
                $t = trim((string) preg_replace('/\s+/u', ' ', (string) ($e['texts'][$l] ?? '')));
                if ($t !== '') $texts[$l] = mb_substr($t, 0, self::TEXT_MAX);
            }
            $moment = (string) $e['moment'];
            $out[] = ['moment' => $moment, 'kind' => self::kind($moment, $format), 'previous_id' => (int) ($e['previous_id'] ?? 0), 'next_id' => (int) ($e['next_id'] ?? 0), 'texts' => $texts];
        }
        return $out;
    }

    /**
     * The show so far, as ShowLog gives it on air: the test show's moments
     * with the songs either side, in order. Without any (and not opening the
     * program) a made-up start: the welcome in the station's own fallback
     * words and a few songs — in a prayer hour its running order up to here.
     *
     * @param array<string,mixed> $program
     * @param array<string,mixed> $channel
     * @param list<array{moment:string,kind:string,previous_id:int,next_id:int,texts:array<string,string>}> $earlier
     * @param array<string,mixed>|null $prev what plays right before the moment
     * @return array<string,mixed>
     */
    private function show(string $moment, string $kind, array $program, array $channel, string $hostName, array $earlier, ?array $prev, int $now): array
    {
        $items = [];
        $song = fn(int $id) => $id > 0 ? $this->song($id, 'song', $program) : null;
        $add = function (?array $item) use (&$items): void {
            if ($item === null) return;
            $last = $items ? $items[array_key_last($items)] : null;
            // A song is not heard twice in a row: the one after a moment is the one before the next.
            if ($last !== null && $item['type'] === 'song' && $last['type'] === 'song' && ($last['library_id'] ?? 0) === ($item['library_id'] ?? -1)) return;
            $items[] = $item;
        };
        if ($earlier !== []) {
            foreach ($earlier as $e) {
                $add($song($e['previous_id']));
                $add($this->spoken($e['kind'], $e['moment'], $e['texts'], $program, $hostName));
                $add($song($e['next_id']));
            }
        } elseif ($moment !== 'intro') {
            foreach ($this->start($kind, $program, $channel, $hostName) as $item) $add($item);
        }
        $add($prev);
        // Made-up times: the moment now, everything before it back to back.
        $at = $now - array_sum(array_map(fn($i) => (int) $i['dur_ms'], $items));
        foreach ($items as &$i) {
            $i['start_ms'] = $i['est_start'] = $at;
            $at += (int) $i['dur_ms'];
        }
        unset($i);
        $start = $items ? (int) $items[0]['start_ms'] : $now;
        $zone = $this->app->resolver()->zone($channel);
        // An intro of a fresh test show: what played before it belongs to the program before.
        if ($items === [] || ($moment === 'intro' && $earlier === [])) return $this->app->showLog()->memory($items, [], $now, $now, $zone, $hostName);
        return $this->app->showLog()->memory([], $items, $start, $now, $zone, $hostName);
    }

    /**
     * A host moment of the test show; one that named a listener on air keeps
     * that mark, so the memory only summarizes it.
     *
     * @param array<string,string> $texts
     * @param array<string,mixed> $program
     * @return array<string,mixed>
     */
    private function spoken(string $kind, string $moment, array $texts, array $program, string $hostName): array
    {
        $theirs = in_array($kind, [...HostWriter::READINGS, 'opening'], true);
        $context = isset(self::NAMING[$moment]) ? [self::NAMING[$moment] => ['test show']] : [];
        return self::item('host', $program, self::HOST_MS, ['kind' => $kind, 'text' => $texts, 'host' => ['name' => $hostName]])
            + ['break' => ['kind' => $kind, 'state' => 'ready', 'context' => $context, 'texts' => [], 'source' => $theirs ? 'listener' : 'model']];
    }

    /**
     * A made-up start, for a test show that has none: the welcome in the
     * station's own fallback words (Templates) and a couple of songs — or,
     * in a prayer hour, its running order up to this moment.
     *
     * @param array<string,mixed> $program
     * @param array<string,mixed> $channel
     * @return list<array<string,mixed>>
     */
    private function start(string $kind, array $program, array $channel, string $hostName): array
    {
        $title = ['en' => (string) $program['title_en'], 'de' => (string) $program['title_de']];
        $words = fn(string $k, array $c = []) => Templates::texts($k, ['program' => ['title' => $title]] + $c);
        $host = fn(string $k, array $c = []) => $this->spoken($k, $k, $words($k, $c), $program, $hostName);
        if (!PrayerHour::applies($program)) {
            $format = self::format($program);
            $first = Catalog::isVideoFormat($format) ? $this->song(0, $format, $program) : $this->song(0, 'song', $program);
            return array_values(array_filter([$host('intro'), $first, $this->song(0, 'song', $program)]));
        }
        $hour = ['format' => 'prayer hour'];
        $order = [
            $host('intro', $hour), self::item('bed', $program, 600_000),
            $host('present', $hour + ['requests' => 3]),
            self::item('host', $program, self::HOST_MS, ['kind' => 'reading']) + ['break' => ['kind' => 'reading', 'state' => 'ready', 'context' => [], 'texts' => [], 'source' => 'listener']],
            self::item('host', $program, self::HOST_MS, ['kind' => 'reading']) + ['break' => ['kind' => 'reading', 'state' => 'ready', 'context' => [], 'texts' => [], 'source' => 'listener']],
            $host('prayertime', $hour + ['requests' => 3]), self::item('silence', $program, 180_000),
        ];
        // Up to where the moment comes in the running order.
        $upTo = match ($kind) {
            'present' => 2,
            'reading' => 3,
            'prayertime' => 5,
            default => count($order),
        };
        return array_slice($order, 0, $upTo);
    }

    /**
     * A song as the editor shows it.
     *
     * @param array<string,mixed>|null $item
     * @return array{id:int,title:string,artist:string,kind:string}|null
     */
    private static function view(?array $item): ?array
    {
        if ($item === null || $item['type'] !== 'song') return null;
        return ['id' => (int) ($item['library_id'] ?? 0), 'title' => (string) $item['payload']['title'], 'artist' => (string) $item['payload']['artist'], 'kind' => (string) $item['payload']['kind']];
    }
}

<?php
declare(strict_types=1);

namespace Arche\Host;

use Arche\Plan\Catalog;

/**
 * What the host says when the model is unavailable (no key, budget reached,
 * refusal, timeout) or when its answer prays — the host never does: it
 * invites listeners to. Deliberately plain; they only need to be correct.
 *
 * Also the short lead-ins before people's own words, which are read out
 * exactly as they were written (HostWriter::reading()).
 */
final class Templates
{
    /**
     * A few of each, so the host does not say the same words before every
     * prayer. Without a first name ({who} is '') the anonymous pool: the
     * sender ticked "Stay anonymous", and the form sent neither.
     * {who}: first name and place; {where}: a country.
     */
    private const LEADS = [
        'prayer' => [
            'en' => ['{who} prays:', 'A prayer from {who}:', '{who} sent us this prayer:', 'Here is the prayer of {who}:', 'From {who}:'],
            'de' => ['{who} betet:', 'Ein Gebet von {who}:', '{who} hat uns dieses Gebet geschickt:', 'Hier ist das Gebet von {who}:', 'Von {who}:'],
        ],
        'prayer_anon' => [
            'en' => ['A prayer:', 'Someone prays:', 'A prayer from our community:', 'Someone sent us this prayer:'],
            'de' => ['Ein Gebet:', 'Jemand betet:', 'Ein Gebet aus unserer Gemeinde:', 'Jemand hat uns dieses Gebet geschickt:'],
        ],
        'request' => [
            'en' => ['{who} asks for prayer:', 'A prayer request from {who}:', '{who} writes:', '{who} asks us to pray:'],
            'de' => ['{who} bittet um Gebet:', 'Ein Gebetsanliegen von {who}:', '{who} schreibt:', '{who} bittet uns um Gebet:'],
        ],
        'request_anon' => [
            'en' => ['A prayer request:', 'Someone asks for prayer:', 'Another request:', 'Someone writes:', 'Someone asks us to pray:'],
            'de' => ['Ein Gebetsanliegen:', 'Jemand bittet um Gebet:', 'Ein weiteres Anliegen:', 'Jemand schreibt:', 'Jemand bittet uns um Gebet:'],
        ],
        'opendoors' => [
            'en' => ['A prayer request from Open Doors for persecuted Christians in {where}:', 'Open Doors asks us to pray for persecuted Christians in {where}:'],
            'de' => ['Ein Gebetsanliegen von Open Doors für verfolgte Christen in {where}:', 'Open Doors bittet um Gebet für verfolgte Christen in {where}:'],
        ],
        'opendoors_anywhere' => [
            'en' => ['A prayer request from Open Doors for persecuted Christians:', 'Open Doors asks us to pray for persecuted Christians:'],
            'de' => ['Ein Gebetsanliegen von Open Doors für verfolgte Christen:', 'Open Doors bittet um Gebet für verfolgte Christen:'],
        ],
    ];

    /**
     * A video of a video program, by kind: what the host calls it (en: a,
     * this; de, accusative: a, this, This at the start of a sentence), and
     * whether one listens or watches.
     */
    private const VIDEOS = [
        'preaching' => ['en' => ['a preaching', 'this preaching'], 'de' => ['eine Predigt', 'diese Predigt', 'Diese Predigt'], 'watch' => false],
        'testimony' => ['en' => ['a testimony', 'this testimony'], 'de' => ['ein Glaubenszeugnis', 'dieses Glaubenszeugnis', 'Dieses Glaubenszeugnis'], 'watch' => false],
        'mission' => ['en' => ['a report from mission work', 'this report from mission work'],
            'de' => ['einen Bericht aus der Mission', 'diesen Bericht aus der Mission', 'Diesen Bericht aus der Mission'], 'watch' => false],
        'film' => ['en' => ['a film', 'this film'], 'de' => ['einen Film', 'diesen Film', 'Diesen Film'], 'watch' => true],
    ];

    /** The $n-th lead-in of its kind: neighbours ($n, $n + 1) never share one. */
    public static function leadIn(string $case, string $lang, int $n, string $who = '', string $where = ''): string
    {
        $pools = self::LEADS[$case] ?? self::LEADS['request_anon'];
        $pool = $pools[$lang] ?? $pools['en'];
        return strtr($pool[abs($n) % count($pool)], ['{who}' => $who, '{where}' => $where]);
    }

    /** First name and place as the host says them: "Tom aus Berlin", "Tom"; '' without a name. */
    public static function who(string $name, string $place, string $lang): string
    {
        $name = trim($name);
        $place = trim($place);
        if ($name === '') return '';
        return $place === '' ? $name : $name . ($lang === 'de' ? ' aus ' : ' from ') . $place;
    }

    /** @param array<string,mixed> $c context from HostWriter::context() @return array<string,string> */
    public static function texts(string $kind, array $c): array
    {
        $texts = self::plain($kind, $c);
        // After an item of a group the stage now shows: them first, then the moment's own words.
        $group = self::group($c);
        if ($group === null) return $texts;
        return ['en' => $group['en'] . ' ' . $texts['en'], 'de' => $group['de'] . ' ' . $texts['de']];
    }

    /** Where there is more from a group, by the kinds of its links. */
    private const PLACES = [
        'en' => ['youtube' => 'their YouTube channel', 'website' => 'their website', 'other' => 'their other pages'],
        'de' => ['youtube' => 'auf ihrem YouTube-Kanal', 'website' => 'auf ihrer Website', 'other' => 'auf ihren anderen Seiten'],
    ];

    /**
     * A group, presented: who they are in the few words a moderator wrote
     * for listeners (`about`), and where there is more from them — by kind,
     * never an address, never "in the app" (the stage shows the links).
     *
     * @param array<string,mixed> $c
     * @return array<string,string>|null
     */
    private static function group(array $c): ?array
    {
        $g = (array) ($c['previous_group'] ?? []);
        $name = trim((string) ($g['name'] ?? ''));
        if ($name === '') return null;
        // Two ways to say it, the same for the same group and item: a group heard often is not presented alike every time.
        $v = crc32($name . '|' . (string) ($c['previous']['title'] ?? '')) % 2;
        $out = [];
        foreach (['en', 'de'] as $l) {
            $places = array_values(array_filter(array_map(fn($k) => self::PLACES[$l][(string) $k] ?? null, (array) ($g['find'] ?? []))));
            $where = implode($l === 'de' ? ' und ' : ' and ', $places);
            $about = trim((string) ($g['about'][$l] ?? ''));
            // Their words become the host's, and the host never prays: words that pray are left out.
            if ($about !== '' && HostWriter::prays($about)) $about = '';
            if ($about !== '' && !preg_match('/[.!?…]$/u', $about)) $about .= '.';
            $open = $l === 'de' ? ($v ? "Gerade habt ihr $name gehört." : "Das war $name.") : ($v ? "You just heard $name." : "That was $name.");
            $invite = match (true) {
                $l === 'de' && $where !== '' => $v ? "Wenn dich das angesprochen hat: Mehr von ihnen findest du $where." : "Mehr von ihnen gibt es $where zu entdecken.",
                $l === 'de' => $v ? 'Wenn dich das angesprochen hat: Von ihnen gibt es noch mehr zu entdecken.' : 'Von ihnen gibt es noch mehr zu entdecken.',
                $where !== '' => $v ? "If that spoke to you, you'll find more from them on $where." : "There's more from them to discover on $where.",
                default => $v ? "If that spoke to you, there's more from them to discover." : "There's more from them to discover.",
            };
            $out[$l] = trim("$open $about $invite");
            $out[$l] = (string) preg_replace('/\s+/u', ' ', $out[$l]);
        }
        return $out;
    }

    /** @param array<string,mixed> $c @return array<string,string> */
    private static function plain(string $kind, array $c): array
    {
        $program = $c['program']['title'] ?? ['en' => 'ARCHE', 'de' => 'ARCHE'];
        $next = $c['next'] ?? null;
        $req = $c['request'] ?? null;
        $name = $req['name'] ?? ($c['contribution']['name'] ?? '');
        $place = $req['place'] ?? ($c['contribution']['place'] ?? '');
        // No name, no place either: whoever stayed anonymous sent neither, and a place alone names nobody.
        $who = trim($name) !== '' ? trim($name . ($place !== '' ? ' (' . $place . ')' : '')) : '';
        // A video names who it is from where a song names its artist.
        $by = is_array($next) ? (string) ($next['artist'] ?? $next['by'] ?? '') : '';
        $nextTitle = is_array($next) ? trim(($next['title'] ?? '') . ($by !== '' ? ' – ' . $by : '')) : '';
        $after = $c['after'] ?? null;
        if (($c['format'] ?? '') === 'prayer hour') {
            $texts = self::prayerHour($kind, $c, $program, $after);
            if ($texts !== null) return $texts;
        }
        // A video program's own moment before its video, or a listener's
        // suggestion of one, announced.
        $suggested = $kind === 'announce' && Catalog::isVideoFormat($req['type'] ?? null);
        if (Catalog::isVideoFormat($kind) || $suggested) {
            return self::video($suggested ? (string) $req['type'] : $kind, $who, is_array($next) ? (string) ($next['title'] ?? '') : '', $by, $suggested);
        }
        // An intro or a break is pinned to the video after it: it introduces it.
        if (in_array($kind, ['intro', 'break'], true) && is_array($next) && Catalog::isVideoFormat($next['kind'] ?? null)) {
            $p = self::video((string) $next['kind'], '', (string) ($next['title'] ?? ''), $by, false);
            return $kind === 'intro'
                ? ['en' => "Welcome to {$program['en']} on ARCHE. " . $p['en'], 'de' => "Willkommen bei {$program['de']} auf ARCHE. " . $p['de']]
                : ['en' => "You're listening to ARCHE. " . $p['en'], 'de' => 'Ihr hört ARCHE. ' . $p['de']];
        }

        return match ($kind) {
            'intro' => [
                'en' => "Welcome to {$program['en']} on ARCHE. We're glad you're here with us.",
                'de' => "Willkommen bei {$program['de']} auf ARCHE. Schön, dass du dabei bist.",
            ],
            'outro' => [
                'en' => "Thank you for spending this time with us in {$program['en']}." . ($after ? " Stay with us for {$after['en']}." : ''),
                'de' => "Danke, dass du bei {$program['de']} dabei warst." . ($after ? " Bleib dran für {$after['de']}." : ''),
            ],
            'announce' => [
                'en' => $who !== '' ? "This next song is a request from $who." : 'This next song is a listener request.',
                'de' => $who !== '' ? "Das nächste Lied hat sich $who gewünscht." : 'Das nächste Lied ist ein Hörerwunsch.',
            ],
            'contrib' => [
                'en' => $who !== '' ? "Now let's listen to $who." : "Now let's listen to one of our listeners.",
                'de' => $who !== '' ? "Jetzt hören wir $who." : 'Jetzt hören wir einen unserer Hörer.',
            ],
            // After the requests were read out word for word: an invitation, never a prayer.
            'prayer' => ((int) ($c['requests'] ?? 1)) > 1 ? [
                'en' => 'Take a moment to pray for these requests. On the prayer wall in the app you can pray along.',
                'de' => 'Nimm dir einen Moment und bete für diese Anliegen. An der Gebetswand in der App kannst du mitbeten.',
            ] : [
                'en' => 'Take a moment to pray for this request. On the prayer wall in the app you can pray along.',
                'de' => 'Nimm dir einen Moment und bete für dieses Anliegen. An der Gebetswand in der App kannst du mitbeten.',
            ],
            default => [
                'en' => $nextTitle !== '' ? "You're listening to ARCHE. Up next: $nextTitle." : "You're listening to ARCHE. Stay with us.",
                'de' => $nextTitle !== '' ? "Ihr hört ARCHE. Als Nächstes: $nextTitle." : 'Ihr hört ARCHE. Bleibt dran.',
            ],
        };
    }

    /**
     * A video introduced: the program's own, or a listener's suggestion
     * ($suggested; by their first name and place, or — they stayed
     * anonymous — by "a listener", still as a suggestion).
     *
     * @param string $kind a video format
     * @return array<string,string>
     */
    private static function video(string $kind, string $who, string $title, string $by, bool $suggested): array
    {
        $v = self::VIDEOS[$kind] ?? self::VIDEOS['preaching'];
        // A preaching is by its preacher; anything else from a person, a ministry or a studio.
        $en = $title !== '' ? "“{$title}”" . ($by !== '' ? ($kind === 'preaching' ? " by $by" : " from $by") : '') : '';
        $de = $title !== '' ? "„{$title}“" . ($by !== '' ? " von $by" : '') : '';
        if ($suggested) {
            $together = $v['watch'] ? ['en' => 'Let us watch together.', 'de' => 'Schauen wir gemeinsam zu.'] : ['en' => 'Let us listen together.', 'de' => 'Hören wir gemeinsam zu.'];
            return [
                'en' => ($who !== '' ? "$who suggested {$v['en'][1]} for us" : "A listener suggested {$v['en'][1]} for us") . ($en !== '' ? ": $en." : '.') . ' ' . $together['en'],
                'de' => ($who !== '' ? "$who hat uns {$v['de'][1]} empfohlen" : "{$v['de'][2]} hat uns jemand aus unserer Hörerschaft empfohlen") . ($de !== '' ? ": $de." : '.') . ' ' . $together['de'],
            ];
        }
        $lead = $v['watch']
            ? ['en' => "Now let us watch {$v['en'][0]} together", 'de' => "Jetzt sehen wir gemeinsam {$v['de'][0]}"]
            : ['en' => "Now let us listen to {$v['en'][0]}", 'de' => "Jetzt hören wir {$v['de'][0]}"];
        return [
            'en' => $en !== '' ? "{$lead['en']}: $en." : "{$lead['en']}.",
            'de' => $de !== '' ? "{$lead['de']}: $de." : "{$lead['de']}.",
        ];
    }

    /**
     * The prayer hour's moments: explaining, presenting and inviting, never
     * praying. What listeners may send follows `intake`.
     *
     * @param array<string,mixed> $c
     * @param array<string,string> $program
     * @param array<string,string>|null $after
     * @return array<string,string>|null null: the usual text
     */
    private static function prayerHour(string $kind, array $c, array $program, ?array $after): ?array
    {
        $intake = (array) ($c['intake'] ?? []);
        $prayersOpen = in_array($intake['prayers'] ?? 'closed', ['open', 'closing'], true);
        $requests = (array) ($c['prayers'] ?? []);
        $names = array_values(array_filter(array_map(fn($r) => trim((string) ($r['name'] ?? '')), $requests)));
        $wall = count(array_filter($requests, fn($r) => !empty($r['on_wall']) && trim((string) ($r['name'] ?? '')) === '')) > 0;
        $list = fn(array $n, string $and) => count($n) > 1 ? implode(', ', array_slice($n, 0, -1)) . " $and " . end($n) : ($n[0] ?? '');
        $by = trim((string) ($c['opening_by'] ?? ''));
        return match ($kind) {
            'intro' => [
                'en' => "Welcome to {$program['en']} on ARCHE, a time to pray for one another. Share your prayer request now with the button in the app. "
                    . 'Soon we read every request out, and then you can send your own prayer for them.' . ($by !== '' ? " $by opens our hour with a prayer." : ''),
                'de' => "Willkommen bei {$program['de']} auf ARCHE, einer Zeit, in der wir füreinander beten. Teile jetzt dein Gebetsanliegen über den Button in der App. "
                    . 'Gleich lesen wir alle Anliegen vor, und dann kannst du dein eigenes Gebet dafür schicken.' . ($by !== '' ? " $by eröffnet unsere Stunde mit einem Gebet." : ''),
            ],
            'present' => [
                'en' => 'These are the prayer requests that have reached us.',
                'de' => 'Das sind die Gebetsanliegen, die uns erreicht haben.',
            ],
            'prayertime' => $prayersOpen ? [
                'en' => 'Now it is time to pray. Send your prayer for these requests with the Pray button, spoken or written. We share them here.',
                'de' => 'Jetzt ist Zeit zum Beten. Schick dein Gebet für diese Anliegen über den Button „Beten“, gesprochen oder geschrieben. Wir teilen es hier.',
            ] : [
                'en' => 'Now it is time to pray. Take a moment for these requests, wherever you are.',
                'de' => 'Jetzt ist Zeit zum Beten. Nimm dir einen Moment für diese Anliegen, wo immer du gerade bist.',
            ],
            'encourage' => $prayersOpen ? [
                'en' => 'Look at the requests on our prayer wall and pray for what moves you. You can send your prayer with the Pray button.',
                'de' => 'Schau dir die Anliegen an unserer Gebetswand an und bete für das, was dich bewegt. Dein Gebet kannst du über den Button „Beten“ schicken.',
            ] : [
                'en' => 'Look at the requests on our prayer wall and pray for what moves you.',
                'de' => 'Schau dir die Anliegen an unserer Gebetswand an und bete für das, was dich bewegt.',
            ],
            'invite' => [
                'en' => 'What would you like prayer for? Share your prayer request now with the button in the app. In a few minutes we read every request out.',
                'de' => 'Wofür sollen wir beten? Teile jetzt dein Gebetsanliegen über den Button in der App. In ein paar Minuten lesen wir jedes Anliegen vor.',
            ],
            // A moment of an hour planned before this order existed.
            'prayer' => match (true) {
                $names !== [] || $wall => [
                    'en' => 'Please pray with us for ' . implode(' and ', array_filter([$list($names, 'and'), $wall ? 'the requests on our prayer wall' : ''])) . '. Take a moment in the quiet.',
                    'de' => 'Bete mit für ' . implode(' und ', array_filter([$list($names, 'und'), $wall ? 'die Anliegen an unserer Gebetswand' : ''])) . '. Nimm dir einen Moment in der Stille.',
                ],
                default => [
                    'en' => 'Take a moment in the quiet to pray for everyone listening, for the sick and the lonely.',
                    'de' => 'Nimm dir einen Moment in der Stille und bete für alle, die jetzt zuhören, für die Kranken und die Einsamen.',
                ],
            },
            'outro' => [
                'en' => "Thank you for every request and every prayer in {$program['en']}." . ($after ? " Stay with us for {$after['en']}." : ''),
                'de' => "Danke für jedes Anliegen und jedes Gebet in {$program['de']}." . ($after ? " Bleib dran für {$after['de']}." : ''),
            ],
            default => null,
        };
    }
}

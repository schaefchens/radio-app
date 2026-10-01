<?php
declare(strict_types=1);

namespace Arche\Host;

/**
 * What the host says when Claude is unavailable (no key, budget reached,
 * refusal, timeout). Deliberately plain; they only need to be correct.
 */
final class Templates
{
    /** @param array<string,mixed> $c context from HostWriter::context() @return array<string,string> */
    public static function texts(string $kind, array $c): array
    {
        $program = $c['program']['title'] ?? ['en' => 'ARCHE', 'de' => 'ARCHE'];
        $next = $c['next'] ?? null;
        $req = $c['request'] ?? null;
        $name = $req['name'] ?? ($c['contribution']['name'] ?? '');
        $place = $req['place'] ?? ($c['contribution']['place'] ?? '');
        $who = trim($name . ($place !== '' ? ' (' . $place . ')' : ''));
        $nextTitle = is_array($next) ? trim(($next['title'] ?? '') . (($next['artist'] ?? '') !== '' ? ' – ' . $next['artist'] : '')) : '';
        $after = $c['after'] ?? null;
        if (($c['format'] ?? '') === 'prayer hour') {
            $texts = self::prayerHour($kind, $c, $program, $after);
            if ($texts !== null) return $texts;
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
            'prayer' => [
                'en' => 'Let us pray together for everyone who shared a prayer request with us today. Lord, hear our prayers. Amen.',
                'de' => 'Lasst uns gemeinsam für alle beten, die uns heute ein Gebetsanliegen geschickt haben. Herr, erhöre unsere Gebete. Amen.',
            ],
            default => [
                'en' => $nextTitle !== '' ? "You're listening to ARCHE. Up next: $nextTitle." : "You're listening to ARCHE. Stay with us.",
                'de' => $nextTitle !== '' ? "Ihr hört ARCHE. Als Nächstes: $nextTitle." : 'Ihr hört ARCHE. Bleibt dran.',
            ],
        };
    }

    /**
     * The prayer hour's moments. A prayer names the requests it may name and
     * speaks of the wall's without their senders: a template still prays for
     * the requests its moment is marked aired for.
     *
     * @param array<string,mixed> $c
     * @param array<string,string> $program
     * @param array<string,string>|null $after
     * @return array<string,string>|null null: the usual text
     */
    private static function prayerHour(string $kind, array $c, array $program, ?array $after): ?array
    {
        $tod = (string) ($c['time_of_day_de'] ?? 'Tag');
        $blessing = $tod === 'Nacht' ? 'eine gesegnete Nacht' : ($tod === 'Mittag' ? 'einen gesegneten Mittag' : "einen gesegneten $tod");
        $requests = (array) ($c['prayers'] ?? []);
        $names = array_values(array_filter(array_map(fn($r) => empty($r['on_wall']) ? trim((string) ($r['name'] ?? '')) : '', $requests)));
        $wall = count(array_filter($requests, fn($r) => !empty($r['on_wall']))) > 0;
        $list = fn(array $n, string $and) => count($n) > 1 ? implode(', ', array_slice($n, 0, -1)) . " $and " . end($n) : ($n[0] ?? '');
        return match ($kind) {
            'intro' => ($by = trim((string) ($c['opening_by'] ?? ''))) !== '' ? [
                'en' => "Welcome to {$program['en']} on ARCHE. $by opens our time of prayer.",
                'de' => "Willkommen bei {$program['de']} auf ARCHE. $by eröffnet unsere Gebetszeit.",
            ] : [
                'en' => "Welcome to {$program['en']} on ARCHE. Let us pray together in this hour.",
                'de' => "Willkommen bei {$program['de']} auf ARCHE. Lasst uns in dieser Stunde miteinander beten.",
            ],
            'opening' => [
                'en' => 'Lord, we come before you in this hour. You know what is on our hearts. Be with us as we pray together. Amen.',
                'de' => 'Herr, wir kommen in dieser Stunde zu dir. Du weißt, was uns bewegt. Sei bei uns, wenn wir jetzt miteinander beten. Amen.',
            ],
            'invite' => [
                'en' => 'What would you like us to pray for? Share your prayer request now with the button in the app. In a few minutes we will pray for every request together.',
                'de' => 'Wofür dürfen wir beten? Teile jetzt dein Gebetsanliegen über den Button in der App. In ein paar Minuten beten wir gemeinsam für jedes Anliegen.',
            ],
            'prayer' => match (true) {
                $names !== [] || $wall => [
                    'en' => 'Let us pray for ' . implode(' and ', array_filter([$list($names, 'and'), $wall ? 'the requests on our prayer wall' : ''])) . '. Lord, you know what moves them. Hear our prayers. Amen.',
                    'de' => 'Lasst uns beten für ' . implode(' und ', array_filter([$list($names, 'und'), $wall ? 'die Anliegen an unserer Gebetswand' : ''])) . '. Herr, du weißt, was sie bewegt. Erhöre unsere Gebete. Amen.',
                ],
                ($c['phase'] ?? '') === 'open' => [
                    'en' => 'Let us begin our time of prayer. Lord, we bring before you everyone who is listening and all that is on our hearts. Amen.',
                    'de' => 'Lasst uns unsere Gebetszeit beginnen. Herr, wir bringen alle vor dich, die jetzt zuhören, und alles, was uns bewegt. Amen.',
                ],
                default => [
                    'en' => 'Let us pray in silence for everyone who is listening, for the sick and the lonely. Lord, hear our prayers. Amen.',
                    'de' => 'Lasst uns in der Stille für alle beten, die jetzt zuhören, für die Kranken und die Einsamen. Herr, erhöre unsere Gebete. Amen.',
                ],
            },
            'outro' => [
                'en' => "Thank you for praying with us in {$program['en']}. May God bless you and keep you, wherever you are. Amen." . ($after ? " Stay with us for {$after['en']}." : ''),
                'de' => "Danke, dass du mit uns in {$program['de']} gebetet hast. Gott segne und behüte dich – $blessing. Amen." . ($after ? " Bleib dran für {$after['de']}." : ''),
            ],
            default => null,
        };
    }
}

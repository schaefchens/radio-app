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
        $prayerHour = ($c['format'] ?? '') === 'prayer hour';
        $tod = (string) ($c['time_of_day_de'] ?? 'Tag');
        $blessing = $tod === 'Nacht' ? 'eine gesegnete Nacht' : "einen gesegneten $tod";

        return match ($kind) {
            'intro' => [
                'en' => "Welcome to {$program['en']} on ARCHE. We're glad you're here with us.",
                'de' => "Willkommen bei {$program['de']} auf ARCHE. Schön, dass du dabei bist.",
            ],
            'outro' => $prayerHour ? [
                'en' => "Thank you for praying with us in {$program['en']}. May God bless you and keep you, wherever you are. Amen." . ($after ? " Stay with us for {$after['en']}." : ''),
                'de' => "Danke, dass du mit uns in {$program['de']} gebetet hast. Gott segne und behüte dich – $blessing. Amen." . ($after ? " Bleib dran für {$after['de']}." : ''),
            ] : [
                'en' => "Thank you for spending this time with us in {$program['en']}." . ($after ? " Stay with us for {$after['en']}." : ''),
                'de' => "Danke, dass du bei {$program['de']} dabei warst." . ($after ? " Bleib dran für {$after['de']}." : ''),
            ],
            'opening' => [
                'en' => 'Lord, we come before you in this hour. You know what is on our hearts. Be with us as we pray together. Amen.',
                'de' => 'Herr, wir kommen in dieser Stunde zu dir. Du weißt, was uns bewegt. Sei bei uns, wenn wir jetzt miteinander beten. Amen.',
            ],
            'invite' => [
                'en' => 'What would you like us to pray for? Send us your prayer request now with the Prayer Request button. In a few minutes we will pray for every request together.',
                'de' => 'Wofür dürfen wir beten? Schick uns jetzt dein Anliegen über den Button „Gebetsanliegen“. In ein paar Minuten beten wir gemeinsam für jedes Anliegen.',
            ],
            'announce' => [
                'en' => $who !== '' ? "This next song is a request from $who." : 'This next song is a listener request.',
                'de' => $who !== '' ? "Das nächste Lied hat sich $who gewünscht." : 'Das nächste Lied ist ein Hörerwunsch.',
            ],
            'contrib' => [
                'en' => $who !== '' ? "Now let's listen to $who." : "Now let's listen to one of our listeners.",
                'de' => $who !== '' ? "Jetzt hören wir $who." : 'Jetzt hören wir einen unserer Hörer.',
            ],
            'prayer' => in_array($c['phase'] ?? '', ['again', 'general'], true) || (!empty($c['first']) && empty($c['prayers'])) ? [
                'en' => 'Let us pray in silence for everyone who is listening, and for all who shared a prayer request with us. Lord, hear our prayers. Amen.',
                'de' => 'Lasst uns in der Stille für alle beten, die jetzt zuhören, und für alle, die uns ein Anliegen geschickt haben. Herr, erhöre unsere Gebete. Amen.',
            ] : [
                'en' => 'Let us pray together for everyone who shared a prayer request with us today. Lord, hear our prayers. Amen.',
                'de' => 'Lasst uns gemeinsam für alle beten, die uns heute ein Gebetsanliegen geschickt haben. Herr, erhöre unsere Gebete. Amen.',
            ],
            default => [
                'en' => $nextTitle !== '' ? "You're listening to ARCHE. Up next: $nextTitle." : "You're listening to ARCHE. Stay with us.",
                'de' => $nextTitle !== '' ? "Ihr hört ARCHE. Als Nächstes: $nextTitle." : 'Ihr hört ARCHE. Bleibt dran.',
            ],
        };
    }
}

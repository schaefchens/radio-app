<?php
declare(strict_types=1);

namespace Arche\Host;

/**
 * What a voice is given to speak, apart from what listeners read.
 *
 * Qwen3-TTS (our voice workers) reads exactly what it gets: a dash between
 * the parts of a YouTube title became a pause in the wrong place,
 * "@SOLUIsrael" and "#ChristianMusic" were read out, an emoji or a word in
 * another alphabet came out as noise, and "EVERY TRIBE" risks being spelled.
 * So every clip's text passes forVoice() right before it is made; the stage
 * still shows the words as they were written (payload.text).
 *
 * The writer is given titles as a host would say them (title()), and each
 * moment carries a line on how it should sound (its delivery): written by
 * the model with the words, or fixed here for words the model does not write.
 */
final class Speech
{
    /** A delivery longer than this is cut: it joins the host's own direction, which may be 500 characters already. */
    private const DELIVERY_MAX = 200;
    /** Words the model does not write: people's own, read out, and a moderator's opening prayer. */
    private const DELIVERIES = [
        'reading' => 'Calm, gentle and compassionate; read slowly and clearly, with a short pause after the opening words.',
        'intercession' => 'Calm, gentle and heartfelt; read slowly and clearly, with a short pause after the opening words.',
        'opening' => 'Calm, warm and reverent; unhurried, with room between the sentences.',
    ];
    /** A recorded line's mood (Lines::MOODS), as its recording is told to sound. */
    private const MOODS = [
        'calm' => 'Calm and unhurried, softly warm.',
        'joyful' => 'Glad and bright, with a smile in the voice; lively but not rushed.',
        'hopeful' => 'Hopeful and encouraging, warm, with a gentle lift.',
        'reflective' => 'Quiet and thoughtful, slow, with gentle pauses.',
        'warm' => 'Warm and friendly, at a relaxed pace.',
    ];
    /** Emoji, pictographs, arrows, flags and the joiners and selectors that build them. */
    private const PICTOGRAPHS = '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{2300}-\x{23FF}\x{2190}-\x{21FF}\x{FE00}-\x{FE0F}\x{200D}\x{20E3}\x{E0020}-\x{E007F}]/u';
    /** Letters no Latin-script voice can say (Hebrew, Cyrillic, Chinese …); digits and punctuation are "Common". */
    private const OTHER_SCRIPTS = '/[^\p{Latin}\p{Common}\p{Inherited}]+/u';
    /** A video's label in brackets, never part of the song's name. */
    private const LABELS = '/\s*[\(\[][^\)\]]*\b(?:official|offiziell|video|lyrics?|songtext|audio|visuali[sz]er|live|hd|4k|mv|clip|musikvideo)\b[^\)\]]*[\)\]]/iu';
    /** @var array<string,array<string,string>> written out, per language (the pattern is matched case-insensitively) */
    private const ABBREVIATIONS = [
        'de' => ['/\bz\.\s?B\./u' => 'zum Beispiel', '/\bd\.\s?h\./u' => 'das heißt', '/\bbzw\./u' => 'beziehungsweise', '/\bca\./u' => 'circa',
            '/\busw\./u' => 'und so weiter', '/\b(?:feat|ft)\./iu' => 'mit', '/\bNr\.\s?(?=\d)/u' => 'Nummer '],
        'en' => ['/\be\.\s?g\./u' => 'for example', '/\bi\.\s?e\./u' => 'that is', '/\bvs\./iu' => 'versus', '/\b(?:feat|ft)\./iu' => 'featuring',
            '/\bNo\.\s?(?=\d)/u' => 'number '],
    ];

    /**
     * The text a voice speaks. `$theirs`: people's own words (a reading, a
     * prayer read out), which lose typography only — never a word or a whole
     * script: they are read as they were written, and a request in another
     * alphabet emptied here would fail its length check and pass from host
     * to host.
     */
    public static function forVoice(string $text, string $lang, bool $theirs = false): string
    {
        $and = $lang === 'de' ? 'und' : 'and';
        $t = (string) preg_replace(self::PICTOGRAPHS, ' ', $text);
        if (!$theirs) {
            $t = (string) preg_replace(self::OTHER_SCRIPTS, ' ', $t);
            // "@SOLUIsrael" is an address, not a word; "#ChristianMusic" is two words.
            $t = (string) preg_replace('/@[\p{L}\p{N}_.]+/u', ' ', $t);
            $t = (string) preg_replace_callback('/#([\p{L}\p{N}_]+)/u', fn(array $m) => (string) preg_replace('/(?<=\p{Ll})(?=\p{Lu})/u', ' ', $m[1]), $t);
        }
        // People's own "#Glaube", "@Jenny": the word, without its sign.
        $t = (string) preg_replace('/[#@](?=[\p{L}\p{N}])/u', '', $t);
        // A dash between phrases or a "|" between a title's parts: a short pause, not a long one.
        $t = (string) preg_replace('/\s*\|\s*|\s+[-–—]+\s+|\s*[–—]\s*/u', ', ', $t);
        $t = (string) preg_replace('/\s*&\s*/u', " $and ", $t);
        if (!$theirs) {
            // "Deutsch / Aramäisch" — between words only: "24/7" and dates stay.
            $t = (string) preg_replace('/(?<=\p{L})\s*\/\s*(?=\p{L})/u', " $and ", $t);
            foreach (self::ABBREVIATIONS[$lang] ?? [] as $pattern => $words) $t = (string) preg_replace($pattern, $words, $t);
            // EVERY TRIBE, WOHL DEM, ARCHE: words, not letters. A short one alone stays (ERF, USA).
            $title = fn(array $m) => mb_convert_case($m[0], MB_CASE_TITLE);
            $t = (string) preg_replace_callback('/\b\p{Lu}{2,}\b(?:[\s,]+\b\p{Lu}{2,}\b)+/u', $title, $t);
            $t = (string) preg_replace_callback('/\b\p{Lu}{4,}\b/u', $title, $t);
        }
        // Brackets read as the asides they are.
        $t = (string) preg_replace('/\s*[\(\[\{]\s*|\s*[\)\]\}]/u', ', ', $t);
        // Quotation marks are not spoken (apostrophes are part of words: God's, Jenny’s).
        $t = (string) preg_replace('/["„“”«»‹›‚‘]/u', '', $t);
        // "WANDELT…." and "Gnade... für": one pause, not a trailing take.
        $t = (string) preg_replace('/\s*(?:\.{2,}|…)+\s*(?=\p{Ll})/u', ', ', $t);
        $t = (string) preg_replace('/(?:\.{2,}|…)+/u', '.', $t);
        // "!!!", "?!", "Krebs.!": one sentence end — a question stays a question.
        $t = (string) preg_replace_callback('/[.!?](?:\s*[.!?])+/u', fn(array $m) => str_contains($m[0], '?') ? '?' : (str_contains($m[0], '!') ? '!' : '.'), $t);
        // Nothing left (all of it in another alphabet): the words as they came, rather than silence.
        $out = self::tidy($t);
        return $out !== '' ? $out : trim($text);
    }

    /**
     * A song's or video's title (or artist) as the writer is given it:
     * without what YouTube adds — a label in brackets, everything after a
     * "|", hashtags, handles, emoji, another alphabet, trailing dots. The
     * library keeps its own title; what is left may still be swapped with the
     * artist, which the writer is told.
     */
    public static function title(string $title): string
    {
        $t = (string) preg_replace(self::PICTOGRAPHS, ' ', $title);
        $parts = array_values(array_filter(array_map('trim', preg_split('/\|/u', $t) ?: [$t]), fn(string $p) => $p !== ''));
        $t = $parts[0] ?? '';
        $t = (string) preg_replace(self::LABELS, '', $t);
        $t = (string) preg_replace('/[#@][\p{L}\p{N}_]+/u', ' ', $t);
        $t = (string) preg_replace(self::OTHER_SCRIPTS, ' ', $t);
        $t = (string) preg_replace('/(?:\.{2,}|…)+/u', ' ', $t);
        // mb_trim: trim() takes its list byte by byte, and the "–" in it cut the
        // „ opening a title in half — invalid UTF-8, the moment's JSON came out
        // empty, and the model asked on air for its data (2026-10-08).
        $t = mb_trim((string) preg_replace('/\s+/u', ' ', $t), " \t-–—:|,;/");
        return $t !== '' ? $t : trim($title);
    }

    /**
     * The model's line on how a moment should sound, made safe to hand on:
     * one line, no quotation marks or brackets, capped. A line that names a
     * listener of this moment is dropped — erasure finds a moment by its
     * context, and what a voice task was told must not keep a name.
     *
     * @param array<string,mixed> $context the moment's context (HostWriter::context)
     */
    public static function delivery(mixed $raw, array $context = []): string
    {
        $d = is_string($raw) ? trim((string) preg_replace('/\s+/u', ' ', strip_tags($raw))) : '';
        $d = trim((string) preg_replace('/["„“”«»‹›\[\]\{\}<>]/u', '', $d));
        if ($d === '') return '';
        foreach (self::personal($context) as $word) {
            if (mb_stripos($d, $word) !== false) return '';
        }
        if (mb_strlen($d) > self::DELIVERY_MAX) {
            $cut = mb_substr($d, 0, self::DELIVERY_MAX);
            $space = mb_strrpos($cut, ' ');
            $d = rtrim($space !== false ? mb_substr($cut, 0, $space) : $cut, ' ,;:') . '.';
        }
        return $d;
    }

    /** How words the model does not write are told to sound ('' for any other kind). */
    public static function fixedDelivery(string $kind): string
    {
        return self::DELIVERIES[$kind] ?? '';
    }

    /** How a recorded line of this mood is told to sound ('' without one). */
    public static function moodDelivery(string $mood): string
    {
        return self::MOODS[$mood] ?? '';
    }

    /**
     * What a voice is directed by: the host's own, standing direction, then
     * this moment's delivery. The host's direction is never cut.
     */
    public static function direction(string $base, string $delivery): string
    {
        $base = trim($base);
        $delivery = trim($delivery);
        if ($delivery === '') return $base;
        if ($base === '') return $delivery;
        return (preg_match('/[.!?…]$/u', $base) ? $base : $base . '.') . ' ' . $delivery;
    }

    /**
     * First names and places of the listeners a moment's context names:
     * none of them may appear in its delivery.
     *
     * @param array<string,mixed> $c
     * @return list<string>
     */
    private static function personal(array $c): array
    {
        $people = [];
        foreach (['request', 'contribution', 'previous_request'] as $k) {
            if (is_array($c[$k] ?? null)) $people[] = $c[$k];
        }
        foreach (['community', 'prayers'] as $k) {
            if (!is_array($c[$k] ?? null)) continue;
            foreach ($c[$k] as $p) {
                if (is_array($p)) $people[] = $p;
            }
        }
        $words = [];
        foreach ($people as $p) {
            foreach (['name', 'place', 'country'] as $f) {
                $w = trim((string) ($p[$f] ?? ''));
                if (mb_strlen($w) >= 2) $words[] = $w;
            }
        }
        return array_values(array_unique($words));
    }

    /** Spaces and commas set right, and an end the voice can hear. */
    private static function tidy(string $t): string
    {
        $t = (string) preg_replace('/\s+/u', ' ', $t);
        $t = (string) preg_replace('/\s+([,.;:!?])/u', '$1', $t);
        $t = (string) preg_replace('/,(?:\s*,)+/u', ',', $t);
        $t = (string) preg_replace('/,\s*([.;:!?])/u', '$1', $t);
        $t = (string) preg_replace('/([.;:!?])\s*,/u', '$1', $t);
        $t = (string) preg_replace('/,(?=\S)/u', ', ', $t);
        $t = trim($t, " ,;");
        if ($t === '') return '';
        return preg_match('/[.!?…:]$/u', $t) ? $t : $t . '.';
    }
}

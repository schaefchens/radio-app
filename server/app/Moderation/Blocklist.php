<?php
declare(strict_types=1);

namespace Arche\Moderation;

use Arche\Store;

/**
 * The moderators' word list (/mod → Chat): lower-case pieces of text a chat
 * message may not contain (the realtime node checks those, realtime/src/hub.ts)
 * — and since the stores asked, a display name neither: a name is shown to
 * the whole room next to every message.
 */
final class Blocklist
{
    /** @return list<string> */
    public static function words(Store $store): array
    {
        return array_values(array_filter(array_map('strval', (array) ($store->get('chat_blocklist') ?? [])), fn($w) => $w !== ''));
    }

    public static function matches(Store $store, string $text): bool
    {
        $text = mb_strtolower($text);
        foreach (self::words($store) as $w) {
            if (str_contains($text, $w)) return true;
        }
        return false;
    }
}

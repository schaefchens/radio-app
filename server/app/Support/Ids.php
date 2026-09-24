<?php
declare(strict_types=1);

namespace Arche\Support;

final class Ids
{
    private const ALPHABET = '0123456789abcdefghjkmnpqrstvwxyz';

    /** Short random id from an unambiguous base-32 alphabet. */
    public static function short(int $length = 10): string
    {
        $out = '';
        $bytes = random_bytes($length);
        for ($i = 0; $i < $length; $i++) $out .= self::ALPHABET[ord($bytes[$i]) & 31];
        return $out;
    }

    public static function hex(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }
}

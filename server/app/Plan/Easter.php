<?php
declare(strict_types=1);

namespace Arche\Plan;

/**
 * Easter Sunday (Gregorian), so plans can say "Good Friday = Easter − 2"
 * without ext-calendar, which the webhosting is not guaranteed to have.
 * Anonymous Gregorian algorithm (Meeus/Jones/Butcher).
 */
final class Easter
{
    public static function sunday(int $year): string
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /** The date $offset days from Easter Sunday of $year. */
    public static function offset(int $year, int $offset): string
    {
        return (new \DateTimeImmutable(self::sunday($year) . ' 12:00', new \DateTimeZone('UTC')))
            ->modify(sprintf('%+d days', $offset))
            ->format('Y-m-d');
    }
}

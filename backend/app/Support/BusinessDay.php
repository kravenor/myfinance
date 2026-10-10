<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Giorni lavorativi: weekend e festivi nazionali. Le ricorrenti che cadono in un
 * giorno non lavorativo slittano al primo lavorativo successivo.
 * ponytail: nazione fissa (DEFAULT_COUNTRY). Per più nazioni basta aggiungere una
 * voce in FIXED/EASTER e passare il paese (es. da una colonna `accounts.country`).
 */
final class BusinessDay
{
    public const DEFAULT_COUNTRY = 'IT';

    /** Festivi a data fissa, `m-d`. */
    private const FIXED = [
        'IT' => ['01-01', '01-06', '04-25', '05-01', '06-02', '08-15', '11-01', '12-08', '12-25', '12-26'],
    ];

    /** Festivi mobili come giorni di distanza dalla domenica di Pasqua (1 = Pasquetta). */
    private const EASTER = [
        'IT' => [1],
    ];

    /** @var array<string, array<string, true>> */
    private static array $cache = [];

    public static function next(Carbon $date, string $country = self::DEFAULT_COUNTRY): Carbon
    {
        $day = $date->copy();
        while (! self::isBusinessDay($day, $country)) {
            $day->addDay();
        }

        return $day;
    }

    public static function isBusinessDay(Carbon $date, string $country = self::DEFAULT_COUNTRY): bool
    {
        return ! $date->isWeekend() && ! isset(self::holidays($date->year, $country)[$date->format('m-d')]);
    }

    /** @return array<string, true> chiavi `m-d` */
    private static function holidays(int $year, string $country): array
    {
        $key = "{$country}-{$year}";
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $days = array_fill_keys(self::FIXED[$country] ?? [], true);
        $easter = self::easter($year);
        foreach (self::EASTER[$country] ?? [] as $offset) {
            $days[$easter->copy()->addDays($offset)->format('m-d')] = true;
        }

        return self::$cache[$key] = $days;
    }

    /** Domenica di Pasqua (algoritmo gregoriano anonimo): `easter_date()` richiede ext-calendar, assente nell'immagine. */
    private static function easter(int $year): Carbon
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $h = (19 * $a + $b - intdiv($b, 4) - intdiv(8 * $b + 13, 25) + 15) % 30;
        $l = (32 + 2 * ($b % 4) + 2 * intdiv($c, 4) - $h - $c % 4) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = ($h + $l - 7 * $m + 114) % 31 + 1;

        return Carbon::create($year, $month, $day)->startOfDay();
    }
}

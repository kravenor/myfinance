<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Confini del "mese finanziario": il ciclo può iniziare in un giorno diverso dal
 * 1° (`users.month_start_day`, es. il giorno dello stipendio). Punto di verità
 * unico: nessun servizio deve usare `startOfMonth()/endOfMonth()` per i periodi
 * di reporting.
 *
 * Convenzione: un ciclo è etichettato `Y-m` del mese in cui **inizia**, quindi
 * con `startDay = 27` il periodo `2026-06` va dal 27/06 al 26/07.
 * Con `startDay = 1` l'helper è identico a `startOfMonth()/endOfMonth()`.
 */
final class FinancialMonth
{
    public const DEFAULT_START_DAY = 1;

    /**
     * Cap a 28: un ciclo che parte il 29/30/31 non esisterebbe a febbraio.
     * ponytail: alzare solo se emerge un caso reale oltre il 28.
     */
    public const MAX_START_DAY = 28;

    public static function clamp(?int $day): int
    {
        return max(1, min(self::MAX_START_DAY, $day ?? self::DEFAULT_START_DAY));
    }

    /**
     * Giorno di inizio dell'utente autenticato (anche nei command, che fanno
     * `Auth::loginUsingId`). Fallback al default se non c'è utente.
     */
    public static function startDay(): int
    {
        /** @var User|null $user */
        $user = Auth::user();

        return self::clamp($user?->month_start_day);
    }

    /**
     * Ciclo che contiene `$ref`.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function range(Carbon $ref, ?int $startDay = null): array
    {
        $startDay = $startDay === null ? self::startDay() : self::clamp($startDay);

        $start = $ref->copy()->startOfMonth()->addDays($startDay - 1)->startOfDay();
        if ($ref->day < $startDay) {
            $start->subMonthNoOverflow();
        }

        return [$start, self::endFor($start)];
    }

    /**
     * Ciclo etichettato `(year, month)` — la chiave dei budget.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function forYearMonth(int $year, int $month, ?int $startDay = null): array
    {
        $startDay = $startDay === null ? self::startDay() : self::clamp($startDay);
        $start = Carbon::create($year, $month, $startDay)->startOfDay();

        return [$start, self::endFor($start)];
    }

    /**
     * Ciclo etichettato `Y-m`.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function fromKey(string $key, ?int $startDay = null): array
    {
        [$year, $month] = array_map('intval', explode('-', $key));

        return self::forYearMonth($year, $month, $startDay);
    }

    /** Etichetta `Y-m` del ciclo che contiene `$ref`. */
    public static function key(Carbon $ref, ?int $startDay = null): string
    {
        return self::range($ref, $startDay)[0]->format('Y-m');
    }

    private static function endFor(Carbon $start): Carbon
    {
        return $start->copy()->addMonthNoOverflow()->subDay()->endOfDay();
    }
}

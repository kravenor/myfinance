<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Scadenza successiva di una ricorrenza (ricorrenti e voci degli scenari).
 * Le cadenze a mesi tornano sul giorno di ancoraggio (di solito quello di
 * `starts_on`): senza, un 31 accorciato a febbraio resterebbe 28 per sempre.
 */
final class Cadence
{
    public static function advance(Carbon $from, string $cadence, int $interval, ?int $anchorDay = null): Carbon
    {
        $next = match ($cadence) {
            'daily' => $from->copy()->addDays($interval),
            'weekly' => $from->copy()->addWeeks($interval),
            'biweekly' => $from->copy()->addWeeks(2 * $interval),
            'monthly' => $from->copy()->addMonthsNoOverflow($interval),
            'quarterly' => $from->copy()->addMonthsNoOverflow(3 * $interval),
            'yearly' => $from->copy()->addYearsNoOverflow($interval),
            default => throw new \UnexpectedValueException("Cadenza non supportata: {$cadence}"),
        };

        if ($anchorDay !== null && in_array($cadence, ['monthly', 'quarterly', 'yearly'], true)) {
            $next->day(min($anchorDay, $next->daysInMonth));
        }

        return $next;
    }
}

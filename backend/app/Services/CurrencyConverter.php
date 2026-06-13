<?php

namespace App\Services;

use App\Models\ExchangeRate;
use Illuminate\Support\Carbon;

/**
 * Converte importi tra valute usando i tassi memorizzati in `exchange_rates`
 * con la regola "tasso alla data": per una data D si usa l'ultimo tasso
 * disponibile <= D (fallback al più antico se la data precede lo storico).
 *
 * I tassi sono espressi come unità di valuta per 1 unità pivot, quindi la
 * conversione passa sempre dal pivot: amount/rate(from) * rate(to).
 *
 * La cache è per-istanza: va risolta dal container e riusata nell'arco di
 * una singola richiesta (vedi ReportService).
 */
class CurrencyConverter
{
    /** @var array<string, array<string, float>> currency => [date => rate] ordinati per data asc */
    private array $cache = [];

    public function pivot(): string
    {
        return strtoupper((string) config('finance.pivot_currency', 'EUR'));
    }

    /**
     * Converte $amount da $from a $to alla data $date.
     */
    public function convert(float $amount, string $from, string $to, Carbon $date): float
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if ($from === $to || $amount === 0.0) {
            return $amount;
        }

        $inPivot = $amount / $this->rateFor($from, $date);

        return $inPivot * $this->rateFor($to, $date);
    }

    /**
     * Tasso della valuta rispetto al pivot alla data indicata.
     * Ritorna 1.0 (parità) se non esiste alcun tasso per la valuta.
     */
    public function rateFor(string $currency, Carbon $date): float
    {
        $currency = strtoupper($currency);

        if ($currency === $this->pivot()) {
            return 1.0;
        }

        $rates = $this->ratesFor($currency);

        if ($rates === []) {
            return 1.0;
        }

        $target = $date->toDateString();
        $chosen = null;
        foreach ($rates as $day => $rate) {
            if ($day <= $target) {
                $chosen = $rate;

                continue;
            }
            break;
        }

        return $chosen ?? reset($rates);
    }

    /**
     * Indica se esiste almeno un tasso per la valuta (≠ pivot).
     */
    public function hasRates(string $currency): bool
    {
        $currency = strtoupper($currency);

        return $currency === $this->pivot() || $this->ratesFor($currency) !== [];
    }

    /**
     * @return array<string, float> [date => rate] ordinati per data ascendente
     */
    private function ratesFor(string $currency): array
    {
        if (! array_key_exists($currency, $this->cache)) {
            $this->cache[$currency] = ExchangeRate::query()
                ->where('currency', $currency)
                ->orderBy('date')
                ->pluck('rate', 'date')
                ->map(fn ($rate) => (float) $rate)
                ->all();
        }

        return $this->cache[$currency];
    }
}

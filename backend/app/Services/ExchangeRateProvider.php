<?php

namespace App\Services;

use App\Models\ExchangeRate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Scarica i tassi di cambio di riferimento BCE via Frankfurter e li
 * persiste in `exchange_rates` (unità di valuta per 1 unità pivot).
 */
class ExchangeRateProvider
{
    public function pivot(): string
    {
        return strtoupper((string) config('finance.pivot_currency', 'EUR'));
    }

    /**
     * Scarica i tassi più recenti disponibili. Ritorna il numero di righe upsertate.
     */
    public function fetchLatest(): int
    {
        $data = $this->request('/latest');

        return $this->store($data['date'] ?? null, $data['rates'] ?? []);
    }

    /**
     * Scarica i tassi per una singola data (Frankfurter restituisce l'ultimo
     * giorno lavorativo <= data richiesta).
     */
    public function fetchForDate(Carbon $date): int
    {
        $data = $this->request('/'.$date->toDateString());

        return $this->store($data['date'] ?? null, $data['rates'] ?? []);
    }

    /**
     * Backfill di un intervallo di date (endpoint time-series Frankfurter).
     * Ritorna il numero totale di righe upsertate.
     */
    public function fetchRange(Carbon $from, Carbon $to): int
    {
        $data = $this->request('/'.$from->toDateString().'..'.$to->toDateString());

        $total = 0;
        foreach (($data['rates'] ?? []) as $date => $rates) {
            $total += $this->store($date, $rates);
        }

        return $total;
    }

    /**
     * @param  array<string, float>  $rates
     */
    private function store(?string $date, array $rates): int
    {
        if (! $date || $rates === []) {
            return 0;
        }

        $now = Carbon::now();
        $rows = [[
            'date' => $date,
            'currency' => $this->pivot(),
            'rate' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]];

        foreach ($rates as $currency => $rate) {
            $rows[] = [
                'date' => $date,
                'currency' => strtoupper((string) $currency),
                'rate' => $rate,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        ExchangeRate::query()->upsert($rows, ['date', 'currency'], ['rate', 'updated_at']);

        return count($rows);
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $path): array
    {
        $base = rtrim((string) config('finance.rates.provider_url'), '/');
        $timeout = (int) config('finance.rates.timeout', 15);

        $response = Http::timeout($timeout)
            ->acceptJson()
            ->get($base.$path, ['from' => $this->pivot()]);

        if ($response->failed()) {
            throw new RuntimeException("Richiesta tassi di cambio fallita ({$response->status()}): {$base}{$path}");
        }

        /** @var array<string, mixed> $json */
        $json = $response->json() ?? [];

        return $json;
    }
}

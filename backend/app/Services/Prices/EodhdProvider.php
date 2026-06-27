<?php

namespace App\Services\Prices;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * EODHD (https://eodhd.com) per stock/etf/fund. Usa l'endpoint real-time
 * (latest) un simbolo alla volta, così un simbolo errato non blocca gli
 * altri. La valuta non è esposta dall'endpoint real-time: la deduciamo dal
 * suffisso exchange (es. .XETRA → EUR), con fallback su default_currency.
 */
class EodhdProvider implements PriceProvider
{
    public function fetch(array $symbols): array
    {
        $key = (string) config('finance.prices.eodhd.api_key');
        if ($key === '') {
            return []; // ponytail: nessuna key → gruppo saltato, resta sui prezzi manuali
        }

        $base = rtrim((string) config('finance.prices.eodhd.url'), '/');
        $timeout = (int) config('finance.prices.eodhd.timeout', 15);

        $out = [];
        foreach (array_unique($symbols) as $symbol) {
            // ponytail: 1 richiesta/simbolo; per portafogli personali è trascurabile.
            // Upgrade: endpoint bulk real-time (?s=) se i simboli crescono.
            $response = Http::timeout($timeout)->acceptJson()->get(
                $base.'/real-time/'.rawurlencode($symbol),
                ['api_token' => $key, 'fmt' => 'json'],
            );

            if ($response->failed()) {
                continue;
            }

            $close = $response->json('close');
            if (! is_numeric($close)) {
                continue; // "NA" a mercato chiuso o simbolo inesistente
            }

            $ts = $response->json('timestamp');
            $asOf = is_numeric($ts)
                ? Carbon::createFromTimestampUTC((int) $ts)->toDateString()
                : Carbon::now()->toDateString();

            $out[] = [
                'symbol' => $symbol,
                'price' => (float) $close,
                'currency' => $this->currencyFor($symbol),
                'as_of' => $asOf,
            ];
        }

        return $out;
    }

    private function currencyFor(string $symbol): string
    {
        $map = (array) config('finance.prices.eodhd.currency_by_suffix', []);
        $default = (string) config('finance.prices.eodhd.default_currency', 'EUR');

        $dot = strrpos($symbol, '.');
        $suffix = $dot === false ? '' : strtoupper(substr($symbol, $dot + 1));

        return $map[$suffix] ?? $default;
    }
}

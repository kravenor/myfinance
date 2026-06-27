<?php

namespace App\Services\Prices;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Yahoo Finance (endpoint chart pubblico, no API key) per stock/etf/fund.
 * Copre le borse EU (UCITS ETF su .MI, .DE, .L, .PA, .AS…) oltre agli USA e
 * restituisce direttamente la valuta di quotazione. Il `symbol` dell'holding
 * dev'essere il symbol Yahoo (es. CSSPX.MI per Borsa Italiana, SXR8.DE per XETRA).
 */
class YahooFinanceProvider implements PriceProvider
{
    public function fetch(array $symbols): array
    {
        $base = rtrim((string) config('finance.prices.yahoo.url'), '/');
        $timeout = (int) config('finance.prices.yahoo.timeout', 15);

        $out = [];
        foreach (array_unique($symbols) as $symbol) {
            // ponytail: 1 richiesta/simbolo via endpoint chart (no cookie/crumb).
            // Il bulk /v7/finance/quote richiede crumb+cookie → più fragile;
            // passare a quello solo se i simboli crescono di molto.
            $response = Http::timeout($timeout)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
                ->acceptJson()
                ->get($base.'/v8/finance/chart/'.rawurlencode($symbol), ['interval' => '1d', 'range' => '1d']);

            if ($response->failed()) {
                continue;
            }

            $meta = $response->json('chart.result.0.meta');
            $price = data_get($meta, 'regularMarketPrice');
            $currency = data_get($meta, 'currency');
            if (! is_numeric($price) || ! is_string($currency) || $currency === '') {
                continue;
            }

            // ponytail: GBp (pence di Londra) NON è gestita — sarebbe 1/100 GBP.
            // Aggiungere la conversione se si tracciano azioni UK quotate in pence.
            $ts = data_get($meta, 'regularMarketTime');
            $asOf = is_numeric($ts)
                ? Carbon::createFromTimestampUTC((int) $ts)->toDateString()
                : Carbon::now()->toDateString();

            $out[] = [
                'symbol' => $symbol,
                'price' => (float) $price,
                'currency' => strtoupper($currency),
                'as_of' => $asOf,
            ];
        }

        return $out;
    }
}

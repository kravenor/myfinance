<?php

namespace App\Services\Prices;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * CoinGecko (https://www.coingecko.com) per le crypto. Il `symbol` dell'holding
 * deve essere l'id CoinGecko (es. "bitcoin", "ethereum"). Endpoint simple/price:
 * batch di tutti gli id in una sola richiesta, valuta = finance.prices.coingecko.vs_currency.
 */
class CoinGeckoProvider implements PriceProvider
{
    public function fetch(array $symbols): array
    {
        $ids = array_values(array_unique($symbols));
        if ($ids === []) {
            return [];
        }

        $base = rtrim((string) config('finance.prices.coingecko.url'), '/');
        $timeout = (int) config('finance.prices.coingecko.timeout', 15);
        $vs = strtolower((string) config('finance.prices.coingecko.vs_currency', 'eur'));
        $key = (string) config('finance.prices.coingecko.api_key');

        $query = ['ids' => implode(',', $ids), 'vs_currencies' => $vs];
        if ($key !== '') {
            $query['x_cg_demo_api_key'] = $key;
        }

        $response = Http::timeout($timeout)->acceptJson()->get($base.'/simple/price', $query);
        if ($response->failed()) {
            return [];
        }

        $data = (array) $response->json();
        $asOf = Carbon::now()->toDateString();
        $currency = strtoupper($vs);

        $out = [];
        foreach ($ids as $id) {
            $price = $data[$id][$vs] ?? null;
            if (! is_numeric($price)) {
                continue;
            }
            $out[] = [
                'symbol' => $id,
                'price' => (float) $price,
                'currency' => $currency,
                'as_of' => $asOf,
            ];
        }

        return $out;
    }
}

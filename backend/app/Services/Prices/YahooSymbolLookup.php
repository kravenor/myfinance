<?php

namespace App\Services\Prices;

use Illuminate\Support\Facades\Http;

/**
 * Risolve un testo libero (ISIN, ticker o nome) nei symbol Yahoo quotabili,
 * arricchiti con valuta e prezzo correnti. Un ISIN può mappare a più
 * quotazioni (borse/valute diverse): vengono restituiti tutti i candidati,
 * con quelli nella valuta preferita in cima.
 */
class YahooSymbolLookup
{
    /** Tipi Yahoo considerati strumenti quotabili. */
    private const QUOTABLE_TYPES = ['equity', 'etf', 'mutualfund'];

    /** Massimo candidati da arricchire con chart (1 richiesta ciascuno). */
    private const MAX_CANDIDATES = 6;

    public function __construct(private readonly YahooFinanceProvider $provider) {}

    /**
     * @return list<array{symbol: string, name: ?string, exchange: ?string, type: ?string, currency: ?string, price: ?float}>
     */
    public function search(string $query, ?string $preferCurrency = null): array
    {
        $base = rtrim((string) config('finance.prices.yahoo.url'), '/');
        $timeout = (int) config('finance.prices.yahoo.timeout', 15);

        $res = Http::timeout($timeout)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
            ->acceptJson()
            ->get($base.'/v1/finance/search', ['q' => $query, 'quotesCount' => 10, 'newsCount' => 0]);

        if ($res->failed()) {
            return [];
        }

        $candidates = [];
        foreach ((array) $res->json('quotes', []) as $q) {
            $symbol = is_array($q) ? ($q['symbol'] ?? null) : null;
            $type = strtolower((string) (is_array($q) ? ($q['quoteType'] ?? '') : ''));
            if (! is_string($symbol) || $symbol === '' || ! in_array($type, self::QUOTABLE_TYPES, true)) {
                continue;
            }
            $candidates[] = [
                'symbol' => $symbol,
                'name' => $q['longname'] ?? $q['shortname'] ?? null,
                'exchange' => $q['exchDisp'] ?? $q['exchange'] ?? null,
                'type' => $type,
            ];
            if (count($candidates) >= self::MAX_CANDIDATES) {
                break;
            }
        }

        if ($candidates === []) {
            return [];
        }

        // La search non espone la valuta: la ricaviamo (con il prezzo) dal chart.
        $quotes = collect($this->provider->fetch(array_column($candidates, 'symbol')))->keyBy('symbol');

        $out = array_map(fn (array $c) => [
            ...$c,
            'currency' => $quotes[$c['symbol']]['currency'] ?? null,
            'price' => $quotes[$c['symbol']]['price'] ?? null,
        ], $candidates);

        // Ordine: prima i quotabili, poi quelli nella valuta preferita.
        $prefer = $preferCurrency !== null ? strtoupper($preferCurrency) : null;
        usort($out, function (array $a, array $b) use ($prefer) {
            $score = fn (array $x) => ($x['price'] !== null ? 2 : 0) + ($prefer !== null && $x['currency'] === $prefer ? 1 : 0);

            return $score($b) <=> $score($a);
        });

        return $out;
    }
}

<?php

namespace App\Services;

use App\Models\InstrumentPrice;
use App\Models\InvestmentHolding;
use App\Services\Prices\CoinGeckoProvider;
use App\Services\Prices\EodhdProvider;
use App\Services\Prices\PriceProvider;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * Raccoglie i simboli distinti da tutti gli holding (globalmente, oltre lo
 * scope utente), li raggruppa per asset_type, instrada al provider giusto e
 * fa l'upsert in `instrument_prices`. Un errore su un provider/gruppo non
 * blocca gli altri.
 */
class InvestmentPriceFetcher
{
    /**
     * @param  list<string>  $only  limita a questi simboli (vuoto = tutti)
     * @return int righe upsertate
     */
    public function fetchLatest(array $only = []): int
    {
        $byProvider = $this->symbolsByProvider($only);

        $total = 0;
        foreach ($byProvider as $providerKey => $symbols) {
            try {
                $quotes = $this->provider($providerKey)->fetch($symbols);
                $total += $this->store($quotes);
            } catch (Throwable $e) {
                report($e); // ponytail: un provider giù non deve far fallire gli altri
            }
        }

        return $total;
    }

    /**
     * Simboli distinti per provider, ricavati dagli holding (senza scope utente)
     * mappando asset_type → provider via config.
     *
     * @param  list<string>  $only
     * @return array<string, list<string>>
     */
    private function symbolsByProvider(array $only): array
    {
        $map = (array) config('finance.prices.providers', []);

        $query = InvestmentHolding::withoutGlobalScopes()
            ->whereNotNull('symbol')
            ->where('symbol', '!=', '');

        if ($only !== []) {
            $query->whereIn('symbol', $only);
        }

        $rows = $query->get(['symbol', 'asset_type'])->unique('symbol');

        $out = [];
        foreach ($rows as $row) {
            $providerKey = $map[$row->asset_type] ?? null;
            if ($providerKey === null) {
                continue; // asset_type senza provider configurato
            }
            $out[$providerKey][] = $row->symbol;
        }

        return $out;
    }

    private function provider(string $key): PriceProvider
    {
        return match ($key) {
            'eodhd' => app(EodhdProvider::class),
            'coingecko' => app(CoinGeckoProvider::class),
            default => throw new RuntimeException("Provider quotazioni sconosciuto: {$key}"),
        };
    }

    /**
     * @param  list<array{symbol: string, price: float, currency: string, as_of: string}>  $quotes
     */
    private function store(array $quotes): int
    {
        if ($quotes === []) {
            return 0;
        }

        $now = Carbon::now();
        $rows = array_map(fn (array $q) => [
            'symbol' => $q['symbol'],
            'currency' => strtoupper($q['currency']),
            'price' => $q['price'],
            'as_of' => $q['as_of'],
            'created_at' => $now,
            'updated_at' => $now,
        ], $quotes);

        InstrumentPrice::query()->upsert($rows, ['symbol', 'as_of'], ['price', 'currency', 'updated_at']);

        return count($rows);
    }
}

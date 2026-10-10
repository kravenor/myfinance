<?php

namespace App\Services;

use App\Models\InstrumentPrice;
use App\Models\InvestmentHolding;
use App\Models\InvestmentTransaction;
use App\Services\Prices\BorsaItalianaProvider;
use App\Services\Prices\CoinGeckoProvider;
use App\Services\Prices\HistoricalPriceProvider;
use App\Services\Prices\PriceProvider;
use App\Services\Prices\TeleborsaProvider;
use App\Services\Prices\YahooFinanceProvider;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * Raccoglie le coppie (simbolo, provider) distinte da tutti gli holding (globalmente,
 * oltre lo scope utente), instrada ogni simbolo a ciascuno dei suoi provider e fa
 * l'upsert in `instrument_prices` per (provider, symbol, as_of). Un errore su un provider/gruppo non
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
                $total += $this->store($providerKey, $quotes);
            } catch (Throwable $e) {
                report($e); // ponytail: un provider giù non deve far fallire gli altri
            }
        }

        return $total;
    }

    /**
     * Storico mensile dal mese del primo movimento a oggi, per i simboli il cui provider ha lo storico.
     * Senza $force salta i simboli già coperti: così può girare ogni notte e scarica solo per
     * holding nuovi o movimenti inseriti a posteriori.
     *
     * @param  list<string>  $only  limita a questi simboli (vuoto = tutti)
     * @return int righe upsertate
     */
    public function backfill(array $only = [], bool $force = false): int
    {
        $firstMovement = InvestmentTransaction::withoutGlobalScopes()
            ->join('investment_holdings', 'investment_holdings.id', '=', 'investment_transactions.investment_holding_id')
            ->whereNotNull('investment_holdings.symbol')
            ->groupBy('investment_holdings.symbol')
            ->selectRaw('investment_holdings.symbol as symbol, MIN(investment_transactions.occurred_at) as first_at')
            ->pluck('first_at', 'symbol');

        $firstQuote = InstrumentPrice::query()->toBase()
            ->groupBy('provider', 'symbol')
            ->selectRaw('provider, symbol, MIN(as_of) as first_at')
            ->get()
            ->mapWithKeys(fn (object $row) => [InstrumentPrice::key($row->provider, $row->symbol) => $row->first_at]);

        $today = Carbon::today();
        $total = 0;

        foreach ($this->symbolsByProvider($only) as $providerKey => $symbols) {
            $provider = $this->provider($providerKey);
            if (! $provider instanceof HistoricalPriceProvider) {
                continue; // Borsa Italiana, Teleborsa, CoinGecko: nessuno storico
            }

            foreach ($symbols as $symbol) {
                if (! isset($firstMovement[$symbol])) {
                    continue;
                }
                $from = Carbon::parse($firstMovement[$symbol])->startOfMonth();
                $firstAt = $firstQuote[InstrumentPrice::key($providerKey, $symbol)] ?? null;
                $covered = $firstAt !== null && Carbon::parse($firstAt)->lte($from->copy()->endOfMonth());
                if ($covered && ! $force) {
                    continue;
                }

                try {
                    $total += $this->store($providerKey, $provider->history($symbol, $from, $today));
                } catch (Throwable $e) {
                    report($e); // un simbolo non risolvibile non deve fermare gli altri
                }
            }
        }

        return $total;
    }

    /**
     * Simboli distinti per provider, ricavati dagli holding (senza scope utente)
     * mappando asset_type → provider via config. Uno stesso simbolo con tipi diversi
     * finisce sotto ciascuno dei suoi provider.
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

        $rows = $query->distinct()->get(['symbol', 'asset_type']);

        $out = [];
        foreach ($rows as $row) {
            $providerKey = $map[$row->asset_type] ?? null;
            if ($providerKey === null) {
                continue; // asset_type senza provider configurato
            }
            $out[$providerKey][$row->symbol] = $row->symbol; // etf e stock condividono yahoo
        }

        return array_map(array_values(...), $out);
    }

    private function provider(string $key): PriceProvider
    {
        return match ($key) {
            'yahoo' => app(YahooFinanceProvider::class),
            'coingecko' => app(CoinGeckoProvider::class),
            'borsaitaliana' => app(BorsaItalianaProvider::class),
            'teleborsa' => app(TeleborsaProvider::class),
            default => throw new RuntimeException("Provider quotazioni sconosciuto: {$key}"),
        };
    }

    /**
     * @param  list<array{symbol: string, price: float, currency: string, as_of: string}>  $quotes
     */
    private function store(string $provider, array $quotes): int
    {
        if ($quotes === []) {
            return 0;
        }

        $now = Carbon::now();
        $rows = array_map(fn (array $q) => [
            'provider' => $provider,
            'symbol' => $q['symbol'],
            'currency' => strtoupper($q['currency']),
            'price' => $q['price'],
            'as_of' => $q['as_of'],
            'created_at' => $now,
            'updated_at' => $now,
        ], $quotes);

        InstrumentPrice::query()->upsert($rows, ['provider', 'symbol', 'as_of'], ['price', 'currency', 'updated_at']);

        return count($rows);
    }
}

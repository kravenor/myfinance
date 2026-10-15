<?php

namespace App\Services;

use App\Models\InstrumentPrice;
use App\Models\InvestmentHolding;
use App\Models\InvestmentTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Risolve il prezzo automatico (da `instrument_prices`) per le holding e lo
 * imposta sul model convertito nella valuta dell'holding. Le holding senza
 * `symbol` o senza quotazione mantengono il prezzo manuale (`last_price`) o,
 * in assenza, il costo medio — fallback gestito da InvestmentHolding::effectivePrice().
 *
 * Tenant-agnostico per design: le quotazioni sono un fatto globale per symbol,
 * indipendente dall'utente che detiene la holding.
 */
class InvestmentPriceResolver
{
    public function __construct(private readonly CurrencyConverter $converter) {}

    /**
     * Imposta su ogni holding la quotazione automatica più recente con
     * `as_of <= $asOf` (default: oggi), convertita nella valuta dell'holding.
     *
     * @param  Collection<int, InvestmentHolding>  $holdings
     */
    public function hydrate(Collection $holdings, ?Carbon $asOf = null): void
    {
        $asOf ??= Carbon::now();

        $symbols = $holdings->pluck('symbol')->filter()->unique()->values();
        if ($symbols->isEmpty()) {
            return;
        }

        // ponytail: carica tutte le quote dei symbol e tiene la più recente <= asOf.
        // Ok per poche decine di symbol; con storico ampio passare a una subquery MAX(as_of).
        $quotesBySymbol = InstrumentPrice::query()
            ->whereIn('symbol', $symbols)
            ->where('as_of', '<=', $asOf->toDateString())
            ->orderBy('as_of')
            ->get()
            ->groupBy(fn (InstrumentPrice $p) => InstrumentPrice::key($p->provider, $p->symbol));

        $firstMovementAt = $this->firstMovementDates($holdings->filter->isIndexed());

        foreach ($holdings as $holding) {
            $quotes = $holding->symbol ? ($quotesBySymbol[$holding->priceKey()] ?? null) : null;
            $quote = $quotes?->last();

            if ($quote === null) {
                continue;
            }

            if ($holding->isIndexed()) {
                $price = $holding->indexedPrice($quotes, $asOf, (float) $holding->avg_cost, $firstMovementAt[$holding->id] ?? null);
                if ($price !== null) {
                    $holding->usingResolvedPrice($price, $quote->as_of->toDateString());
                }

                continue;
            }

            $holding->usingResolvedPrice(
                $this->converter->convert(
                    (float) $quote->price,
                    $quote->currency,
                    $holding->currency,
                    $quote->as_of,
                ),
                $quote->as_of->toDateString(),
            );
        }
    }

    /**
     * Data del primo movimento per holding, ancora del costo negli holding indicizzati.
     * Senza scope utente: gli id arrivano dalla collection già filtrata dal chiamante.
     *
     * @param  Collection<int, InvestmentHolding>  $holdings
     * @return Collection<int, Carbon>
     */
    private function firstMovementDates(Collection $holdings): Collection
    {
        if ($holdings->isEmpty()) {
            return collect();
        }

        return InvestmentTransaction::withoutGlobalScopes()
            ->whereIn('investment_holding_id', $holdings->pluck('id'))
            ->groupBy('investment_holding_id')
            ->selectRaw('investment_holding_id, MIN(occurred_at) as first_at')
            ->pluck('first_at', 'investment_holding_id')
            ->map(fn (string $at) => Carbon::parse($at));
    }

    /**
     * Prezzo automatico per una singola holding (valuta dell'holding), o null
     * se non c'è quotazione per il suo symbol.
     */
    public function priceFor(InvestmentHolding $holding, ?Carbon $asOf = null): ?float
    {
        $this->hydrate(collect([$holding]), $asOf);

        return $holding->resolvedPrice();
    }
}

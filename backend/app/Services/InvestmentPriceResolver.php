<?php

namespace App\Services;

use App\Models\InstrumentPrice;
use App\Models\InvestmentHolding;
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
            ->groupBy('symbol');

        foreach ($holdings as $holding) {
            $quote = $holding->symbol
                ? ($quotesBySymbol[$holding->symbol] ?? null)?->last()
                : null;

            if ($quote === null) {
                continue;
            }

            $holding->usingResolvedPrice($this->converter->convert(
                (float) $quote->price,
                $quote->currency,
                $holding->currency,
                $quote->as_of,
            ));
        }
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

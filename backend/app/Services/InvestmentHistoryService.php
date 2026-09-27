<?php

namespace App\Services;

use App\Models\InstrumentPrice;
use App\Models\InvestmentHolding;
use App\Models\InvestmentTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Serie storica mensile del portafoglio: capitale versato contro valore di
 * mercato, entrambi nella valuta base dell'utente.
 *
 * La serie parte dal primo movimento del registro e va avanti: non si
 * ricostruisce nulla prima di quella data. Il valore di un holding usa la
 * quotazione più recente <= al punto della serie; dove non ne esiste ancora
 * una si usa il costo medio di quel momento, che all'inizio di un PAC è il
 * prezzo a cui hai appena comprato (e non un valore inventato). `last_price`
 * non entra: è un prezzo di oggi, privo di significato su un mese passato.
 * Motivazioni e alternative in docs/adr/0002-registro-movimenti-investimenti.md (D7, D8).
 */
class InvestmentHistoryService
{
    public function __construct(private readonly CurrencyConverter $converter) {}

    /**
     * @return array<string, mixed>
     */
    public function monthly(): array
    {
        $base = strtoupper(Auth::user()->currency);
        $holdings = InvestmentHolding::query()->get()->keyBy('id');

        $movements = InvestmentTransaction::query()
            ->whereIn('investment_holding_id', $holdings->keys())
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->groupBy('investment_holding_id');

        $first = $movements->flatten()->first()?->occurred_at;

        if ($first === null) {
            return ['base_currency' => $base, 'points' => [], 'xirr_pct' => null];
        }

        $quotes = $this->quotesBySymbol($holdings);

        // Stato che avanza con la serie: un cursore per holding sui suoi
        // movimenti già ordinati, così ogni movimento viene letto una volta sola.
        $cursor = [];
        $quantity = [];
        $costBasis = [];
        $invested = 0.0;
        $flows = [];
        $marketValue = 0.0;

        $points = [];
        $today = Carbon::today();

        foreach ($this->monthEnds($first, $today) as $at) {
            foreach ($holdings as $id => $holding) {
                $cursor[$id] ??= 0;
                $quantity[$id] ??= 0.0;
                $costBasis[$id] ??= 0.0;

                $list = $movements[$id] ?? collect();

                while (($movement = $list->get($cursor[$id])) && $movement->occurred_at->lte($at)) {
                    $cursor[$id]++;
                    $moved = (float) $movement->quantity;
                    $cash = $movement->cashFlow();

                    // Il versato si converte al cambio del giorno del movimento,
                    // non a quello del punto della serie: è cassa già uscita.
                    $inBase = $this->converter->convert($cash, $holding->currency, $base, $movement->occurred_at);
                    $flows[] = [$movement->occurred_at, $movement->side === 'sell' ? $inBase : -$inBase];

                    // Costo puro: cassa immessa che non compra quote.
                    if ($movement->side === 'fee') {
                        $invested += $inBase;

                        continue;
                    }

                    if ($movement->side === 'buy') {
                        $quantity[$id] += $moved;
                        $costBasis[$id] += $cash;
                        $invested += $inBase;

                        continue;
                    }

                    $avg = $quantity[$id] > 0 ? $costBasis[$id] / $quantity[$id] : 0.0;
                    $quantity[$id] -= $moved;
                    $costBasis[$id] -= $moved * $avg;
                    $invested -= $inBase;
                }
            }

            $marketValue = 0.0;

            foreach ($holdings as $id => $holding) {
                if (($quantity[$id] ?? 0.0) <= 0) {
                    continue;
                }

                $price = $this->priceAt($quotes, $holding, $at, $base)
                    ?? ($costBasis[$id] / $quantity[$id]);

                $marketValue += $this->converter->convert(
                    $quantity[$id] * $price,
                    $holding->currency,
                    $base,
                    $at
                );
            }

            $points[] = [
                'month' => $at->format('Y-m'),
                'as_of' => $at->toDateString(),
                'invested' => $this->fmt($invested),
                'market_value' => $this->fmt($marketValue),
                'unrealized_pl' => $this->fmt($marketValue - $invested),
            ];
        }

        $flows[] = [$today, $marketValue];

        return ['base_currency' => $base, 'points' => $points, 'xirr_pct' => $this->xirrPct($flows, $first, $today)];
    }

    /**
     * Rendimento annualizzato money-weighted sui flussi del registro più il
     * valore di oggi come flusso finale. Sotto l'anno è null: annualizzare
     * poche settimane darebbe percentuali prive di senso.
     *
     * @param  list<array{0: Carbon, 1: float}>  $flows
     */
    private function xirrPct(array $flows, Carbon $first, Carbon $today): ?string
    {
        if ($first->diffInDays($today) < 365) {
            return null;
        }

        $npv = function (float $rate) use ($flows, $first): float {
            $sum = 0.0;
            foreach ($flows as [$at, $amount]) {
                $sum += $amount / (1 + $rate) ** ($first->diffInDays($at) / 365);
            }

            return $sum;
        };

        // ponytail: bisezione su [-99%, +1000%], lenta ma sempre convergente; Newton se i flussi diventano migliaia.
        [$low, $high] = [-0.99, 10.0];
        $fLow = $npv($low);

        if ($fLow * $npv($high) > 0) {
            return null;
        }

        for ($i = 0; $i < 100 && $high - $low > 1e-7; $i++) {
            $mid = ($low + $high) / 2;
            $fMid = $npv($mid);

            if ($fLow * $fMid <= 0) {
                $high = $mid;
            } else {
                [$low, $fLow] = [$mid, $fMid];
            }
        }

        return $this->fmt(($low + $high) / 2 * 100);
    }

    /**
     * Quotazioni per symbol ordinate per data, caricate in un colpo solo.
     *
     * @param  Collection<int, InvestmentHolding>  $holdings
     * @return Collection<array-key, mixed>
     */
    private function quotesBySymbol($holdings): Collection
    {
        $symbols = $holdings->pluck('symbol')->filter()->unique()->values();

        if ($symbols->isEmpty()) {
            return collect();
        }

        return collect(
            InstrumentPrice::query()
                ->whereIn('symbol', $symbols)
                ->orderBy('as_of')
                ->get()
                ->groupBy('symbol')
        );
    }

    /** Ultima quotazione <= $at nella valuta dell'holding, o null se non ce n'è ancora. */
    private function priceAt($quotes, InvestmentHolding $holding, Carbon $at, string $base): ?float
    {
        if (! $holding->symbol) {
            return null;
        }

        $quote = ($quotes[$holding->symbol] ?? collect())
            ->last(fn (InstrumentPrice $p) => $p->as_of->lte($at));

        if ($quote === null) {
            return null;
        }

        return $this->converter->convert((float) $quote->price, $quote->currency, $holding->currency, $at);
    }

    /**
     * Fine di ogni mese dal primo movimento a oggi; l'ultimo punto è oggi, così
     * combacia con l'overview.
     *
     * @return list<Carbon>
     */
    private function monthEnds(Carbon $from, Carbon $today): array
    {
        $points = [];
        $at = $from->copy()->endOfMonth();

        while ($at->lt($today)) {
            $points[] = $at->copy();
            $at->addMonthNoOverflow()->endOfMonth();
        }

        $points[] = $today->copy();

        return $points;
    }

    private function fmt(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}

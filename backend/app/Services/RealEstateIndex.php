<?php

namespace App\Services;

use App\Models\InstrumentPrice;
use App\Models\InvestmentHolding;
use App\Models\OmiQuotation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Serie dell'indice con cui si rivaluta ogni immobile (InvestmentHolding::indexedPrice()).
 *
 * Con una zona OMI scelta la base è il valore centrale OMI di zona, tipologia e stato,
 * un punto a semestre; prima del primo semestre caricato e dopo l'ultimo la serie
 * prosegue con l'indice ISTAT del territorio (già esteso con la BIS prima del 2010),
 * riscalato sul semestre OMI di raccordo. Senza zona OMI, o senza quotazioni per
 * quella zona, resta l'indice ISTAT.
 *
 * Le righe OMI diventano InstrumentPrice non salvati con provider 'omi', così chi legge
 * la serie (indexedPrice, badge del prezzo) non distingue le fonti se non dal provider.
 */
class RealEstateIndex
{
    /**
     * Serie per id di holding, ordinate per data, degli holding indicizzati.
     *
     * @param  Collection<int, InvestmentHolding>  $holdings
     * @param  Collection<array-key, mixed>  $quotesByKey  quote ISTAT per InstrumentPrice::key(), ordinate per as_of
     * @param  Collection<int, Collection<int, OmiQuotation>>|null  $omi  da omiQuotations(), se il chiamante le ha già
     * @return Collection<int, Collection<int, InstrumentPrice>>
     */
    public function series(Collection $holdings, Collection $quotesByKey, ?Collection $omi = null): Collection
    {
        $indexed = $holdings->filter->isIndexed();
        $omi ??= $this->omiQuotations($indexed);

        return $indexed->mapWithKeys(fn (InvestmentHolding $h) => [
            $h->id => $this->combine(
                ($omi[$h->id] ?? collect())->map(fn (OmiQuotation $q) => $this->point('omi', $q->mid(), $q->semester))->values(),
                $h->symbol ? collect($quotesByKey[$h->priceKey()] ?? []) : collect(),
            ),
        ]);
    }

    /**
     * OMI dove c'è, ISTAT riscalato sul primo semestre OMI prima e sull'ultimo dopo.
     * Tra due semestri OMI non si interpola: vale l'ultimo semestre, come per le quote.
     *
     * @param  Collection<int, InstrumentPrice>  $omi
     * @param  Collection<int, InstrumentPrice>  $istat
     * @return Collection<int, InstrumentPrice>
     */
    public function combine(Collection $omi, Collection $istat): Collection
    {
        if ($omi->isEmpty()) {
            return $istat->values();
        }

        $first = $omi->first();
        $last = $omi->last();

        return collect([
            ...$this->rescaled($istat->filter(fn (InstrumentPrice $p) => $p->as_of->lt($first->as_of)), $istat, $first),
            ...$omi,
            ...$this->rescaled($istat->filter(fn (InstrumentPrice $p) => $p->as_of->gt($last->as_of)), $istat, $last),
        ]);
    }

    /**
     * Punti ISTAT portati sulla scala OMI: × OMI(raccordo) / ISTAT(raccordo).
     *
     * @param  Collection<int, InstrumentPrice>  $points
     * @param  Collection<int, InstrumentPrice>  $istat
     * @return list<InstrumentPrice>
     */
    private function rescaled(Collection $points, Collection $istat, InstrumentPrice $joint): array
    {
        $base = $istat->last(fn (InstrumentPrice $p) => $p->as_of->lte($joint->as_of));
        if ($base === null || (float) $base->price <= 0) {
            return [];
        }

        $factor = (float) $joint->price / (float) $base->price;

        return $points->map(fn (InstrumentPrice $p) => $this->point('istat', (float) $p->price * $factor, $p->as_of))->values()->all();
    }

    /**
     * Quotazioni OMI di zona, tipologia e stato per id di holding, ordinate per semestre.
     * Senza scope utente (chi chiama può essere un comando): ogni holding legge solo
     * le quotazioni caricate dal suo utente.
     *
     * @param  Collection<int, InvestmentHolding>  $holdings
     * @return Collection<int, Collection<int, OmiQuotation>>
     */
    public function omiQuotations(Collection $holdings): Collection
    {
        $withZone = $holdings->filter(fn (InvestmentHolding $h) => $h->isIndexed() && $h->omi_zone && $h->omi_type && $h->omi_state);
        if ($withZone->isEmpty()) {
            return collect();
        }

        $rows = OmiQuotation::withoutGlobalScopes()
            ->whereIn('user_id', $withZone->pluck('user_id')->unique())
            ->whereIn('link_zona', $withZone->pluck('omi_zone')->unique())
            ->orderBy('semester')
            ->get()
            ->groupBy(fn (OmiQuotation $q) => $this->omiKey($q->user_id, $q->link_zona, $q->cod_tip, $q->stato));

        return $withZone->mapWithKeys(fn (InvestmentHolding $h) => [
            $h->id => collect($rows->get($this->omiKey($h->user_id, (string) $h->omi_zone, (int) $h->omi_type, (string) $h->omi_state))?->all() ?? [])
                ->values(),
        ]);
    }

    private function omiKey(int $userId, string $zone, int $type, string $state): string
    {
        return $userId.'|'.$zone.'|'.$type.'|'.$state;
    }

    private function point(string $provider, float $price, Carbon $asOf): InstrumentPrice
    {
        return new InstrumentPrice(['provider' => $provider, 'symbol' => '', 'currency' => 'XXX', 'price' => $price, 'as_of' => $asOf]);
    }
}

<?php

namespace App\Services\Prices;

use Illuminate\Support\Carbon;

/**
 * Provider che sa restituire quotazioni passate (oggi solo Yahoo). Separata da
 * PriceProvider: gli scraping di Borsa Italiana e Teleborsa non hanno uno storico.
 */
interface HistoricalPriceProvider
{
    /**
     * Un punto per mese tra $from e $to: l'ultima chiusura del mese, con la sua data.
     *
     * @return list<array{symbol: string, price: float, currency: string, as_of: string}>
     */
    public function history(string $symbol, Carbon $from, Carbon $to): array;
}

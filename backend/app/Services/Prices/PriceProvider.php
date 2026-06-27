<?php

namespace App\Services\Prices;

/**
 * Sorgente di quotazioni per un sottoinsieme di simboli. Ogni provider
 * conosce gli endpoint e la valuta nativa dei propri strumenti.
 */
interface PriceProvider
{
    /**
     * Quotazioni più recenti per i simboli richiesti. I simboli non
     * risolvibili vengono semplicemente omessi dal risultato.
     *
     * @param  list<string>  $symbols
     * @return list<array{symbol: string, price: float, currency: string, as_of: string}>
     */
    public function fetch(array $symbols): array;
}

<?php

namespace App\Services\Prices;

use Illuminate\Support\Facades\Http;

/**
 * Borsa Italiana (MOT) per i titoli quotati in Italia: BTP, BOT, CCT, BTP
 * Italia/Valore e i corporate sul MOT. Il `symbol` dell'holding dev'essere
 * l'ISIN (es. IT0005534984). Non esiste un'API pubblica: si legge la scheda
 * HTML del titolo, che espone prezzo e data di riferimento (la chiusura del
 * giorno precedente, come i cambi BCE). Il segmento `/btp/` dell'URL è
 * indifferente al tipo di titolo, un solo template copre tutto il MOT.
 *
 * Le obbligazioni si quotano in percentuale del nominale (101,51 = 101,51% del
 * valore nominale). Qui il prezzo viene diviso per 100: così `quantity`
 * dell'holding è il nominale in valuta e `quantity * price` dà il valore di
 * mercato senza casi speciali in InvestmentHolding::marketValue().
 */
class BorsaItalianaProvider implements PriceProvider
{
    /** ISIN: 2 lettere paese + 9 alfanumerici + 1 cifra di controllo. */
    private const ISIN = '/^[A-Z]{2}[A-Z0-9]{9}[0-9]$/';

    public function fetch(array $symbols): array
    {
        $base = rtrim((string) config('finance.prices.borsaitaliana.url'), '/');
        $timeout = (int) config('finance.prices.borsaitaliana.timeout', 15);

        $out = [];
        foreach (array_unique($symbols) as $symbol) {
            $isin = strtoupper(trim($symbol));
            if (preg_match(self::ISIN, $isin) !== 1) {
                continue; // non è un ISIN: inutile interrogare il sito
            }

            // ponytail: 1 richiesta/ISIN (~60 KB di HTML), nessun rate limit
            // rilevato. Se i titoli diventassero decine, la lista
            // /mot/btp/lista.html?page=N dà molti prezzi in una richiesta.
            $response = Http::timeout($timeout)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
                ->get($base.'/'.$isin.'.html', ['lang' => 'it']);

            if ($response->failed()) {
                continue;
            }

            $html = $response->body();
            $price = $this->number($this->field($html, 'Prezzo di riferimento'));
            $asOf = $this->date($this->field($html, 'Data di riferimento'));

            // ISIN inesistente o sospeso: la pagina risponde 200 senza i campi prezzo.
            if ($price === null || $asOf === null) {
                continue;
            }

            $out[] = [
                'symbol' => $symbol,
                'price' => round($price / 100, 8),
                'currency' => $this->currency($html),
                'as_of' => $asOf,
            ];
        }

        return $out;
    }

    /**
     * Valore della cella accanto a un'etichetta nelle tabelle della scheda:
     * `<strong>Etichetta</strong> … <span class="t-text -right">valore</span>`.
     */
    private function field(string $html, string $label): ?string
    {
        $pattern = '/<strong>\s*'.preg_quote($label, '/').'\s*<\/strong>.*?-right">\s*([^<]+?)\s*</s';

        return preg_match($pattern, $html, $m) === 1 ? $m[1] : null;
    }

    /** Formato italiano: "1.234,56" → 1234.56. */
    private function number(?string $value): ?float
    {
        if ($value === null || preg_match('/^[\d.]+(,\d+)?$/', $value) !== 1) {
            return null;
        }

        return (float) str_replace(',', '.', str_replace('.', '', $value));
    }

    private function date(?string $value): ?string
    {
        if ($value === null || preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $value, $m) !== 1) {
            return null;
        }

        if (! checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return null;
        }

        return "{$m[3]}-{$m[2]}-{$m[1]}";
    }

    /**
     * "Valuta di Negoziazione/ Liquidazione" vale "EUR/EUR" per i titoli di
     * stato, ma sul MOT ci sono obbligazioni in altre valute.
     */
    private function currency(string $html): string
    {
        $value = $this->field($html, 'Valuta di Negoziazione/ Liquidazione');

        return preg_match('/^([A-Z]{3})/', (string) $value, $m) === 1 ? $m[1] : 'EUR';
    }
}

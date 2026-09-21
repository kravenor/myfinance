<?php

namespace App\Services\Prices;

use Illuminate\Support\Facades\Http;

/**
 * Teleborsa per i certificati quotati sul SeDeX/Cert-X. Il `symbol`
 * dell'holding dev'essere l'ISIN (es. DE000VY746G1). La sezione certificati
 * di Borsa Italiana (/borsa/cw-e-certificates/*) risponde 502, quindi la
 * fonte è la scheda HTML di Teleborsa: l'URL è ricostruibile dal solo ISIN
 * perché lo slug descrittivo viene ignorato e conta solo la coda base64.
 *
 * I certificati si quotano in euro per certificato (non in percentuale del
 * nominale come le obbligazioni): `quantity * price` è già il controvalore.
 */
class TeleborsaProvider implements PriceProvider
{
    /** ISIN: 2 lettere paese + 9 alfanumerici + 1 cifra di controllo. */
    private const ISIN = '/^[A-Z]{2}[A-Z0-9]{9}[0-9]$/';

    public function fetch(array $symbols): array
    {
        $base = rtrim((string) config('finance.prices.teleborsa.url'), '/');
        $timeout = (int) config('finance.prices.teleborsa.timeout', 15);

        $out = [];
        foreach (array_unique($symbols) as $symbol) {
            $isin = strtoupper(trim($symbol));
            if (preg_match(self::ISIN, $isin) !== 1) {
                continue; // non è un ISIN: inutile interrogare il sito
            }

            // ponytail: 1 richiesta/ISIN (~130 KB di HTML), nessun rate limit
            // rilevato. Con decine di certificati conviene la lista di mercato.
            $slug = 'x-'.strtolower($isin).'-'.base64_encode($isin);
            $response = Http::timeout($timeout)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
                ->get($base.'/'.$slug);

            if ($response->failed()) {
                continue;
            }

            // "Prezzo di riferimento" vale "0,465 - 18/09/2026": prezzo e data
            // di riferimento nella stessa cella.
            [$price, $asOf] = $this->reference($response->body());

            if ($price === null || $asOf === null) {
                continue; // ISIN inesistente, scaduto o senza scambi
            }

            $out[] = [
                'symbol' => $symbol,
                'price' => round($price, 8),
                // Il SeDeX/Cert-X negozia in euro: la scheda non espone la valuta.
                'currency' => 'EUR',
                'as_of' => $asOf,
            ];
        }

        return $out;
    }

    /**
     * Prezzo e data dalla riga "Prezzo di riferimento" della scheda:
     * `<label>Prezzo di riferimento</label> <span id="…">0,465 - 18/09/2026</span>`.
     *
     * @return array{0: float|null, 1: string|null}
     */
    private function reference(string $html): array
    {
        $pattern = '/<label>\s*Prezzo di riferimento\s*<\/label>\s*<span[^>]*>\s*([^<]+?)\s*<\/span>/s';

        if (preg_match($pattern, $html, $m) !== 1) {
            return [null, null];
        }

        $parts = preg_split('/\s+-\s+/', $m[1]);

        return [$this->number($parts[0] ?? null), $this->date($parts[1] ?? null)];
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
}

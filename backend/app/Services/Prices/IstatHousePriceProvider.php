<?php

namespace App\Services\Prices;

use Illuminate\Support\Facades\Http;

/**
 * Indice ISTAT dei prezzi delle abitazioni esistenti (IPAB), trimestrale, per gli
 * immobili. Il `symbol` dell'holding è il territorio della serie (config
 * `finance.prices.istat.areas`). Il `price` salvato è il numero indice, non un
 * prezzo: InvestmentHolding::indexedPrice() ne usa solo i rapporti, per questo la
 * valuta è XXX («nessuna valuta» ISO 4217).
 *
 * Ogni chiamata scarica la serie intera dal 2010 (una richiesta per tutti i
 * territori, poche centinaia di righe): così lo storico è sempre completo e le
 * revisioni dei dati provvisori sovrascrivono i valori vecchi.
 */
class IstatHousePriceProvider implements PriceProvider
{
    private const DATAFLOW = 'IT1,143_497,1.0';

    public function fetch(array $symbols): array
    {
        $allowed = (array) config('finance.prices.istat.areas', []);
        $areas = array_values(array_intersect(array_unique($symbols), $allowed));
        if ($areas === []) {
            return [];
        }

        // Chiave SDMX: FREQ.REF_AREA.DATA_TYPE.MEASURE(4 = numero indice).PURCHASES_DWELLINGS
        $key = 'Q.'.implode('+', $areas).'.'.config('finance.prices.istat.data_type').'.4.EXST_DW';

        $response = Http::timeout((int) config('finance.prices.istat.timeout', 60))
            ->accept('application/vnd.sdmx.data+csv;version=1.0.0')
            ->get(rtrim((string) config('finance.prices.istat.url'), '/').'/data/'.self::DATAFLOW.'/'.$key, [
                'startPeriod' => '2010-Q1',
            ]);

        if ($response->failed()) {
            return [];
        }

        $lines = preg_split('/\r?\n/', trim($response->body()));
        $header = str_getcsv((string) array_shift($lines), escape: '');

        $out = [];
        foreach ($lines as $line) {
            $row = array_combine($header, array_pad(str_getcsv($line, escape: ''), count($header), ''));
            $asOf = $this->quarterStart($row['TIME_PERIOD'] ?? '');
            if ($asOf === null || ! is_numeric($row['OBS_VALUE'] ?? null)) {
                continue;
            }

            $out[] = [
                'symbol' => $row['REF_AREA'],
                'price' => (float) $row['OBS_VALUE'],
                'currency' => 'XXX',
                'as_of' => $asOf,
            ];
        }

        return $out;
    }

    /** "2026-Q2" → "2026-04-01": il trimestre si data al suo primo giorno, vedi InvestmentHolding::indexedPrice(). */
    private function quarterStart(string $period): ?string
    {
        if (preg_match('/^(\d{4})-Q([1-4])$/', $period, $m) !== 1) {
            return null;
        }

        return sprintf('%s-%02d-01', $m[1], ($m[2] - 1) * 3 + 1);
    }
}

<?php

namespace App\Services\Prices;

use Illuminate\Http\Client\ConnectionException;
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
 * revisioni dei dati provvisori sovrascrivono i valori vecchi. Prima del 2010
 * la serie prosegue con l'indice nazionale BIS, vedi backcast().
 */
class IstatHousePriceProvider implements PriceProvider
{
    private const DATAFLOW = 'IT1,143_497,1.0';

    /** Prezzi nominali delle abitazioni in Italia, trimestrali dal 1927 (BIS, Selected property prices). */
    private const BIS_SERIES = 'dataflow/BIS/WS_SPP/1.0/Q.IT.N.628';

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

        $out = [];
        foreach ($this->csvRows($response->body()) as $row) {
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

        return $out === [] ? [] : [...$this->backcast($out), ...$out];
    }

    /**
     * Trimestri prima del primo dato ISTAT di ogni territorio, dalla serie nazionale BIS
     * riscalata su quel primo trimestre: la serie resta continua e gli immobili comprati
     * prima del 2010 si possono indicizzare. Senza BIS la serie parte dal 2010.
     *
     * ponytail: prima del 2010 ogni territorio, città comprese, segue la media nazionale.
     *
     * @param  list<array{symbol: string, price: float, currency: string, as_of: string}>  $istat
     * @return list<array{symbol: string, price: float, currency: string, as_of: string}>
     */
    private function backcast(array $istat): array
    {
        $bis = $this->bisSeries();

        $first = [];
        foreach ($istat as $row) {
            if (! isset($first[$row['symbol']]) || $row['as_of'] < $first[$row['symbol']]['as_of']) {
                $first[$row['symbol']] = $row;
            }
        }

        $out = [];
        foreach ($first as $area => $row) {
            $base = $bis[$row['as_of']] ?? null;
            if (! $base) {
                continue;
            }

            foreach ($bis as $asOf => $value) {
                if ($asOf < $row['as_of']) {
                    $out[] = ['symbol' => $area, 'price' => round($row['price'] * $value / $base, 8), 'currency' => 'XXX', 'as_of' => $asOf];
                }
            }
        }

        return $out;
    }

    /** @return array<string, float> as_of (inizio trimestre) => indice */
    private function bisSeries(): array
    {
        try {
            $response = Http::timeout((int) config('finance.prices.istat.timeout', 60))
                ->get(rtrim((string) config('finance.prices.istat.bis_url'), '/').'/data/'.self::BIS_SERIES, ['format' => 'csv']);
        } catch (ConnectionException $e) {
            report($e); // la serie ISTAT va salvata comunque

            return [];
        }

        if ($response->failed()) {
            return [];
        }

        $series = [];
        foreach ($this->csvRows($response->body()) as $row) {
            $asOf = $this->quarterStart($row['TIME_PERIOD'] ?? '');
            if ($asOf !== null && is_numeric($row['OBS_VALUE'] ?? null) && (float) $row['OBS_VALUE'] > 0) {
                $series[$asOf] = (float) $row['OBS_VALUE'];
            }
        }

        return $series;
    }

    /** @return list<array<string, string>> righe di un CSV con intestazione */
    private function csvRows(string $body): array
    {
        $lines = preg_split('/\r?\n/', trim($body));
        $header = str_getcsv((string) array_shift($lines), escape: '');

        return array_map(
            fn (string $line) => array_combine($header, array_pad(str_getcsv($line, escape: ''), count($header), '')),
            array_filter($lines, fn (string $line) => $line !== ''),
        );
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

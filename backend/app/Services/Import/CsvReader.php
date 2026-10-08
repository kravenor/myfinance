<?php

namespace App\Services\Import;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class CsvReader extends ImportReader
{
    /** Un estratto conto ha una decina di colonne: centinaia di migliaia esauriscono la memoria già in anteprima. */
    private const MAX_COLUMNS = 100;

    public function format(): string
    {
        return 'csv';
    }

    public function read(UploadedFile $file, int $limit): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        if ($handle === false) {
            throw new \RuntimeException('Impossibile leggere il file caricato.');
        }

        $delimiter = $this->detectDelimiter($file);

        $headers = fgetcsv($handle, 0, $delimiter) ?: [];
        if (count($headers) > self::MAX_COLUMNS) {
            fclose($handle);
            throw ValidationException::withMessages(['file' => 'Il file ha più di '.self::MAX_COLUMNS.' colonne: non sembra un estratto conto.']);
        }
        $headers = array_map(fn ($h) => trim((string) $h), $headers);

        $rows = [];
        $count = 0;
        while (($data = fgetcsv($handle, 0, $delimiter)) !== false && $count < $limit) {
            if (count(array_filter($data, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $row = [];
            foreach ($headers as $idx => $header) {
                $row[$header] = isset($data[$idx]) ? trim((string) $data[$idx]) : '';
            }
            $rows[] = $row;
            $count++;
        }
        fclose($handle);

        return [
            'headers' => $headers,
            'rows' => $rows,
            'mapping_locked' => false,
        ];
    }

    public function suggestedMapping(array $headers): array
    {
        $find = function (array $keywords) use ($headers): ?string {
            foreach ($headers as $header) {
                $h = mb_strtolower($header);
                foreach ($keywords as $kw) {
                    if (str_contains($h, $kw)) {
                        return $header;
                    }
                }
            }

            return null;
        };

        return [
            'date' => $find(['data', 'date', 'occurred']),
            'amount' => $find(['importo', 'amount', 'value']),
            'description' => $find(['descrizione', 'description', 'causale', 'memo']),
            'type' => $find(['tipo', 'type']),
            'category' => $find(['categoria', 'category']),
            // Parole chiave strette: un generico "id" prenderebbe colonne sbagliate.
            'external_id' => $find(['external_id', 'id univoco', 'id operazione']),
        ];
    }

    private function detectDelimiter(UploadedFile $file): string
    {
        $handle = fopen($file->getRealPath(), 'r');
        if ($handle === false) {
            return ',';
        }
        $sample = fgets($handle) ?: '';
        fclose($handle);

        $candidates = [',' => substr_count($sample, ','), ';' => substr_count($sample, ';'), "\t" => substr_count($sample, "\t")];

        return array_search(max($candidates), $candidates, true) ?: ',';
    }
}

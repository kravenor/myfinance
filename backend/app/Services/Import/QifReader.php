<?php

namespace App\Services\Import;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

class QifReader extends ImportReader
{
    public function format(): string
    {
        return 'qif';
    }

    public function read(UploadedFile $file, int $limit): array
    {
        $content = (string) file_get_contents($file->getRealPath());
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = (string) mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
        }

        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];

        $rows = [];
        $entry = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, '!')) {
                continue; // header tipo "!Type:Bank"
            }

            $code = $line[0];
            $value = trim(substr($line, 1));

            if ($code === '^') {
                if ($entry !== []) {
                    $rows[] = $this->normalize($entry);
                    if (count($rows) >= $limit) {
                        return $this->result($rows);
                    }
                }
                $entry = [];

                continue;
            }

            $entry[$code] = $value;
        }

        if ($entry !== [] && count($rows) < $limit) {
            $rows[] = $this->normalize($entry);
        }

        return $this->result($rows);
    }

    public function suggestedMapping(array $headers): array
    {
        return [
            'date' => 'date',
            'amount' => 'amount',
            'description' => 'description',
            'notes' => 'notes',
            'type' => 'type',
        ];
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     * @return array{headers: array<int, string>, rows: array<int, array<string, string>>, mapping_locked: bool}
     */
    private function result(array $rows): array
    {
        return [
            'headers' => ['date', 'amount', 'description', 'notes', 'type'],
            'rows' => $rows,
            'mapping_locked' => true,
        ];
    }

    /**
     * @param  array<string, string>  $entry
     * @return array<string, string>
     */
    private function normalize(array $entry): array
    {
        $amount = $entry['T'] ?? ($entry['U'] ?? '');
        $payee = $entry['P'] ?? '';
        $memo = $entry['M'] ?? '';

        return [
            'date' => $this->parseDate($entry['D'] ?? ''),
            'amount' => $amount,
            'description' => $payee !== '' ? $payee : $memo,
            'notes' => $payee !== '' ? $memo : '',
            'type' => str_starts_with(ltrim($amount), '-') ? 'expense' : 'income',
        ];
    }

    /**
     * Date QIF in formati eterogenei: prova europeo (d/m) prima dell'americano (m/d).
     * Ritorna ISO o stringa vuota (riga scartata a valle).
     */
    private function parseDate(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        // Quicken usa l'apostrofo per separare l'anno (es. 5/10'2026).
        $raw = str_replace("'", '/', $raw);
        $raw = (string) preg_replace('/\s+/', '', $raw);

        foreach (['Y-m-d', 'd/m/Y', 'd/m/y', 'm/d/Y', 'm/d/y', 'Y/m/d'] as $format) {
            try {
                return Carbon::createFromFormat('!'.$format, $raw)->toDateString();
            } catch (\Throwable) {
                // formato non compatibile: prova il successivo
            }
        }

        return '';
    }
}

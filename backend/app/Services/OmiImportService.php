<?php

namespace App\Services;

use App\Models\OmiQuotation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/**
 * Importa le quotazioni OMI di un semestre dal file delle «Forniture dati OMI»
 * dell'Agenzia delle Entrate: lo zip così come si scarica (QI_..._VALORI.csv più
 * QI_..._ZONE.csv, da cui prende la descrizione delle zone) o il solo CSV dei valori.
 * Ricaricare lo stesso semestre sovrascrive i valori.
 *
 * Formato: prima riga didascalia con «Semestre AAAA/N», poi intestazione; separatore
 * `;` anche a fine riga, decimali con la virgola, codifica Windows-1252 (a volte mista).
 */
class OmiImportService
{
    /** Tetto per file estratto dallo zip: il CSV nazionale dei valori pesa ~20 MB. */
    private const MAX_ENTRY_BYTES = 40 * 1024 * 1024;

    private const CHUNK = 1000;

    /**
     * @return array{semester: string, rows: int, comuni: int}
     */
    public function import(UploadedFile $file): array
    {
        [$values, $zones] = $this->contents($file);

        $descriptions = $zones !== null ? $this->zoneDescriptions($zones) : [];
        $semester = null;
        $rows = 0;
        $comuni = [];
        $chunk = [];
        $userId = Auth::id();

        DB::transaction(function () use ($values, $descriptions, $userId, &$semester, &$rows, &$comuni, &$chunk) {
            foreach ($this->records($values, $semester) as $record) {
                if ($semester === null) {
                    break; // didascalia senza «Semestre AAAA/N»: non è un file OMI
                }

                $min = $this->number($record['Compr_min'] ?? '');
                $max = $this->number($record['Compr_max'] ?? '');
                $stato = strtoupper(trim($record['Stato'] ?? ''));
                if ($min === null || $max === null || $max <= 0 || ! in_array($stato, OmiQuotation::STATES, true) || ($record['LinkZona'] ?? '') === '') {
                    continue;
                }

                $comuni[$record['Comune_amm'] ?? $record['Comune_descrizione']] = true;
                $chunk[] = [
                    'user_id' => $userId,
                    'semester' => $semester,
                    'link_zona' => $record['LinkZona'],
                    'comune' => mb_substr($record['Comune_descrizione'] ?? '', 0, 80),
                    'prov' => mb_substr($record['Prov'] ?? '', 0, 2),
                    'zona' => mb_substr($record['Zona'] ?? '', 0, 8),
                    'zona_descr' => $descriptions[$record['LinkZona']] ?? null,
                    'cod_tip' => (int) ($record['Cod_Tip'] ?? 0),
                    'tipologia' => mb_substr($record['Descr_Tipologia'] ?? '', 0, 60),
                    'stato' => $stato,
                    'compr_min' => $min,
                    'compr_max' => $max,
                    'sup_nl' => in_array($record['Sup_NL_compr'] ?? '', ['L', 'N'], true) ? $record['Sup_NL_compr'] : null,
                ];

                if (count($chunk) >= self::CHUNK) {
                    $rows += $this->flush($chunk);
                }
            }

            $rows += $this->flush($chunk);
        });

        if ($rows === 0 || $semester === null) {
            throw ValidationException::withMessages(['file' => 'Nessuna quotazione trovata: serve il file VALORI delle Forniture dati OMI (o lo zip che lo contiene).']);
        }

        return ['semester' => $semester, 'rows' => $rows, 'comuni' => count($comuni)];
    }

    /**
     * Testo del CSV dei valori e, se c'è, di quello delle zone.
     *
     * @return array{0: string, 1: string|null}
     */
    private function contents(UploadedFile $file): array
    {
        $zip = new ZipArchive;
        if ($zip->open((string) $file->getRealPath()) !== true) {
            return [(string) file_get_contents((string) $file->getRealPath()), null];
        }

        $values = null;
        $zones = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                continue;
            }

            $kind = match (true) {
                (bool) preg_match('/VALORI\.csv$/i', $stat['name']) => 'values',
                (bool) preg_match('/ZONE\.csv$/i', $stat['name']) => 'zones',
                default => null,
            };
            if ($kind === null) {
                continue;
            }

            // Si legge solo ciò che serve e con un tetto: uno zip può dichiarare entry enormi.
            if ($stat['size'] > self::MAX_ENTRY_BYTES) {
                throw ValidationException::withMessages(['file' => 'Il file nello zip è troppo grande: scarica le quotazioni del comune o della regione.']);
            }

            $text = (string) $zip->getFromIndex($i);
            $kind === 'values' ? $values = $text : $zones = $text;
        }
        $zip->close();

        if ($values === null) {
            throw ValidationException::withMessages(['file' => 'Nello zip manca il file VALORI delle quotazioni OMI.']);
        }

        return [$values, $zones];
    }

    /** @return array<string, string> LinkZona => descrizione della zona */
    private function zoneDescriptions(string $zones): array
    {
        $semester = null;
        $out = [];
        foreach ($this->records($zones, $semester) as $record) {
            $descr = trim($record['Zona_Descr'] ?? '', " '");
            if (($record['LinkZona'] ?? '') !== '' && $descr !== '') {
                $out[$record['LinkZona']] = mb_substr($descr, 0, 255);
            }
        }

        return $out;
    }

    /**
     * Righe del CSV come array associativi. Il semestre si legge dalla didascalia.
     *
     * @return \Generator<int, array<string, string>>
     */
    private function records(string $text, ?string &$semester): \Generator
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $text);
        rewind($stream);

        $caption = (string) fgets($stream);
        if (preg_match('/Semestre\s+(\d{4})\/([12])/i', $caption, $m) === 1) {
            $semester = $m[1].($m[2] === '1' ? '-01-01' : '-07-01');
        }

        $header = fgetcsv($stream, separator: ';', escape: '');
        if ($header === false) {
            fclose($stream);

            return;
        }
        $header = array_map(fn (?string $h) => trim((string) $h), $header);

        while (($line = fgetcsv($stream, separator: ';', escape: '')) !== false) {
            if ($line === [null]) {
                continue;
            }
            $line = array_pad(array_slice($line, 0, count($header)), count($header), '');
            yield array_combine($header, array_map(fn (?string $v) => $this->utf8(trim((string) $v)), $line));
        }

        fclose($stream);
    }

    /** @param  list<array<string, mixed>>  $chunk */
    private function flush(array &$chunk): int
    {
        if ($chunk === []) {
            return 0;
        }

        OmiQuotation::query()->upsert(
            $chunk,
            ['user_id', 'link_zona', 'cod_tip', 'stato', 'semester'],
            ['comune', 'prov', 'zona', 'zona_descr', 'tipologia', 'compr_min', 'compr_max', 'sup_nl', 'updated_at'],
        );
        $count = count($chunk);
        $chunk = [];

        return $count;
    }

    private function number(string $value): ?float
    {
        $value = str_replace(',', '.', $value);

        return is_numeric($value) ? (float) $value : null;
    }

    /** Per campo e non per file: nei CSV OMI capitano righe in UTF-8 e righe in Windows-1252. */
    private function utf8(string $text): string
    {
        return mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    }
}

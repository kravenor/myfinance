<?php

namespace App\Services\Import;

use Illuminate\Http\UploadedFile;

abstract class ImportReader
{
    /**
     * Legge il file e ritorna headers + righe normalizzate.
     *
     * @return array{headers: array<int, string>, rows: array<int, array<string, string>>, mapping_locked: bool}
     */
    abstract public function read(UploadedFile $file, int $limit): array;

    /**
     * Mapping suggerito (o forzato, se mapping_locked) campo logico -> header.
     *
     * @param  array<int, string>  $headers
     * @return array<string, ?string>
     */
    abstract public function suggestedMapping(array $headers): array;

    /**
     * Identificatore del formato gestito (csv|ofx|qif).
     */
    abstract public function format(): string;
}

<?php

namespace App\Services\Import;

use Illuminate\Http\UploadedFile;

class ImportReaderFactory
{
    public function for(UploadedFile $file, ?string $explicit = null): ImportReader
    {
        $format = $explicit ?? $this->detect($file);

        return match ($format) {
            'ofx' => new OfxReader,
            'qif' => new QifReader,
            default => new CsvReader,
        };
    }

    public function detect(UploadedFile $file): string
    {
        $ext = strtolower((string) $file->getClientOriginalExtension());
        if (in_array($ext, ['ofx', 'qfx'], true)) {
            return 'ofx';
        }
        if ($ext === 'qif') {
            return 'qif';
        }

        // Content sniff sui primi 512 byte quando l'estensione non è dirimente.
        $head = (string) file_get_contents($file->getRealPath(), false, null, 0, 512);
        $trimmed = ltrim($head);
        if (str_contains($head, '<OFX') || str_starts_with($trimmed, 'OFXHEADER') || str_starts_with($trimmed, '<?xml')) {
            return 'ofx';
        }
        if (str_starts_with($trimmed, '!Type:')) {
            return 'qif';
        }

        return 'csv';
    }
}

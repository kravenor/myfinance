<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Transaction;
use App\Services\Import\ImportReaderFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

class TransactionImportService
{
    public function __construct(
        private readonly CategorizationRuleMatcher $matcher,
        private readonly ImportReaderFactory $readers,
    ) {}

    /**
     * Legge il file (CSV/OFX/QIF) e ritorna formato, headers, righe campione e mapping suggerito.
     *
     * @return array{format: string, headers: array<int, string>, sample: array<int, array<string, string>>, mapping_locked: bool, suggested: array<string, ?string>}
     */
    public function preview(UploadedFile $file, int $sampleSize = 10): array
    {
        $reader = $this->readers->for($file);
        $data = $reader->read($file, $sampleSize);

        return [
            'format' => $reader->format(),
            'headers' => $data['headers'],
            'sample' => $data['rows'],
            'mapping_locked' => $data['mapping_locked'],
            'suggested' => $reader->suggestedMapping($data['headers']),
        ];
    }

    /**
     * Per le righe campione, predice categoria via regole utente.
     *
     * @param  array<string, ?string>  $mapping
     * @return array<int, array{category_id: ?int, category_name: ?string, rule_id: ?int}>
     */
    public function previewPredictions(UploadedFile $file, array $mapping, int $sampleSize = 50): array
    {
        $reader = $this->readers->for($file);
        $data = $reader->read($file, $sampleSize);
        $rows = $data['rows'];

        if ($data['mapping_locked']) {
            $mapping = $reader->suggestedMapping($data['headers']);
        }

        $byName = Category::query()->get()->keyBy(fn ($c) => mb_strtolower($c->name));
        $this->matcher->reset();
        $this->matcher->preload();

        $predictions = [];
        foreach ($rows as $row) {
            $type = $this->safeResolveType($mapping, $row);
            $description = ! empty($mapping['description'])
                ? trim((string) ($row[$mapping['description']] ?? '')) ?: null
                : null;

            $categoryId = null;
            $categoryName = null;
            $ruleId = null;

            if (! empty($mapping['category'])) {
                $name = trim((string) ($row[$mapping['category']] ?? ''));
                if ($name !== '') {
                    $cat = $byName->get(mb_strtolower($name));
                    if ($cat) {
                        $categoryId = $cat->id;
                        $categoryName = $cat->name;
                    }
                }
            }

            if ($categoryId === null) {
                $rule = $this->matcher->match($description, $type);
                if ($rule) {
                    $ruleId = $rule->id;
                    $categoryId = $rule->category_id;
                    $cat = $byName->first(fn ($c) => $c->id === $rule->category_id);
                    $categoryName = $cat?->name;
                }
            }

            $predictions[] = [
                'category_id' => $categoryId,
                'category_name' => $categoryName,
                'rule_id' => $ruleId,
            ];
        }

        return $predictions;
    }

    /**
     * Esegue l'import con mapping confermato (forzato per OFX/QIF).
     *
     * @param  array<string, ?string>  $mapping
     * @return array{imported: int, skipped: int, duplicates: int, auto_categorized: int, errors: array<int, array{row: int, message: string}>}
     */
    public function import(
        UploadedFile $file,
        int $accountId,
        array $mapping,
        string $dateFormat = 'Y-m-d',
        string $currency = 'EUR',
    ): array {
        $reader = $this->readers->for($file);
        $data = $reader->read($file, PHP_INT_MAX);
        $rows = $data['rows'];

        // I formati strutturati (OFX/QIF) hanno campi fissi e date già normalizzate ISO.
        if ($data['mapping_locked']) {
            $mapping = $reader->suggestedMapping($data['headers']);
            $dateFormat = 'Y-m-d';
        }

        $byName = Category::query()->get()->keyBy(fn ($c) => mb_strtolower($c->name));
        $this->matcher->reset();
        $this->matcher->preload();

        // Dedup: external_id già presenti per l'utente (global scope) + visti nel batch.
        $existingExternalIds = Transaction::query()
            ->whereNotNull('external_id')
            ->pluck('external_id')
            ->flip();
        $seenInBatch = [];

        $imported = 0;
        $skipped = 0;
        $duplicates = 0;
        $autoCategorized = 0;
        $errors = [];

        foreach ($rows as $i => $row) {
            $rowNumber = $i + 2; // header on line 1

            try {
                $raw = trim((string) ($row[$mapping['date']] ?? ''));
                $date = $raw === '' ? null : Carbon::createFromFormat($dateFormat, $raw);
                if (! $date) {
                    throw new \RuntimeException("Data non valida: {$raw}");
                }

                $amountRaw = (string) ($row[$mapping['amount']] ?? '');
                $amount = $this->parseAmount($amountRaw);
                if ($amount === null) {
                    throw new \RuntimeException("Importo non valido: {$amountRaw}");
                }

                $type = $this->resolveType($mapping, $row, $amount);
                $description = ! empty($mapping['description'])
                    ? trim((string) ($row[$mapping['description']] ?? '')) ?: null
                    : null;
                $notes = ! empty($mapping['notes'])
                    ? trim((string) ($row[$mapping['notes']] ?? '')) ?: null
                    : null;
                $externalId = ! empty($mapping['external_id'])
                    ? trim((string) ($row[$mapping['external_id']] ?? '')) ?: null
                    : null;

                if ($externalId !== null
                    && (isset($existingExternalIds[$externalId]) || isset($seenInBatch[$externalId]))) {
                    $duplicates++;

                    continue;
                }

                $categoryId = null;
                if (! empty($mapping['category'])) {
                    $name = trim((string) ($row[$mapping['category']] ?? ''));
                    if ($name !== '') {
                        $categoryId = $byName->get(mb_strtolower($name))?->id;
                    }
                }

                $matchedRule = null;
                if ($categoryId === null) {
                    $matchedRule = $this->matcher->match($description, $type);
                    if ($matchedRule) {
                        $categoryId = $matchedRule->category_id;
                    }
                }

                Transaction::create([
                    'account_id' => $accountId,
                    'category_id' => $categoryId,
                    'type' => $type,
                    'amount' => abs($amount),
                    'currency' => $currency,
                    'occurred_at' => $date->toDateString(),
                    'description' => $description,
                    'notes' => $notes,
                    'external_id' => $externalId,
                ]);

                if ($externalId !== null) {
                    $seenInBatch[$externalId] = true;
                }

                if ($matchedRule) {
                    $this->matcher->recordHit($matchedRule);
                    $autoCategorized++;
                }

                $imported++;
            } catch (\Throwable $e) {
                $skipped++;
                $errors[] = ['row' => $rowNumber, 'message' => $e->getMessage()];
            }
        }

        $this->matcher->flushHits();

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'duplicates' => $duplicates,
            'auto_categorized' => $autoCategorized,
            'errors' => $errors,
        ];
    }

    private function parseAmount(string $raw): ?float
    {
        $clean = preg_replace('/[^0-9,.\-]/', '', $raw);
        if ($clean === '' || $clean === null) {
            return null;
        }
        $hasComma = str_contains($clean, ',');
        $hasDot = str_contains($clean, '.');
        if ($hasComma && $hasDot) {
            // assume "1.234,56" (italian style)
            $clean = str_replace('.', '', $clean);
            $clean = str_replace(',', '.', $clean);
        } elseif ($hasComma) {
            $clean = str_replace(',', '.', $clean);
        }
        if (! is_numeric($clean)) {
            return null;
        }

        return (float) $clean;
    }

    /**
     * Versione tollerante per la preview: deduce type da colonna type o, se assente,
     * dal segno dell'importo parsato. Fallback `expense` se nulla è disponibile.
     *
     * @param  array<string, ?string>  $mapping
     * @param  array<string, string>  $row
     */
    private function safeResolveType(array $mapping, array $row): string
    {
        $amount = 0.0;
        if (! empty($mapping['amount'])) {
            $parsed = $this->parseAmount((string) ($row[$mapping['amount']] ?? ''));
            if ($parsed !== null) {
                $amount = $parsed;
            }
        }

        return $this->resolveType($mapping, $row, $amount);
    }

    /**
     * @param  array<string, ?string>  $mapping
     * @param  array<string, string>  $row
     */
    private function resolveType(array $mapping, array $row, float $amount): string
    {
        if (! empty($mapping['type'])) {
            $value = mb_strtolower(trim((string) ($row[$mapping['type']] ?? '')));
            if (in_array($value, ['income', 'expense'], true)) {
                return $value;
            }
            if (in_array($value, ['entrata', 'accredito', 'credit'], true)) {
                return 'income';
            }
            if (in_array($value, ['uscita', 'addebito', 'debit'], true)) {
                return 'expense';
            }
        }

        return $amount >= 0 ? 'income' : 'expense';
    }
}

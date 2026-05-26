<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

class TransactionImportService
{
    public function __construct(
        private readonly CategorizationRuleMatcher $matcher,
    ) {}

    /**
     * Legge il CSV e ritorna headers + righe campione + mapping suggerito.
     *
     * @return array{headers: array<int, string>, sample: array<int, array<string, string>>, suggested: array<string, ?string>}
     */
    public function preview(UploadedFile $file, int $sampleSize = 10): array
    {
        [$headers, $rows] = $this->parse($file, $sampleSize);

        return [
            'headers' => $headers,
            'sample' => $rows,
            'suggested' => $this->suggestMapping($headers),
        ];
    }

    /**
     * Per le righe campione, predice categoria via regole utente.
     *
     * @param  array{date: string, amount: string, description?: ?string, type?: ?string, category?: ?string}  $mapping
     * @return array<int, array{category_id: ?int, category_name: ?string, rule_id: ?int}>
     */
    public function previewPredictions(UploadedFile $file, array $mapping, int $sampleSize = 50): array
    {
        [, $rows] = $this->parse($file, $sampleSize);

        $byName = Category::query()->get()->keyBy(fn ($c) => mb_strtolower($c->name));
        $this->matcher->reset();
        $this->matcher->preload();

        $predictions = [];
        foreach ($rows as $row) {
            $type = $this->safeResolveType($mapping, $row);
            $description = isset($mapping['description']) && $mapping['description']
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
     * Esegue l'import con mapping confermato.
     *
     * @param  array{date: string, amount: string, description?: ?string, type?: ?string, category?: ?string}  $mapping
     * @return array{imported: int, skipped: int, errors: array<int, array{row: int, message: string}>}
     */
    public function import(
        UploadedFile $file,
        int $accountId,
        array $mapping,
        string $dateFormat = 'Y-m-d',
        string $currency = 'EUR',
    ): array {
        [$headers, $rows] = $this->parse($file, PHP_INT_MAX);

        $byName = Category::query()->get()->keyBy(fn ($c) => mb_strtolower($c->name));
        $this->matcher->reset();
        $this->matcher->preload();

        $imported = 0;
        $skipped = 0;
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
                $description = isset($mapping['description']) && $mapping['description']
                    ? trim((string) ($row[$mapping['description']] ?? '')) ?: null
                    : null;

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
                ]);

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
            'auto_categorized' => $autoCategorized,
            'errors' => $errors,
        ];
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, array<string, string>>}
     */
    private function parse(UploadedFile $file, int $limit): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        if ($handle === false) {
            throw new \RuntimeException('Impossibile leggere il file caricato.');
        }

        $delimiter = $this->detectDelimiter($file);

        $headers = fgetcsv($handle, 0, $delimiter) ?: [];
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

        return [$headers, $rows];
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

    /**
     * @param  array<int, string>  $headers
     * @return array<string, ?string>
     */
    private function suggestMapping(array $headers): array
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
     * @param  array<string, mixed>  $mapping
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
     * @param  array<string, mixed>  $mapping
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

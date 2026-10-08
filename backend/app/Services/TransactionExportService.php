<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TransactionExportService
{
    public function stream(Request $request): StreamedResponse
    {
        $filename = 'transactions-'.now()->format('Y-m-d').'.csv';

        return new StreamedResponse(function () use ($request) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'occurred_at',
                'type',
                'amount',
                'currency',
                'account',
                'transfer_account',
                'category',
                'description',
                'notes',
                'external_id',
            ]);

            $accounts = Account::query()->pluck('name', 'id');
            $categories = Category::query()->pluck('name', 'id');

            $query = Transaction::query()->orderBy('occurred_at')->orderBy('id');

            if ($request->filled('account_id')) {
                $id = $request->integer('account_id');
                $query->where(fn ($q) => $q->where('account_id', $id)->orWhere('transfer_account_id', $id));
            }
            if ($request->filled('type')) {
                $query->where('type', $request->string('type'));
            }
            if ($request->filled('from')) {
                $query->whereDate('occurred_at', '>=', $request->date('from'));
            }
            if ($request->filled('to')) {
                $query->whereDate('occurred_at', '<=', $request->date('to'));
            }

            $query->chunk(500, function ($transactions) use ($handle, $accounts, $categories) {
                foreach ($transactions as $t) {
                    fputcsv($handle, [
                        $t->occurred_at->toDateString(),
                        $t->type,
                        $t->amount,
                        $t->currency,
                        self::cell($accounts[$t->account_id] ?? ''),
                        self::cell($t->transfer_account_id ? ($accounts[$t->transfer_account_id] ?? '') : ''),
                        self::cell($t->category_id ? ($categories[$t->category_id] ?? '') : ''),
                        self::cell($t->description ?? ''),
                        self::cell($t->notes ?? ''),
                        self::cell($t->external_id ?? ''),
                    ]);
                }
            });

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Testo libero (descrizioni importate dalla banca, nomi): Excel esegue le celle che iniziano
     * con = + - @ tab o CR come formule. L'apostrofo le fa leggere come testo.
     */
    private static function cell(string $value): string
    {
        return $value !== '' && str_contains("=+-@\t\r", $value[0]) ? "'".$value : $value;
    }
}

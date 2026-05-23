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
                        $accounts[$t->account_id] ?? '',
                        $t->transfer_account_id ? ($accounts[$t->transfer_account_id] ?? '') : '',
                        $t->category_id ? ($categories[$t->category_id] ?? '') : '',
                        $t->description ?? '',
                        $t->notes ?? '',
                        $t->external_id ?? '',
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
}

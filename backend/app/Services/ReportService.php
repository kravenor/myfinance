<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Support\Carbon;

class ReportService
{
    /**
     * Income, expense, net nel range + saldo per conto.
     *
     * @return array<string, mixed>
     */
    public function summary(Carbon $from, Carbon $to): array
    {
        $rows = Transaction::query()
            ->whereBetween('occurred_at', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('type, SUM(amount) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $income = (float) ($rows['income'] ?? 0);
        $expense = (float) ($rows['expense'] ?? 0);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'income' => $this->fmt($income),
            'expense' => $this->fmt($expense),
            'net' => $this->fmt($income - $expense),
            'accounts' => $this->accountBalances($to),
            'net_worth' => $this->fmt($this->cumulativeBalance($to)),
        ];
    }

    /**
     * Totale transazioni per categoria nel range.
     *
     * @return array<int, array<string, mixed>>
     */
    public function byCategory(Carbon $from, Carbon $to, string $type): array
    {
        $rows = Transaction::query()
            ->where('type', $type)
            ->whereBetween('occurred_at', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('category_id, SUM(amount) as total')
            ->groupBy('category_id')
            ->orderByDesc('total')
            ->get();

        $categoryIds = $rows->pluck('category_id')->filter()->all();
        $categories = Category::query()
            ->whereIn('id', $categoryIds)
            ->pluck('name', 'id');

        return $rows->map(fn ($row) => [
            'category_id' => $row->category_id,
            'category_name' => $row->category_id ? ($categories[$row->category_id] ?? '—') : 'Senza categoria',
            'total' => $this->fmt((float) $row->total),
        ])->all();
    }

    /**
     * Serie mensile income/expense nel range.
     *
     * @return array<int, array<string, mixed>>
     */
    public function timeline(Carbon $from, Carbon $to): array
    {
        $transactions = Transaction::query()
            ->whereIn('type', ['income', 'expense'])
            ->whereBetween('occurred_at', [$from->toDateString(), $to->toDateString()])
            ->get(['type', 'amount', 'occurred_at']);

        $buckets = $this->monthBuckets($from, $to);

        foreach ($transactions as $t) {
            $key = $t->occurred_at->format('Y-m');
            if (! isset($buckets[$key])) {
                continue;
            }
            $buckets[$key][$t->type] += (float) $t->amount;
        }

        return collect($buckets)->map(fn ($v, $k) => [
            'period' => $k,
            'income' => $this->fmt($v['income']),
            'expense' => $this->fmt($v['expense']),
            'net' => $this->fmt($v['income'] - $v['expense']),
        ])->values()->all();
    }

    /**
     * Net worth cumulato a fine di ogni mese nel range.
     *
     * @return array<int, array<string, mixed>>
     */
    public function netWorth(Carbon $from, Carbon $to): array
    {
        $initial = (float) Account::query()->sum('initial_balance');

        $monthly = Transaction::query()
            ->whereIn('type', ['income', 'expense'])
            ->where('occurred_at', '<=', $to->toDateString())
            ->get(['type', 'amount', 'occurred_at']);

        $byMonth = collect($this->monthBuckets($from, $to));
        $earlierTotal = 0.0;

        foreach ($monthly as $t) {
            $key = $t->occurred_at->format('Y-m');
            $delta = $t->type === 'income' ? (float) $t->amount : -(float) $t->amount;
            if ($t->occurred_at->lt($from)) {
                $earlierTotal += $delta;

                continue;
            }
            if (! $byMonth->has($key)) {
                continue;
            }
            $bucket = $byMonth->get($key);
            $bucket['delta'] = ($bucket['delta'] ?? 0) + $delta;
            $byMonth->put($key, $bucket);
        }

        $cumulative = $initial + $earlierTotal;
        $out = [];
        foreach ($byMonth as $key => $bucket) {
            $cumulative += $bucket['delta'] ?? 0;
            $out[] = [
                'period' => $key,
                'net_worth' => $this->fmt($cumulative),
            ];
        }

        return $out;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function accountBalances(Carbon $upTo): array
    {
        $accounts = Account::query()->orderBy('name')->get();
        if ($accounts->isEmpty()) {
            return [];
        }

        $debits = Transaction::query()
            ->where('occurred_at', '<=', $upTo->toDateString())
            ->whereIn('type', ['expense', 'transfer'])
            ->selectRaw('account_id, SUM(amount) as total')
            ->groupBy('account_id')
            ->pluck('total', 'account_id');

        $credits = Transaction::query()
            ->where('occurred_at', '<=', $upTo->toDateString())
            ->where('type', 'income')
            ->selectRaw('account_id, SUM(amount) as total')
            ->groupBy('account_id')
            ->pluck('total', 'account_id');

        $transfersIn = Transaction::query()
            ->where('occurred_at', '<=', $upTo->toDateString())
            ->where('type', 'transfer')
            ->whereNotNull('transfer_account_id')
            ->selectRaw('transfer_account_id, SUM(amount) as total')
            ->groupBy('transfer_account_id')
            ->pluck('total', 'transfer_account_id');

        return $accounts->map(fn (Account $a) => [
            'id' => $a->id,
            'name' => $a->name,
            'currency' => $a->currency,
            'balance' => $this->fmt(
                (float) $a->initial_balance
                + (float) ($credits[$a->id] ?? 0)
                - (float) ($debits[$a->id] ?? 0)
                + (float) ($transfersIn[$a->id] ?? 0)
            ),
        ])->all();
    }

    private function cumulativeBalance(Carbon $upTo): float
    {
        $initial = (float) Account::query()->sum('initial_balance');

        $delta = Transaction::query()
            ->whereIn('type', ['income', 'expense'])
            ->where('occurred_at', '<=', $upTo->toDateString())
            ->selectRaw("SUM(CASE WHEN type='income' THEN amount ELSE -amount END) as total")
            ->value('total');

        return $initial + (float) ($delta ?? 0);
    }

    /**
     * @return array<string, array<string, float>>
     */
    private function monthBuckets(Carbon $from, Carbon $to): array
    {
        $cursor = $from->copy()->startOfMonth();
        $end = $to->copy()->startOfMonth();
        $buckets = [];
        while ($cursor->lte($end)) {
            $buckets[$cursor->format('Y-m')] = ['income' => 0.0, 'expense' => 0.0];
            $cursor->addMonthNoOverflow();
        }

        return $buckets;
    }

    private function fmt(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}

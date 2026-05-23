<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Category;
use App\Models\RecurringTransaction;
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
        $savingRate = $income > 0 ? ($income - $expense) / $income : 0.0;

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'income' => $this->fmt($income),
            'expense' => $this->fmt($expense),
            'net' => $this->fmt($income - $expense),
            'saving_rate' => $this->fmt($savingRate * 100),
            'accounts' => $this->accountBalances($to),
            'net_worth' => $this->fmt($this->cumulativeBalance($to)),
        ];
    }

    /**
     * Confronta un periodo con il precedente equivalente (mese vs mese precedente, anno vs anno).
     *
     * @return array<string, mixed>
     */
    public function periodComparison(Carbon $reference, string $unit): array
    {
        if (! in_array($unit, ['month', 'year'], true)) {
            $unit = 'month';
        }

        if ($unit === 'month') {
            $currentFrom = $reference->copy()->startOfMonth();
            $currentTo = $reference->copy()->endOfMonth();
            $previousFrom = $reference->copy()->subMonthNoOverflow()->startOfMonth();
            $previousTo = $reference->copy()->subMonthNoOverflow()->endOfMonth();
        } else {
            $currentFrom = $reference->copy()->startOfYear();
            $currentTo = $reference->copy()->endOfYear();
            $previousFrom = $reference->copy()->subYearNoOverflow()->startOfYear();
            $previousTo = $reference->copy()->subYearNoOverflow()->endOfYear();
        }

        $current = $this->totalsFor($currentFrom, $currentTo);
        $previous = $this->totalsFor($previousFrom, $previousTo);

        return [
            'unit' => $unit,
            'current' => [
                'label' => $unit === 'month' ? $currentFrom->format('Y-m') : (string) $currentFrom->year,
                'from' => $currentFrom->toDateString(),
                'to' => $currentTo->toDateString(),
                'income' => $this->fmt($current['income']),
                'expense' => $this->fmt($current['expense']),
                'net' => $this->fmt($current['income'] - $current['expense']),
            ],
            'previous' => [
                'label' => $unit === 'month' ? $previousFrom->format('Y-m') : (string) $previousFrom->year,
                'from' => $previousFrom->toDateString(),
                'to' => $previousTo->toDateString(),
                'income' => $this->fmt($previous['income']),
                'expense' => $this->fmt($previous['expense']),
                'net' => $this->fmt($previous['income'] - $previous['expense']),
            ],
            'delta' => [
                'income' => $this->fmt($current['income'] - $previous['income']),
                'income_pct' => $this->pct($previous['income'], $current['income']),
                'expense' => $this->fmt($current['expense'] - $previous['expense']),
                'expense_pct' => $this->pct($previous['expense'], $current['expense']),
                'net' => $this->fmt(($current['income'] - $current['expense']) - ($previous['income'] - $previous['expense'])),
            ],
        ];
    }

    /**
     * Serie mensile per le top N categorie nel range.
     *
     * @return array<string, mixed>
     */
    public function categoryTrend(Carbon $from, Carbon $to, string $type, int $top = 5): array
    {
        $buckets = $this->monthBuckets($from, $to);
        $monthsKeys = array_keys($buckets);

        $totals = Transaction::query()
            ->where('type', $type)
            ->whereBetween('occurred_at', [$from->toDateString(), $to->toDateString()])
            ->whereNotNull('category_id')
            ->selectRaw('category_id, SUM(amount) as total')
            ->groupBy('category_id')
            ->orderByDesc('total')
            ->limit($top)
            ->get();

        $categoryIds = $totals->pluck('category_id')->all();
        $names = Category::query()->whereIn('id', $categoryIds)->pluck('name', 'id');

        $rows = Transaction::query()
            ->where('type', $type)
            ->whereIn('category_id', $categoryIds)
            ->whereBetween('occurred_at', [$from->toDateString(), $to->toDateString()])
            ->get(['category_id', 'amount', 'occurred_at']);

        $series = [];
        foreach ($categoryIds as $cid) {
            $series[$cid] = array_fill_keys($monthsKeys, 0.0);
        }
        foreach ($rows as $t) {
            $key = $t->occurred_at->format('Y-m');
            if (! isset($series[$t->category_id][$key])) {
                continue;
            }
            $series[$t->category_id][$key] += (float) $t->amount;
        }

        return [
            'periods' => $monthsKeys,
            'categories' => collect($categoryIds)->map(fn ($id) => [
                'category_id' => $id,
                'category_name' => $names[$id] ?? "#{$id}",
                'values' => array_map(fn ($v) => $this->fmt($v), array_values($series[$id])),
            ])->all(),
        ];
    }

    /**
     * Top N transazioni per importo nel range.
     *
     * @return array<int, array<string, mixed>>
     */
    public function topTransactions(Carbon $from, Carbon $to, string $type, int $limit = 10): array
    {
        $query = Transaction::query()
            ->whereBetween('occurred_at', [$from->toDateString(), $to->toDateString()]);

        if ($type !== '') {
            $query->where('type', $type);
        }

        $rows = $query->orderByDesc('amount')->limit($limit)->get();

        $categories = Category::query()
            ->whereIn('id', $rows->pluck('category_id')->filter()->all())
            ->pluck('name', 'id');
        $accounts = Account::query()
            ->whereIn('id', $rows->pluck('account_id')->all())
            ->pluck('name', 'id');

        return $rows->map(fn (Transaction $t) => [
            'id' => $t->id,
            'occurred_at' => $t->occurred_at->toDateString(),
            'type' => $t->type,
            'amount' => $t->amount,
            'currency' => $t->currency,
            'account_name' => $accounts[$t->account_id] ?? null,
            'category_name' => $t->category_id ? ($categories[$t->category_id] ?? null) : null,
            'description' => $t->description,
        ])->all();
    }

    /**
     * Proietta i flussi futuri stimati per N mesi a partire dalle ricorrenti attive.
     *
     * @return array<int, array<string, mixed>>
     */
    public function cashFlowForecast(int $months = 6): array
    {
        $months = max(1, min(24, $months));
        $start = Carbon::now()->startOfMonth();
        $end = $start->copy()->addMonthsNoOverflow($months - 1)->endOfMonth();

        $buckets = $this->monthBuckets($start, $end);
        $deltas = array_fill_keys(array_keys($buckets), ['income' => 0.0, 'expense' => 0.0]);

        $recurrings = RecurringTransaction::query()
            ->where('is_active', true)
            ->whereIn('type', ['income', 'expense'])
            ->get();

        foreach ($recurrings as $r) {
            $cursor = $r->next_run_at->copy();
            while ($cursor->lte($end)) {
                if ($r->ends_on && $cursor->gt($r->ends_on)) {
                    break;
                }
                if ($cursor->gte($start)) {
                    $key = $cursor->format('Y-m');
                    if (isset($deltas[$key])) {
                        $deltas[$key][$r->type] += (float) $r->amount;
                    }
                }
                $cursor = $this->advance($cursor, $r->cadence, max(1, (int) $r->interval));
            }
        }

        $running = $this->cumulativeBalance($start->copy()->subDay());
        $out = [];
        foreach ($deltas as $key => $d) {
            $running += $d['income'] - $d['expense'];
            $out[] = [
                'period' => $key,
                'income' => $this->fmt($d['income']),
                'expense' => $this->fmt($d['expense']),
                'net' => $this->fmt($d['income'] - $d['expense']),
                'projected_net_worth' => $this->fmt($running),
            ];
        }

        return $out;
    }

    /**
     * @return array{income: float, expense: float}
     */
    private function totalsFor(Carbon $from, Carbon $to): array
    {
        $rows = Transaction::query()
            ->whereBetween('occurred_at', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('type, SUM(amount) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        return [
            'income' => (float) ($rows['income'] ?? 0),
            'expense' => (float) ($rows['expense'] ?? 0),
        ];
    }

    private function pct(float $previous, float $current): ?string
    {
        if ($previous === 0.0) {
            return null;
        }

        return $this->fmt((($current - $previous) / $previous) * 100);
    }

    private function advance(Carbon $from, string $cadence, int $interval): Carbon
    {
        return match ($cadence) {
            'daily' => $from->copy()->addDays($interval),
            'weekly' => $from->copy()->addWeeks($interval),
            'biweekly' => $from->copy()->addWeeks(2 * $interval),
            'monthly' => $from->copy()->addMonthsNoOverflow($interval),
            'quarterly' => $from->copy()->addMonthsNoOverflow(3 * $interval),
            'yearly' => $from->copy()->addYearsNoOverflow($interval),
            default => throw new \UnexpectedValueException("Cadenza non supportata: {$cadence}"),
        };
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
            'total' => $this->fmt((float) $row->getAttribute('total')),
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

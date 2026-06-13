<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Category;
use App\Models\RecurringTransaction;
use App\Models\Tag;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Tutti gli importi sono convertiti nella valuta base dell'utente
 * (`user.currency`) tramite {@see CurrencyConverter}, applicando il tasso
 * vigente alla `occurred_at` di ciascuna transazione ("tasso alla data").
 */
class ReportService
{
    private ?string $baseCurrency = null;

    public function __construct(private readonly CurrencyConverter $converter) {}

    /**
     * Income, expense, net nel range + saldo per conto.
     *
     * @return array<string, mixed>
     */
    public function summary(Carbon $from, Carbon $to): array
    {
        $totals = $this->totalsFor($from, $to);
        $income = $totals['income'];
        $expense = $totals['expense'];
        $savingRate = $income > 0 ? ($income - $expense) / $income : 0.0;

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'base_currency' => $this->baseCurrency(),
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
            'base_currency' => $this->baseCurrency(),
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
        $monthsKeys = array_keys($this->monthBuckets($from, $to));

        $rows = Transaction::query()
            ->where('type', $type)
            ->whereBetween('occurred_at', [$from->toDateString(), $to->toDateString()])
            ->whereNotNull('category_id')
            ->get(['category_id', 'amount', 'currency', 'occurred_at']);

        $totalPerCat = [];
        $series = [];
        foreach ($rows as $t) {
            $base = $this->toBase($t);
            $cid = (int) $t->category_id;
            $totalPerCat[$cid] = ($totalPerCat[$cid] ?? 0.0) + $base;
            $key = $t->occurred_at->format('Y-m');
            $series[$cid][$key] = ($series[$cid][$key] ?? 0.0) + $base;
        }

        arsort($totalPerCat);
        $categoryIds = array_slice(array_keys($totalPerCat), 0, $top);
        $names = Category::query()->whereIn('id', $categoryIds)->pluck('name', 'id');

        return [
            'periods' => $monthsKeys,
            'categories' => collect($categoryIds)->map(fn ($cid) => [
                'category_id' => $cid,
                'category_name' => $names[$cid] ?? "#{$cid}",
                'values' => array_map(fn ($key) => $this->fmt($series[$cid][$key] ?? 0.0), $monthsKeys),
            ])->all(),
        ];
    }

    /**
     * Top N transazioni per importo (convertito in valuta base) nel range.
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

        $rows = $query->get();

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
            'amount_base' => $this->fmt($this->toBase($t)),
            'account_name' => $accounts[$t->account_id] ?? null,
            'category_name' => $t->category_id ? ($categories[$t->category_id] ?? null) : null,
            'description' => $t->description,
        ])
            ->sortByDesc('amount_base')
            ->take($limit)
            ->values()
            ->all();
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
        $base = $this->baseCurrency();

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
                        $deltas[$key][$r->type] += $this->converter->convert((float) $r->amount, $r->currency, $base, $cursor);
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
            ->whereIn('type', ['income', 'expense'])
            ->whereBetween('occurred_at', [$from->toDateString(), $to->toDateString()])
            ->get(['type', 'amount', 'currency', 'occurred_at']);

        $income = 0.0;
        $expense = 0.0;
        foreach ($rows as $t) {
            $base = $this->toBase($t);
            if ($t->type === 'income') {
                $income += $base;
            } else {
                $expense += $base;
            }
        }

        return ['income' => $income, 'expense' => $expense];
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
     * Totale transazioni per categoria nel range (convertito in valuta base).
     *
     * @return array<int, array<string, mixed>>
     */
    public function byCategory(Carbon $from, Carbon $to, string $type): array
    {
        $rows = Transaction::query()
            ->where('type', $type)
            ->whereBetween('occurred_at', [$from->toDateString(), $to->toDateString()])
            ->get(['category_id', 'amount', 'currency', 'occurred_at']);

        $totals = [];
        foreach ($rows as $t) {
            $key = $t->category_id === null ? 'null' : (string) $t->category_id;
            $totals[$key] = ($totals[$key] ?? 0.0) + $this->toBase($t);
        }
        arsort($totals);

        $ids = collect(array_keys($totals))
            ->reject(fn ($k) => $k === 'null')
            ->map(fn ($k) => (int) $k)
            ->all();
        $categories = Category::query()->whereIn('id', $ids)->pluck('name', 'id');

        return collect($totals)->map(fn ($total, $key) => [
            'category_id' => $key === 'null' ? null : (int) $key,
            'category_name' => $key === 'null' ? 'Senza categoria' : ($categories[(int) $key] ?? '—'),
            'total' => $this->fmt($total),
        ])->values()->all();
    }

    /**
     * Totale per tag nel range, per tipo (income/expense), convertito in valuta base.
     *
     * @return array<int, array<string, mixed>>
     */
    public function byTag(Carbon $from, Carbon $to, string $type): array
    {
        $rows = Transaction::query()
            ->where('transactions.type', $type)
            ->whereBetween('transactions.occurred_at', [$from->toDateString(), $to->toDateString()])
            ->join('tag_transaction', 'tag_transaction.transaction_id', '=', 'transactions.id')
            ->get([
                'tag_transaction.tag_id as tag_id',
                'transactions.amount as amount',
                'transactions.currency as currency',
                'transactions.occurred_at as occurred_at',
            ]);

        $totals = [];
        foreach ($rows as $r) {
            $tagId = (int) $r->getAttribute('tag_id');
            $totals[$tagId] = ($totals[$tagId] ?? 0.0) + $this->toBase($r);
        }
        arsort($totals);

        $tags = Tag::query()
            ->whereIn('id', array_keys($totals))
            ->get(['id', 'name', 'color'])
            ->keyBy('id');

        return collect($totals)->map(fn ($total, $tagId) => [
            'tag_id' => (int) $tagId,
            'tag_name' => $tags[$tagId]->name ?? '—',
            'tag_color' => $tags[$tagId]->color ?? null,
            'total' => $this->fmt($total),
        ])->values()->all();
    }

    /**
     * Serie mensile income/expense nel range (convertita in valuta base).
     *
     * @return array<int, array<string, mixed>>
     */
    public function timeline(Carbon $from, Carbon $to): array
    {
        $transactions = Transaction::query()
            ->whereIn('type', ['income', 'expense'])
            ->whereBetween('occurred_at', [$from->toDateString(), $to->toDateString()])
            ->get(['type', 'amount', 'currency', 'occurred_at']);

        $buckets = $this->monthBuckets($from, $to);

        foreach ($transactions as $t) {
            $key = $t->occurred_at->format('Y-m');
            if (! isset($buckets[$key])) {
                continue;
            }
            $buckets[$key][$t->type] += $this->toBase($t);
        }

        return collect($buckets)->map(fn ($v, $k) => [
            'period' => $k,
            'income' => $this->fmt($v['income']),
            'expense' => $this->fmt($v['expense']),
            'net' => $this->fmt($v['income'] - $v['expense']),
        ])->values()->all();
    }

    /**
     * Net worth cumulato (in valuta base) a fine di ogni mese nel range.
     *
     * @return array<int, array<string, mixed>>
     */
    public function netWorth(Carbon $from, Carbon $to): array
    {
        $out = [];
        foreach (array_keys($this->monthBuckets($from, $to)) as $key) {
            $monthEnd = Carbon::parse($key.'-01')->endOfMonth();
            $out[] = [
                'period' => $key,
                'net_worth' => $this->fmt($this->cumulativeBalance($monthEnd)),
            ];
        }

        return $out;
    }

    /**
     * Saldo per conto al $upTo: importo nella valuta propria del conto +
     * controvalore nella valuta base (al tasso corrente del $upTo).
     *
     * @return array<int, array<string, mixed>>
     */
    private function accountBalances(Carbon $upTo): array
    {
        $base = $this->baseCurrency();

        return $this->rawAccountBalances($upTo)->map(fn (array $a) => [
            'id' => $a['id'],
            'name' => $a['name'],
            'currency' => $a['currency'],
            'balance' => $this->fmt($a['balance']),
            'balance_base' => $this->fmt($this->converter->convert($a['balance'], $a['currency'], $base, $upTo)),
        ])->all();
    }

    /**
     * Saldo grezzo per conto (nella valuta del conto) al $upTo.
     * I transfer in ingresso usano `transfer_amount` (valuta destinazione)
     * con fallback su `amount`.
     *
     * @return Collection<int, array{id: int, name: string, currency: string, balance: float}>
     */
    private function rawAccountBalances(Carbon $upTo): Collection
    {
        $accounts = Account::query()->orderBy('name')->get();
        if ($accounts->isEmpty()) {
            return collect();
        }

        $date = $upTo->toDateString();

        $debits = Transaction::query()
            ->where('occurred_at', '<=', $date)
            ->whereIn('type', ['expense', 'transfer'])
            ->selectRaw('account_id, SUM(amount) as total')
            ->groupBy('account_id')
            ->pluck('total', 'account_id');

        $credits = Transaction::query()
            ->where('occurred_at', '<=', $date)
            ->where('type', 'income')
            ->selectRaw('account_id, SUM(amount) as total')
            ->groupBy('account_id')
            ->pluck('total', 'account_id');

        $transfersIn = Transaction::query()
            ->where('occurred_at', '<=', $date)
            ->where('type', 'transfer')
            ->whereNotNull('transfer_account_id')
            ->selectRaw('transfer_account_id, SUM(COALESCE(transfer_amount, amount)) as total')
            ->groupBy('transfer_account_id')
            ->pluck('total', 'transfer_account_id');

        return $accounts->map(fn (Account $a) => [
            'id' => $a->id,
            'name' => $a->name,
            'currency' => $a->currency,
            'balance' => (float) $a->initial_balance
                + (float) ($credits[$a->id] ?? 0)
                - (float) ($debits[$a->id] ?? 0)
                + (float) ($transfersIn[$a->id] ?? 0),
        ]);
    }

    /**
     * Patrimonio netto (in valuta base) al $upTo = somma dei saldi conto
     * convertiti al tasso del $upTo.
     */
    private function cumulativeBalance(Carbon $upTo): float
    {
        $base = $this->baseCurrency();

        return $this->rawAccountBalances($upTo)->sum(
            fn (array $a) => $this->converter->convert($a['balance'], $a['currency'], $base, $upTo)
        );
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

    /**
     * Converte una transazione nella valuta base al tasso della sua data.
     *
     * @param  Transaction|object  $t  oggetto con attributi amount, currency, occurred_at
     */
    private function toBase($t): float
    {
        $date = $t->occurred_at instanceof Carbon ? $t->occurred_at : Carbon::parse($t->occurred_at);

        return $this->converter->convert((float) $t->amount, $t->currency, $this->baseCurrency(), $date);
    }

    private function baseCurrency(): string
    {
        // Sotto auth:sanctum l'utente è sempre presente; currency ha default 'EUR'.
        return $this->baseCurrency ??= strtoupper(Auth::user()->currency);
    }

    private function fmt(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}

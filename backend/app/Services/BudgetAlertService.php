<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Support\FinancialMonth;

class BudgetAlertService
{
    /**
     * Soglia (in %) oltre la quale un budget è in stato "warning".
     */
    public const WARNING_THRESHOLD = 80.0;

    /**
     * Alert dei budget per il periodo indicato (esclude gli "ok"), ordinati per percent desc.
     *
     * @return array<int, array{budget_id: int, category_id: int, category_name: ?string, category_color: ?string, year: int, month: int, amount: string, spent: string, percent: float, status: string}>
     */
    public function alerts(int $year, int $month, ?float $warningThreshold = null): array
    {
        $warningThreshold ??= self::WARNING_THRESHOLD;

        $budgets = Budget::query()
            ->with('category')
            ->where('year', $year)
            ->where('month', $month)
            ->get();

        if ($budgets->isEmpty()) {
            return [];
        }

        $spentByCategory = $this->spentByCategory($budgets->pluck('category_id')->all(), $year, $month);

        $alerts = [];
        foreach ($budgets as $budget) {
            $amount = (float) $budget->amount;
            $spent = (float) ($spentByCategory[$budget->category_id] ?? 0);

            if ($amount > 0) {
                $percent = round($spent / $amount * 100, 1);
            } else {
                $percent = $spent > 0 ? 100.0 : 0.0;
            }

            $status = match (true) {
                $percent >= 100 => 'exceeded',
                $percent >= $warningThreshold => 'warning',
                default => 'ok',
            };

            if ($status === 'ok') {
                continue;
            }

            $alerts[] = [
                'budget_id' => $budget->id,
                'category_id' => $budget->category_id,
                'category_name' => $budget->category?->name,
                'category_color' => $budget->category?->color,
                'year' => $year,
                'month' => $month,
                'amount' => number_format($amount, 2, '.', ''),
                'spent' => number_format($spent, 2, '.', ''),
                'percent' => $percent,
                'status' => $status,
            ];
        }

        usort($alerts, fn (array $a, array $b): int => $b['percent'] <=> $a['percent']);

        return $alerts;
    }

    /**
     * Speso del mese per categoria, includendo le spese delle sotto-categorie dirette.
     *
     * @param  array<int, int>  $categoryIds
     * @return array<int, float>
     */
    public function spentByCategory(array $categoryIds, int $year, int $month): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $parentOf = Category::query()
            ->whereIn('parent_id', $categoryIds)
            ->pluck('parent_id', 'id')
            ->all();

        [$start, $end] = FinancialMonth::forYearMonth($year, $month);

        $totals = Transaction::query()
            ->where('type', 'expense')
            ->whereIn('category_id', [...$categoryIds, ...array_keys($parentOf)])
            ->whereBetween('occurred_at', [$start->toDateString(), $end->toDateString()])
            ->groupBy('category_id')
            ->selectRaw('category_id, SUM(amount) as total')
            ->pluck('total', 'category_id');

        $spent = array_fill_keys($categoryIds, 0.0);
        foreach ($totals as $categoryId => $total) {
            if (isset($spent[$categoryId])) {
                $spent[$categoryId] += (float) $total;
            }
            if (isset($parentOf[$categoryId])) {
                $spent[$parentOf[$categoryId]] += (float) $total;
            }
        }

        return $spent;
    }
}

<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\Transaction;
use Illuminate\Support\Carbon;

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
    public function alerts(int $year, int $month): array
    {
        $budgets = Budget::query()
            ->with('category')
            ->where('year', $year)
            ->where('month', $month)
            ->get();

        if ($budgets->isEmpty()) {
            return [];
        }

        $start = Carbon::createFromDate($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth();

        $spentByCategory = Transaction::query()
            ->where('type', 'expense')
            ->whereIn('category_id', $budgets->pluck('category_id')->all())
            ->whereBetween('occurred_at', [$start->toDateString(), $end->toDateString()])
            ->groupBy('category_id')
            ->selectRaw('category_id, SUM(amount) as total')
            ->pluck('total', 'category_id');

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
                $percent >= self::WARNING_THRESHOLD => 'warning',
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
}

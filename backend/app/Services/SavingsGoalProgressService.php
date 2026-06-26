<?php

namespace App\Services;

use App\Models\SavingsGoal;
use App\Models\Transaction;
use Illuminate\Support\Carbon;

class SavingsGoalProgressService
{
    /**
     * Calcola e attacca a ogni goal gli attributi derivati, calcolati LIVE
     * dalle transazioni del conto collegato sul periodo di riferimento:
     * - saved          flusso netto sul conto nel periodo (entrate+transfer in − uscite−transfer out)
     * - progress       percentuale 0..100 (cap) verso target_amount
     * - remaining      quanto manca al target (>= 0)
     * - period_start / period_end   estremi del periodo considerato (null = aperto)
     * - pace           null oppure dati di ritmo se il periodo ha una fine
     *
     * Periodo:
     * - recurrence 'none'  → [start_date, target_date] (estremi opzionali)
     * - weekly/monthly/yearly → periodo corrente che contiene $now
     *
     * @param  array<int, SavingsGoal>  $goals
     */
    public function attachProgress(array $goals, ?Carbon $now = null): void
    {
        if ($goals === []) {
            return;
        }

        $now = $now ?? Carbon::now();

        foreach ($goals as $goal) {
            [$start, $end] = $this->period($goal, $now);

            $target = (float) $goal->target_amount;
            $saved = $goal->account_id ? $this->savedForGoal((int) $goal->account_id, $start, $end) : 0.0;
            $remaining = max(0.0, $target - $saved);

            $progress = $target > 0 ? min(100.0, round($saved / $target * 100, 1)) : ($saved > 0 ? 100.0 : 0.0);

            $goal->setAttribute('saved', number_format($saved, 2, '.', ''));
            $goal->setAttribute('progress', $progress);
            $goal->setAttribute('remaining', number_format($remaining, 2, '.', ''));
            $goal->setAttribute('period_start', $start?->toDateString());
            $goal->setAttribute('period_end', $end?->toDateString());
            $goal->setAttribute('pace', $this->pace($goal, $saved, $target, $remaining, $start, $end, $now));
        }
    }

    /**
     * Estremi del periodo di riferimento del goal.
     *
     * @return array{0: Carbon|null, 1: Carbon|null} [start, end]
     */
    private function period(SavingsGoal $goal, Carbon $now): array
    {
        return match ($goal->recurrence) {
            'weekly' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'monthly' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'yearly' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
            default => [$goal->start_date?->copy(), $goal->target_date?->copy()],
        };
    }

    /**
     * Flusso netto sul conto nel periodo, nella valuta del conto.
     * I transfer in ingresso usano `transfer_amount` (fallback `amount`),
     * coerente con il calcolo dei saldi in ReportService.
     */
    private function savedForGoal(int $accountId, ?Carbon $start, ?Carbon $end): float
    {
        $query = Transaction::query()
            ->where(function ($w) use ($accountId) {
                $w->where('account_id', $accountId)->orWhere('transfer_account_id', $accountId);
            });

        if ($start !== null) {
            $query->whereDate('occurred_at', '>=', $start->toDateString());
        }
        if ($end !== null) {
            $query->whereDate('occurred_at', '<=', $end->toDateString());
        }

        // $accountId è un intero validato: cast esplicito, niente binding.
        $saved = $query->selectRaw(
            "COALESCE(SUM(CASE
                WHEN type = 'income' AND account_id = {$accountId} THEN amount
                WHEN type = 'expense' AND account_id = {$accountId} THEN -amount
                WHEN type = 'transfer' AND transfer_account_id = {$accountId} THEN COALESCE(transfer_amount, amount)
                WHEN type = 'transfer' AND account_id = {$accountId} THEN -amount
                ELSE 0 END), 0) as saved"
        )->value('saved');

        return (float) $saved;
    }

    /**
     * Dati di ritmo per arrivare al target entro la fine del periodo.
     *
     * @return array{target_date: string, days_left: int, months_left: int, required_per_month: string, status: string}|null
     */
    private function pace(SavingsGoal $goal, float $saved, float $target, float $remaining, ?Carbon $start, ?Carbon $end, Carbon $now): ?array
    {
        if ($end === null) {
            return null;
        }

        $deadline = $end->copy()->endOfDay();
        $reached = $target > 0 && $saved >= $target;

        $daysLeft = (int) max(0, $now->copy()->startOfDay()->diffInDays($deadline, false));
        $monthsLeft = (int) max(0, ceil($daysLeft / 30));
        $requiredPerMonth = $monthsLeft > 0 ? $remaining / $monthsLeft : $remaining;

        $status = $this->paceStatus($goal, $saved, $target, $reached, $start, $deadline, $now);

        return [
            'target_date' => $end->toDateString(),
            'days_left' => $daysLeft,
            'months_left' => $monthsLeft,
            'required_per_month' => number_format($requiredPerMonth, 2, '.', ''),
            'status' => $status,
        ];
    }

    /**
     * on_track / behind / overdue / completed in base al tempo trascorso vs progresso.
     */
    private function paceStatus(SavingsGoal $goal, float $saved, float $target, bool $reached, ?Carbon $start, Carbon $deadline, Carbon $now): string
    {
        if ($reached) {
            return 'completed';
        }

        if ($now->greaterThan($deadline)) {
            return 'overdue';
        }

        $start = ($start ?? $goal->created_at ?? $now)->copy();
        $totalDays = $start->diffInDays($deadline, false);

        if ($totalDays <= 0 || $target <= 0) {
            return 'on_track';
        }

        $elapsedDays = max(0.0, $start->diffInDays($now, false));
        $expectedFraction = min(1.0, $elapsedDays / $totalDays);
        $actualFraction = $saved / $target;

        return $actualFraction + 1e-9 >= $expectedFraction ? 'on_track' : 'behind';
    }
}

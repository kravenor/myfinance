<?php

namespace App\Services;

use App\Models\SavingsGoal;
use App\Models\SavingsGoalMovement;
use Illuminate\Support\Carbon;

class SavingsGoalProgressService
{
    /**
     * Calcola e attacca a ogni goal gli attributi derivati:
     * - saved          somma firmata dei movimenti (in − out)
     * - progress        percentuale 0..100 (cap) verso target_amount
     * - remaining       quanto manca al target (>= 0)
     * - pace            null oppure dati di ritmo se target_date presente
     *
     * @param  array<int, SavingsGoal>  $goals
     */
    public function attachProgress(array $goals, ?Carbon $now = null): void
    {
        if ($goals === []) {
            return;
        }

        $now = $now ?? Carbon::now();

        $ids = array_map(fn (SavingsGoal $g): int => $g->id, $goals);

        $savedByGoal = SavingsGoalMovement::query()
            ->whereIn('savings_goal_id', $ids)
            ->groupBy('savings_goal_id')
            ->selectRaw("savings_goal_id, SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END) as saved")
            ->pluck('saved', 'savings_goal_id');

        foreach ($goals as $goal) {
            $target = (float) $goal->target_amount;
            $saved = (float) ($savedByGoal[$goal->id] ?? 0);
            $remaining = max(0.0, $target - $saved);

            $progress = $target > 0 ? min(100.0, round($saved / $target * 100, 1)) : ($saved > 0 ? 100.0 : 0.0);

            $goal->setAttribute('saved', number_format($saved, 2, '.', ''));
            $goal->setAttribute('progress', $progress);
            $goal->setAttribute('remaining', number_format($remaining, 2, '.', ''));
            $goal->setAttribute('pace', $this->pace($goal, $saved, $target, $remaining, $now));
        }
    }

    /**
     * Dati di ritmo per arrivare al target entro target_date.
     *
     * @return array{target_date: string, days_left: int, months_left: int, required_per_month: string, status: string}|null
     */
    private function pace(SavingsGoal $goal, float $saved, float $target, float $remaining, Carbon $now): ?array
    {
        if ($goal->target_date === null) {
            return null;
        }

        $targetDate = $goal->target_date->copy()->endOfDay();
        $reached = $target > 0 && $saved >= $target;

        $daysLeft = (int) max(0, $now->copy()->startOfDay()->diffInDays($targetDate, false));
        $monthsLeft = (int) max(0, ceil($daysLeft / 30));
        $requiredPerMonth = $monthsLeft > 0 ? $remaining / $monthsLeft : $remaining;

        $status = $this->paceStatus($goal, $saved, $target, $reached, $targetDate, $now);

        return [
            'target_date' => $goal->target_date->toDateString(),
            'days_left' => $daysLeft,
            'months_left' => $monthsLeft,
            'required_per_month' => number_format($requiredPerMonth, 2, '.', ''),
            'status' => $status,
        ];
    }

    /**
     * on_track / behind / overdue / completed in base al tempo trascorso vs progresso.
     */
    private function paceStatus(SavingsGoal $goal, float $saved, float $target, bool $reached, Carbon $targetDate, Carbon $now): string
    {
        if ($reached) {
            return 'completed';
        }

        if ($now->greaterThan($targetDate)) {
            return 'overdue';
        }

        $start = ($goal->created_at ?? $now)->copy();
        $totalDays = $start->diffInDays($targetDate, false);

        if ($totalDays <= 0 || $target <= 0) {
            return 'on_track';
        }

        $elapsedDays = max(0.0, $start->diffInDays($now, false));
        $expectedFraction = min(1.0, $elapsedDays / $totalDays);
        $actualFraction = $saved / $target;

        return $actualFraction + 1e-9 >= $expectedFraction ? 'on_track' : 'behind';
    }
}

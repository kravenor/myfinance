<?php

namespace App\Services;

use App\Models\RecurringTransaction;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RecurringTransactionRunner
{
    /**
     * Materializza tutte le ricorrenti maturate fino a $until (default: oggi).
     *
     * @return int Numero di transazioni create.
     */
    public function run(?Carbon $until = null): int
    {
        $until = ($until ?? Carbon::now())->startOfDay();

        $recurrings = RecurringTransaction::query()
            ->withoutGlobalScopes()
            ->where('is_active', true)
            ->whereDate('next_run_at', '<=', $until->toDateString())
            ->get();

        $created = 0;

        foreach ($recurrings as $recurring) {
            $created += $this->process($recurring, $until);
        }

        return $created;
    }

    private function process(RecurringTransaction $recurring, Carbon $until): int
    {
        $count = 0;

        DB::transaction(function () use ($recurring, $until, &$count) {
            while ($recurring->is_active && $recurring->next_run_at->lte($until)) {
                $occurredAt = $recurring->next_run_at->copy();

                Transaction::withoutGlobalScopes()->create([
                    'user_id' => $recurring->user_id,
                    'account_id' => $recurring->account_id,
                    'category_id' => $recurring->category_id,
                    'transfer_account_id' => $recurring->transfer_account_id,
                    'recurring_transaction_id' => $recurring->id,
                    'type' => $recurring->type,
                    'amount' => $recurring->amount,
                    'currency' => $recurring->currency,
                    'occurred_at' => $occurredAt->toDateString(),
                    'description' => $recurring->description,
                ]);

                $recurring->last_run_at = $occurredAt;
                $recurring->next_run_at = $this->advance($occurredAt, $recurring->cadence, max(1, (int) $recurring->interval));

                if ($recurring->ends_on && $recurring->next_run_at->gt($recurring->ends_on)) {
                    $recurring->is_active = false;
                }

                $count++;
            }

            $recurring->save();
        });

        return $count;
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
}

<?php

namespace App\Services;

use App\Models\Account;
use App\Models\InvestmentHolding;
use App\Models\InvestmentTransaction;
use App\Models\RecurringTransaction;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RecurringTransactionRunner
{
    public function __construct(
        private readonly CurrencyConverter $converter,
        private readonly InvestmentPriceResolver $prices,
        private readonly HoldingPositionRecalculator $recalculator,
    ) {}

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

        // Holding alimentato dalla ricorrente (rata PAC), risolto una volta sola:
        // il ricalcolo della posizione va fatto a fine backlog, non per rata.
        $holding = $recurring->investment_holding_id
            ? InvestmentHolding::withoutGlobalScopes()->find($recurring->investment_holding_id)
            : null;

        DB::transaction(function () use ($recurring, $holding, $until, &$count) {
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
                    'transfer_amount' => $this->transferAmount($recurring, $occurredAt),
                    'currency' => $recurring->currency,
                    'occurred_at' => $occurredAt->toDateString(),
                    'description' => $recurring->description,
                ]);

                if ($holding) {
                    $this->recordInvestmentBuy($recurring, $holding, $occurredAt);
                }

                $recurring->last_run_at = $occurredAt;
                $recurring->next_run_at = $this->advance($occurredAt, $recurring->cadence, max(1, (int) $recurring->interval));

                if ($recurring->ends_on && $recurring->next_run_at->gt($recurring->ends_on)) {
                    $recurring->is_active = false;
                }

                $count++;
            }

            $recurring->save();

            if ($holding && $count > 0) {
                $this->recalculator->recalculate($holding);
            }
        });

        return $count;
    }

    /**
     * Rata PAC: registra l'acquisto sull'holding collegato ricavando le quote
     * dall'importo versato e dalla quotazione del giorno, come nel form dei
     * movimenti.
     */
    private function recordInvestmentBuy(RecurringTransaction $recurring, InvestmentHolding $holding, Carbon $occurredAt): void
    {
        $price = $this->prices->priceFor($holding, $occurredAt) ?? $holding->effectivePrice();
        if ($price <= 0) {
            // ponytail: senza prezzo le quote non sono calcolabili: resta solo il
            // movimento di cassa, l'utente registra l'acquisto a mano.
            return;
        }

        $amount = (float) $recurring->amount;
        if ($recurring->currency !== $holding->currency) {
            $amount = $this->converter->convert($amount, $recurring->currency, $holding->currency, $occurredAt);
        }

        InvestmentTransaction::withoutGlobalScopes()->create([
            'user_id' => $recurring->user_id,
            'investment_holding_id' => $holding->id,
            'side' => 'buy',
            'occurred_at' => $occurredAt->toDateString(),
            'quantity' => number_format($amount / $price, 8, '.', ''),
            'price' => number_format($price, 8, '.', ''),
            'fees' => 0,
            'notes' => $recurring->description,
        ]);
    }

    /**
     * Importo accreditato sul conto destinazione per i transfer (convertito
     * al tasso della data se le valute differiscono).
     */
    private function transferAmount(RecurringTransaction $recurring, Carbon $occurredAt): ?string
    {
        if ($recurring->type !== 'transfer' || ! $recurring->transfer_account_id) {
            return null;
        }

        // transfer_account_id ha FK valida (nullOnDelete), quindi il conto esiste.
        $dest = Account::withoutGlobalScopes()->findOrFail($recurring->transfer_account_id);
        $destCurrency = $dest->currency;

        if ($destCurrency === $recurring->currency) {
            return $recurring->amount;
        }

        $converted = $this->converter->convert(
            (float) $recurring->amount,
            $recurring->currency,
            $destCurrency,
            $occurredAt,
        );

        return number_format($converted, 2, '.', '');
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

<?php

namespace Tests\Feature\Services;

use App\Models\Account;
use App\Models\InstrumentPrice;
use App\Models\InvestmentHolding;
use App\Models\RecurringTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\RecurringTransactionRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RecurringTransactionRunnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_transaction_when_due(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $recurring = RecurringTransaction::factory()->for($user)->for($account, 'account')->create([
            'cadence' => 'monthly',
            'interval' => 1,
            'starts_on' => '2026-01-01',
            'next_run_at' => '2026-01-01',
            'amount' => 100,
        ]);

        $count = app(RecurringTransactionRunner::class)->run(Carbon::parse('2026-01-15'));

        $this->assertSame(1, $count);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'account_id' => $account->id,
            'recurring_transaction_id' => $recurring->id,
            // 1/1 è festivo: il movimento slitta al primo giorno lavorativo.
            'occurred_at' => '2026-01-02',
        ]);

        $this->assertSame('2026-02-01', $recurring->fresh()->next_run_at->toDateString());
        $this->assertSame('2026-01-02', $recurring->fresh()->last_run_at->toDateString());
        $this->assertTrue($recurring->fresh()->is_active);
    }

    public function test_creates_multiple_transactions_for_backlog(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        RecurringTransaction::factory()->for($user)->for($account, 'account')->create([
            'cadence' => 'monthly',
            'interval' => 1,
            'starts_on' => '2026-01-01',
            'next_run_at' => '2026-01-01',
        ]);

        $count = app(RecurringTransactionRunner::class)->run(Carbon::parse('2026-04-10'));

        $this->assertSame(4, $count);
        $this->assertSame(4, Transaction::withoutGlobalScopes()->count());
    }

    public function test_deactivates_when_ends_on_passed(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $recurring = RecurringTransaction::factory()->for($user)->for($account, 'account')->create([
            'cadence' => 'monthly',
            'interval' => 1,
            'starts_on' => '2026-01-01',
            'next_run_at' => '2026-01-01',
            'ends_on' => '2026-02-15',
        ]);

        app(RecurringTransactionRunner::class)->run(Carbon::parse('2026-06-01'));

        $recurring->refresh();

        $this->assertFalse($recurring->is_active);
        $this->assertSame(2, Transaction::withoutGlobalScopes()->count());
    }

    public function test_skips_inactive_recurring(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        RecurringTransaction::factory()->for($user)->for($account, 'account')->create([
            'cadence' => 'monthly',
            'next_run_at' => '2026-01-01',
            'is_active' => false,
        ]);

        $count = app(RecurringTransactionRunner::class)->run(Carbon::parse('2026-12-31'));

        $this->assertSame(0, $count);
    }

    public function test_linked_holding_gets_buy_with_quantity_from_amount(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $holding = InvestmentHolding::factory()->for($user)->create([
            'account_id' => Account::factory()->for($user)->create(['type' => 'investment', 'currency' => 'EUR'])->id,
            'currency' => 'EUR',
            'symbol' => 'VWCE.XETRA',
            'asset_type' => 'etf',
            'quantity' => 0,
            'avg_cost' => 0,
            'last_price' => null,
        ]);
        InstrumentPrice::query()->create([
            'provider' => 'yahoo', 'symbol' => 'VWCE.XETRA', 'currency' => 'EUR', 'price' => 50, 'as_of' => '2026-01-01',
        ]);

        RecurringTransaction::factory()->for($user)->for($account, 'account')->create([
            'cadence' => 'monthly',
            'interval' => 1,
            'starts_on' => '2026-01-01',
            'next_run_at' => '2026-01-01',
            'amount' => 100,
            'currency' => 'EUR',
            'investment_holding_id' => $holding->id,
        ]);

        app(RecurringTransactionRunner::class)->run(Carbon::parse('2026-02-15'));

        // Due rate da 100 € a 50 € di quotazione: 2 quote ciascuna.
        $this->assertSame(2, $holding->transactions()->count());
        $first = $holding->transactions()->orderBy('occurred_at')->first();
        $this->assertSame('buy', $first->side);
        $this->assertSame('2026-01-02', $first->occurred_at->toDateString());
        $this->assertSame('2.00000000', $first->quantity);
        $this->assertSame('50.00000000', $first->price);
        $this->assertSame('4.00000000', $holding->fresh()->quantity);
        $this->assertSame('200.00', $holding->fresh()->net_invested);
    }

    public function test_linked_holding_buy_nets_out_the_recurring_fees(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $holding = InvestmentHolding::factory()->for($user)->create([
            'account_id' => Account::factory()->for($user)->create(['type' => 'investment', 'currency' => 'EUR'])->id,
            'currency' => 'EUR',
            'symbol' => 'VWCE.XETRA',
            'asset_type' => 'etf',
            'quantity' => 0,
            'avg_cost' => 0,
            'last_price' => null,
        ]);
        InstrumentPrice::query()->create([
            'provider' => 'yahoo', 'symbol' => 'VWCE.XETRA', 'currency' => 'EUR', 'price' => 50, 'as_of' => '2026-01-01',
        ]);

        RecurringTransaction::factory()->for($user)->for($account, 'account')->create([
            'cadence' => 'monthly',
            'interval' => 1,
            'starts_on' => '2026-01-01',
            'next_run_at' => '2026-01-01',
            'amount' => 100,
            'currency' => 'EUR',
            'investment_holding_id' => $holding->id,
            'investment_fees' => 2,
        ]);

        app(RecurringTransactionRunner::class)->run(Carbon::parse('2026-01-15'));

        // 100 € di rata meno 2 € di costi: 98 € a 50 € comprano 1,96 quote.
        $movement = $holding->transactions()->sole();
        $this->assertSame('1.96000000', $movement->quantity);
        $this->assertSame('2.00', $movement->fees);
        // Il versato resta la rata intera: i costi sono cassa uscita.
        $this->assertSame('100.00', $holding->fresh()->net_invested);
    }

    public function test_non_working_day_shifts_only_the_transaction_date(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        // 14/02/2026 è sabato: il movimento slitta a lunedì 16, la cadenza resta ancorata al 14.
        $recurring = RecurringTransaction::factory()->for($user)->for($account, 'account')->create([
            'cadence' => 'monthly',
            'interval' => 1,
            'starts_on' => '2026-02-14',
            'next_run_at' => '2026-02-14',
        ]);

        $runner = app(RecurringTransactionRunner::class);

        // Sabato e domenica non registra nulla: la data effettiva non è ancora arrivata.
        $this->assertSame(0, $runner->run(Carbon::parse('2026-02-15')));

        $this->assertSame(1, $runner->run(Carbon::parse('2026-02-16')));
        $recurring->refresh();
        $this->assertSame('2026-02-16', $recurring->last_run_at->toDateString());
        $this->assertSame('2026-03-14', $recurring->next_run_at->toDateString());

        // 14/03 sabato → 16/03; 14/04 martedì, nessuno slittamento.
        $runner->run(Carbon::parse('2026-04-30'));
        $this->assertSame(
            ['2026-02-16', '2026-03-16', '2026-04-14'],
            Transaction::withoutGlobalScopes()->orderBy('occurred_at')->pluck('occurred_at')->map(fn ($d) => Carbon::parse($d)->toDateString())->all(),
        );
    }

    public function test_month_end_recurring_keeps_its_day_after_february(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        // Mensile del 31: 28/02 è sabato → 2/3, poi torna al 31 (31/03 martedì, 30/04 giovedì).
        $recurring = RecurringTransaction::factory()->for($user)->for($account, 'account')->create([
            'cadence' => 'monthly',
            'interval' => 1,
            'starts_on' => '2026-01-31',
            'next_run_at' => '2026-02-28',
        ]);

        app(RecurringTransactionRunner::class)->run(Carbon::parse('2026-04-30'));

        $this->assertSame(
            ['2026-03-02', '2026-03-31', '2026-04-30'],
            Transaction::withoutGlobalScopes()->orderBy('occurred_at')->pluck('occurred_at')->map(fn ($d) => Carbon::parse($d)->toDateString())->all(),
        );
        $this->assertSame('2026-05-31', $recurring->fresh()->next_run_at->toDateString());
    }
}

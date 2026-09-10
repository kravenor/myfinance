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
            'occurred_at' => '2026-01-01',
        ]);

        $this->assertSame('2026-02-01', $recurring->fresh()->next_run_at->toDateString());
        $this->assertSame('2026-01-01', $recurring->fresh()->last_run_at->toDateString());
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
            'quantity' => 0,
            'avg_cost' => 0,
            'last_price' => null,
        ]);
        InstrumentPrice::query()->create([
            'symbol' => 'VWCE.XETRA', 'currency' => 'EUR', 'price' => 50, 'as_of' => '2026-01-01',
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
        $this->assertSame('2026-01-01', $first->occurred_at->toDateString());
        $this->assertSame('2.00000000', $first->quantity);
        $this->assertSame('50.00000000', $first->price);
        $this->assertSame('4.00000000', $holding->fresh()->quantity);
        $this->assertSame('200.00', $holding->fresh()->net_invested);
    }
}

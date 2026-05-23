<?php

namespace Tests\Feature\Services;

use App\Models\Account;
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
}

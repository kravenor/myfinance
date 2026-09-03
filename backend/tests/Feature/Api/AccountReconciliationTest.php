<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AccountReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_returns_the_computed_balance_at_the_date(): void
    {
        Carbon::setTestNow('2026-09-03');

        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create([
            'currency' => 'EUR',
            'initial_balance' => 1000,
        ]);
        Transaction::factory()->for($user)->create([
            'account_id' => $account->id,
            'type' => 'expense',
            'amount' => 250,
            'currency' => 'EUR',
            'occurred_at' => '2026-09-01',
        ]);
        // Fuori data: non deve entrare nel saldo al 02/09.
        Transaction::factory()->for($user)->create([
            'account_id' => $account->id,
            'type' => 'expense',
            'amount' => 90,
            'currency' => 'EUR',
            'occurred_at' => '2026-09-03',
        ]);

        $this->actingAs($user)
            ->getJson("/api/accounts/{$account->id}/reconciliation?date=2026-09-02")
            ->assertOk()
            ->assertJsonPath('data.computed_balance', '750.00')
            ->assertJsonPath('data.date', '2026-09-02')
            ->assertJsonPath('data.currency', 'EUR');
    }

    public function test_creates_an_income_adjustment_when_the_real_balance_is_higher(): void
    {
        Carbon::setTestNow('2026-09-03');

        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create(['currency' => 'EUR', 'initial_balance' => 500]);
        $category = Category::factory()->for($user)->create(['type' => 'income']);

        $resp = $this->actingAs($user)
            ->postJson("/api/accounts/{$account->id}/reconciliation", [
                'balance' => 530.25,
                'category_id' => $category->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.adjusted', true)
            ->assertJsonPath('data.computed_balance', '500.00')
            ->assertJsonPath('data.difference', '30.25')
            ->assertJsonPath('data.transaction.type', 'income')
            ->assertJsonPath('data.transaction.amount', '30.25')
            ->assertJsonPath('data.transaction.description', 'Rettifica saldo')
            ->assertJsonPath('data.transaction.category_id', $category->id)
            ->assertJsonPath('data.transaction.is_adjustment', true)
            ->json('data');

        $this->assertDatabaseCount('transactions', 1);

        // Dopo la rettifica il conto torna il saldo reale: una seconda
        // riconciliazione con lo stesso valore non deve creare nulla.
        $this->actingAs($user)
            ->postJson("/api/accounts/{$account->id}/reconciliation", ['balance' => 530.25])
            ->assertOk()
            ->assertJsonPath('data.adjusted', false)
            ->assertJsonPath('data.difference', '0.00')
            ->assertJsonPath('data.transaction', null);

        $this->assertDatabaseCount('transactions', 1);
        $this->assertSame('2026-09-03', $resp['date']);
    }

    public function test_creates_an_expense_adjustment_when_the_real_balance_is_lower(): void
    {
        Carbon::setTestNow('2026-09-03');

        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create(['currency' => 'EUR', 'initial_balance' => 500]);

        $this->actingAs($user)
            ->postJson("/api/accounts/{$account->id}/reconciliation", [
                'balance' => 480,
                'date' => '2026-08-31',
                'description' => 'Commissioni non registrate',
            ])
            ->assertOk()
            ->assertJsonPath('data.adjusted', true)
            ->assertJsonPath('data.difference', '-20.00')
            ->assertJsonPath('data.transaction.type', 'expense')
            ->assertJsonPath('data.transaction.amount', '20.00')
            ->assertJsonPath('data.transaction.occurred_at', '2026-08-31')
            ->assertJsonPath('data.transaction.description', 'Commissioni non registrate');
    }

    public function test_accepts_a_negative_real_balance(): void
    {
        Carbon::setTestNow('2026-09-03');

        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create([
            'type' => 'card',
            'currency' => 'EUR',
            'initial_balance' => 0,
        ]);

        $this->actingAs($user)
            ->postJson("/api/accounts/{$account->id}/reconciliation", ['balance' => -150.5])
            ->assertOk()
            ->assertJsonPath('data.difference', '-150.50')
            ->assertJsonPath('data.transaction.type', 'expense')
            ->assertJsonPath('data.transaction.amount', '150.50');
    }

    public function test_rejects_investment_accounts(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create(['type' => 'investment']);

        $this->actingAs($user)
            ->postJson("/api/accounts/{$account->id}/reconciliation", ['balance' => 100])
            ->assertStatus(422)
            ->assertJsonValidationErrors('balance');

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_cannot_reconcile_another_users_account(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $account = Account::factory()->for($other)->create();

        $this->actingAs($user)
            ->postJson("/api/accounts/{$account->id}/reconciliation", ['balance' => 100])
            ->assertNotFound();

        $this->actingAs($user)
            ->getJson("/api/accounts/{$account->id}/reconciliation")
            ->assertNotFound();

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_adjustment_moves_the_balance_but_stays_out_of_the_statistics(): void
    {
        Carbon::setTestNow('2026-09-15');

        $user = User::factory()->create(['currency' => 'EUR']);
        $account = Account::factory()->for($user)->create(['currency' => 'EUR', 'initial_balance' => 0]);
        $category = Category::factory()->for($user)->create(['type' => 'expense']);

        Transaction::factory()->for($user)->create([
            'account_id' => $account->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 100,
            'currency' => 'EUR',
            'occurred_at' => '2026-09-05',
        ]);

        $this->actingAs($user)
            ->postJson("/api/accounts/{$account->id}/reconciliation", [
                'balance' => -180,
                'date' => '2026-09-10',
                'category_id' => $category->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.difference', '-80.00');

        $summary = $this->actingAs($user)
            ->getJson('/api/reports/summary?from=2026-09-01&to=2026-09-30')
            ->assertOk()
            ->json('data');

        // Statistiche: solo la spesa vera da 100.
        $this->assertSame('100.00', $summary['expense']);
        $this->assertSame('0.00', $summary['income']);

        // Saldo e patrimonio: la rettifica c'è, altrimenti non servirebbe a niente.
        $this->assertSame('-180.00', $summary['accounts'][0]['balance']);
        $this->assertSame('-180.00', $summary['net_worth']);

        // Per categoria e timeline restano puliti.
        $byCategory = $this->actingAs($user)
            ->getJson('/api/reports/by-category?from=2026-09-01&to=2026-09-30&type=expense')
            ->assertOk()
            ->json('data');
        $this->assertSame('100.00', collect($byCategory)->firstWhere('category_id', $category->id)['total']);

        $timeline = $this->actingAs($user)
            ->getJson('/api/reports/timeline?from=2026-09-01&to=2026-09-30')
            ->assertOk()
            ->json('data');
        $this->assertSame('100.00', $timeline[0]['expense']);
    }

    public function test_adjustment_does_not_consume_the_budget(): void
    {
        Carbon::setTestNow('2026-09-15');

        $user = User::factory()->create(['currency' => 'EUR']);
        $account = Account::factory()->for($user)->create(['currency' => 'EUR', 'initial_balance' => 0]);
        $category = Category::factory()->for($user)->create(['type' => 'expense']);
        Budget::factory()->for($user)->create([
            'category_id' => $category->id,
            'year' => 2026,
            'month' => 9,
            'amount' => 300,
        ]);

        $this->actingAs($user)
            ->postJson("/api/accounts/{$account->id}/reconciliation", [
                'balance' => -250,
                'date' => '2026-09-10',
                'category_id' => $category->id,
            ])
            ->assertOk();

        $budgets = $this->actingAs($user)
            ->getJson('/api/budgets?year=2026&month=9')
            ->assertOk()
            ->json('data');

        $this->assertSame('0.00', (string) $budgets[0]['spent']);

        $this->actingAs($user)
            ->getJson('/api/budgets/alerts?year=2026&month=9')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_requires_auth(): void
    {
        $account = Account::factory()->create();

        $this->postJson("/api/accounts/{$account->id}/reconciliation", ['balance' => 1])
            ->assertUnauthorized();
    }
}

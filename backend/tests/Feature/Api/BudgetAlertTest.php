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

class BudgetAlertTest extends TestCase
{
    use RefreshDatabase;

    public function test_alerts_require_auth(): void
    {
        $this->getJson('/api/budgets/alerts')->assertUnauthorized();
    }

    public function test_warning_and_exceeded_are_returned_ok_is_excluded(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();

        $warning = $this->budgetWithSpend($user, $account, 100, 85);   // 85% -> warning
        $exceeded = $this->budgetWithSpend($user, $account, 100, 130);  // 130% -> exceeded
        $this->budgetWithSpend($user, $account, 100, 40);               // 40% -> ok (escluso)

        $response = $this->actingAs($user)
            ->getJson('/api/budgets/alerts?year=2026&month=5')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        // ordinati per percent desc: prima l'exceeded
        $response->assertJsonPath('data.0.status', 'exceeded')
            ->assertJsonPath('data.0.percent', 130)
            ->assertJsonPath('data.0.budget_id', $exceeded->id)
            ->assertJsonPath('data.1.status', 'warning')
            ->assertJsonPath('data.1.percent', 85)
            ->assertJsonPath('data.1.budget_id', $warning->id);
    }

    public function test_defaults_to_current_month(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $now = Carbon::now();

        $category = Category::factory()->for($user)->create(['type' => 'expense']);
        Budget::factory()->for($user)->for($category)->create([
            'year' => $now->year, 'month' => $now->month, 'amount' => 100,
        ]);
        Transaction::factory()->for($user)->for($account)->create([
            'category_id' => $category->id, 'type' => 'expense', 'amount' => 120,
            'occurred_at' => $now->copy()->startOfMonth()->addDay()->toDateString(),
        ]);

        $this->actingAs($user)
            ->getJson('/api/budgets/alerts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'exceeded');
    }

    public function test_zero_amount_with_spend_is_exceeded(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $category = Category::factory()->for($user)->create(['type' => 'expense']);
        Budget::factory()->for($user)->for($category)->create([
            'year' => 2026, 'month' => 5, 'amount' => 0,
        ]);
        Transaction::factory()->for($user)->for($account)->create([
            'category_id' => $category->id, 'type' => 'expense', 'amount' => 10,
            'occurred_at' => '2026-05-10',
        ]);

        $this->actingAs($user)
            ->getJson('/api/budgets/alerts?year=2026&month=5')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'exceeded');
    }

    public function test_scoped_to_authenticated_user(): void
    {
        $other = User::factory()->create();
        $otherAccount = Account::factory()->for($other)->create();
        $this->budgetWithSpend($other, $otherAccount, 100, 130);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/budgets/alerts?year=2026&month=5')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    private function budgetWithSpend(User $user, Account $account, float $amount, float $spend): Budget
    {
        $category = Category::factory()->for($user)->create(['type' => 'expense']);
        $budget = Budget::factory()->for($user)->for($category)->create([
            'year' => 2026, 'month' => 5, 'amount' => $amount,
        ]);
        Transaction::factory()->for($user)->for($account)->create([
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => $spend,
            'occurred_at' => '2026-05-15',
        ]);

        return $budget;
    }
}

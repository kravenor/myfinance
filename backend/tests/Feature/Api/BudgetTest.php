<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BudgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_requires_auth(): void
    {
        $this->getJson('/api/budgets')->assertUnauthorized();
    }

    public function test_store_creates_budget(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create(['type' => 'expense']);

        $this->actingAs($user)
            ->postJson('/api/budgets', [
                'category_id' => $category->id,
                'year' => 2026,
                'month' => 5,
                'amount' => 600,
            ])
            ->assertCreated()
            ->assertJsonPath('data.amount', '600.00')
            ->assertJsonPath('data.spent', '0.00');
    }

    public function test_store_rejects_duplicate(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create(['type' => 'expense']);
        Budget::factory()->for($user)->for($category)->create([
            'year' => 2026,
            'month' => 5,
        ]);

        $this->actingAs($user)
            ->postJson('/api/budgets', [
                'category_id' => $category->id,
                'year' => 2026,
                'month' => 5,
                'amount' => 800,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id']);
    }

    public function test_index_includes_spent_for_month(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $category = Category::factory()->for($user)->create(['type' => 'expense']);
        $budget = Budget::factory()->for($user)->for($category)->create([
            'year' => 2026,
            'month' => 5,
            'amount' => 500,
        ]);

        // dentro al mese: incluso
        Transaction::factory()->for($user)->for($account, 'account')->for($category, 'category')->create([
            'type' => 'expense',
            'amount' => 120,
            'occurred_at' => '2026-05-10',
        ]);
        // fuori al mese: escluso
        Transaction::factory()->for($user)->for($account, 'account')->for($category, 'category')->create([
            'type' => 'expense',
            'amount' => 999,
            'occurred_at' => '2026-06-01',
        ]);
        // income: escluso
        Transaction::factory()->for($user)->for($account, 'account')->for($category, 'category')->create([
            'type' => 'income',
            'amount' => 50,
            'occurred_at' => '2026-05-15',
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/budgets?year=2026&month=5')
            ->assertOk();

        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $budget->id)
            ->assertJsonPath('data.0.spent', '120.00');
    }

    public function test_update_and_destroy(): void
    {
        $user = User::factory()->create();
        $budget = Budget::factory()->for($user)->create(['amount' => 100]);

        $this->actingAs($user)
            ->patchJson("/api/budgets/{$budget->id}", ['amount' => 250])
            ->assertOk()
            ->assertJsonPath('data.amount', '250.00');

        $this->actingAs($user)
            ->deleteJson("/api/budgets/{$budget->id}")
            ->assertNoContent();
    }
}

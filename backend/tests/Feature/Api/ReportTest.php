<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_requires_auth(): void
    {
        $this->getJson('/api/reports/summary')->assertUnauthorized();
    }

    public function test_summary_returns_income_expense_net_for_range(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create(['initial_balance' => 100]);

        Transaction::factory()->for($user)->for($account, 'account')->create([
            'type' => 'income', 'amount' => 1000, 'occurred_at' => '2026-05-10',
        ]);
        Transaction::factory()->for($user)->for($account, 'account')->create([
            'type' => 'expense', 'amount' => 300, 'occurred_at' => '2026-05-15',
        ]);
        // fuori range
        Transaction::factory()->for($user)->for($account, 'account')->create([
            'type' => 'income', 'amount' => 999, 'occurred_at' => '2026-04-01',
        ]);

        $this->actingAs($user)
            ->getJson('/api/reports/summary?from=2026-05-01&to=2026-05-31')
            ->assertOk()
            ->assertJsonPath('data.income', '1000.00')
            ->assertJsonPath('data.expense', '300.00')
            ->assertJsonPath('data.net', '700.00')
            ->assertJsonCount(1, 'data.accounts');
    }

    public function test_by_category_groups_expenses(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $food = Category::factory()->for($user)->create(['name' => 'Food', 'type' => 'expense']);
        $transport = Category::factory()->for($user)->create(['name' => 'Transport', 'type' => 'expense']);

        Transaction::factory()->for($user)->for($account, 'account')->for($food, 'category')->create([
            'type' => 'expense', 'amount' => 50, 'occurred_at' => '2026-05-10',
        ]);
        Transaction::factory()->for($user)->for($account, 'account')->for($food, 'category')->create([
            'type' => 'expense', 'amount' => 30, 'occurred_at' => '2026-05-12',
        ]);
        Transaction::factory()->for($user)->for($account, 'account')->for($transport, 'category')->create([
            'type' => 'expense', 'amount' => 20, 'occurred_at' => '2026-05-15',
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/reports/by-category?from=2026-05-01&to=2026-05-31&type=expense')
            ->assertOk();

        $response->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.category_name', 'Food')
            ->assertJsonPath('data.0.total', '80.00')
            ->assertJsonPath('data.1.category_name', 'Transport')
            ->assertJsonPath('data.1.total', '20.00');
    }

    public function test_timeline_buckets_by_month(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();

        Transaction::factory()->for($user)->for($account, 'account')->create([
            'type' => 'income', 'amount' => 500, 'occurred_at' => '2026-03-10',
        ]);
        Transaction::factory()->for($user)->for($account, 'account')->create([
            'type' => 'expense', 'amount' => 200, 'occurred_at' => '2026-03-15',
        ]);
        Transaction::factory()->for($user)->for($account, 'account')->create([
            'type' => 'income', 'amount' => 1000, 'occurred_at' => '2026-05-01',
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/reports/timeline?from=2026-03-01&to=2026-05-31')
            ->assertOk();

        $response->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.period', '2026-03')
            ->assertJsonPath('data.0.income', '500.00')
            ->assertJsonPath('data.0.expense', '200.00')
            ->assertJsonPath('data.1.period', '2026-04')
            ->assertJsonPath('data.1.income', '0.00')
            ->assertJsonPath('data.2.period', '2026-05')
            ->assertJsonPath('data.2.income', '1000.00');
    }

    public function test_net_worth_cumulative(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create(['initial_balance' => 1000]);

        Transaction::factory()->for($user)->for($account, 'account')->create([
            'type' => 'income', 'amount' => 500, 'occurred_at' => '2026-03-10',
        ]);
        Transaction::factory()->for($user)->for($account, 'account')->create([
            'type' => 'expense', 'amount' => 200, 'occurred_at' => '2026-04-05',
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/reports/net-worth?from=2026-03-01&to=2026-05-31')
            ->assertOk();

        $response->assertJsonPath('data.0.period', '2026-03')
            ->assertJsonPath('data.0.net_worth', '1500.00')
            ->assertJsonPath('data.1.period', '2026-04')
            ->assertJsonPath('data.1.net_worth', '1300.00')
            ->assertJsonPath('data.2.period', '2026-05')
            ->assertJsonPath('data.2.net_worth', '1300.00');
    }

    public function test_summary_isolates_users(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $account = Account::factory()->for($other)->create();

        Transaction::factory()->for($other)->for($account, 'account')->create([
            'type' => 'income', 'amount' => 9999, 'occurred_at' => '2026-05-10',
        ]);

        $this->actingAs($user)
            ->getJson('/api/reports/summary?from=2026-05-01&to=2026-05-31')
            ->assertOk()
            ->assertJsonPath('data.income', '0.00');
    }
}

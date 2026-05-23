<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\Category;
use App\Models\RecurringTransaction;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdvancedReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_includes_saving_rate(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();

        Transaction::factory()->for($user)->for($account, 'account')->create([
            'type' => 'income', 'amount' => 1000, 'occurred_at' => '2026-05-10',
        ]);
        Transaction::factory()->for($user)->for($account, 'account')->create([
            'type' => 'expense', 'amount' => 250, 'occurred_at' => '2026-05-12',
        ]);

        $this->actingAs($user)
            ->getJson('/api/reports/summary?from=2026-05-01&to=2026-05-31')
            ->assertOk()
            ->assertJsonPath('data.saving_rate', '75.00');
    }

    public function test_period_comparison_month_over_month(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();

        Transaction::factory()->for($user)->for($account, 'account')->create([
            'type' => 'expense', 'amount' => 100, 'occurred_at' => '2026-04-10',
        ]);
        Transaction::factory()->for($user)->for($account, 'account')->create([
            'type' => 'expense', 'amount' => 200, 'occurred_at' => '2026-05-10',
        ]);
        Transaction::factory()->for($user)->for($account, 'account')->create([
            'type' => 'income', 'amount' => 1000, 'occurred_at' => '2026-05-01',
        ]);

        $this->actingAs($user)
            ->getJson('/api/reports/period-comparison?unit=month&reference=2026-05-15')
            ->assertOk()
            ->assertJsonPath('data.unit', 'month')
            ->assertJsonPath('data.current.label', '2026-05')
            ->assertJsonPath('data.current.expense', '200.00')
            ->assertJsonPath('data.previous.expense', '100.00')
            ->assertJsonPath('data.delta.expense', '100.00')
            ->assertJsonPath('data.delta.expense_pct', '100.00');
    }

    public function test_category_trend_returns_top_categories_with_monthly_series(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $food = Category::factory()->for($user)->create(['name' => 'Food', 'type' => 'expense']);
        $transport = Category::factory()->for($user)->create(['name' => 'Transport', 'type' => 'expense']);

        Transaction::factory()->for($user)->for($account, 'account')->for($food, 'category')->create([
            'type' => 'expense', 'amount' => 100, 'occurred_at' => '2026-03-10',
        ]);
        Transaction::factory()->for($user)->for($account, 'account')->for($food, 'category')->create([
            'type' => 'expense', 'amount' => 50, 'occurred_at' => '2026-05-15',
        ]);
        Transaction::factory()->for($user)->for($account, 'account')->for($transport, 'category')->create([
            'type' => 'expense', 'amount' => 30, 'occurred_at' => '2026-04-01',
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/reports/category-trend?from=2026-03-01&to=2026-05-31&type=expense&top=2')
            ->assertOk();

        $response->assertJsonCount(3, 'data.periods')
            ->assertJsonPath('data.periods.0', '2026-03')
            ->assertJsonCount(2, 'data.categories')
            ->assertJsonPath('data.categories.0.category_name', 'Food')
            ->assertJsonPath('data.categories.0.values.0', '100.00')
            ->assertJsonPath('data.categories.0.values.2', '50.00')
            ->assertJsonPath('data.categories.1.category_name', 'Transport');
    }

    public function test_top_transactions_returns_largest(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();

        Transaction::factory()->for($user)->for($account, 'account')->create([
            'type' => 'expense', 'amount' => 10, 'occurred_at' => '2026-05-10', 'description' => 'piccola',
        ]);
        Transaction::factory()->for($user)->for($account, 'account')->create([
            'type' => 'expense', 'amount' => 500, 'occurred_at' => '2026-05-15', 'description' => 'grande',
        ]);
        Transaction::factory()->for($user)->for($account, 'account')->create([
            'type' => 'expense', 'amount' => 200, 'occurred_at' => '2026-05-20', 'description' => 'media',
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/reports/top-transactions?from=2026-05-01&to=2026-05-31&type=expense&limit=2')
            ->assertOk();

        $response->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.description', 'grande')
            ->assertJsonPath('data.0.amount', '500.00')
            ->assertJsonPath('data.1.description', 'media');
    }

    public function test_cash_flow_forecast_uses_active_recurrings(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create(['initial_balance' => 1000]);

        // Stipendio mensile (income) e affitto mensile (expense)
        RecurringTransaction::factory()->for($user)->for($account, 'account')->create([
            'type' => 'income', 'amount' => 2000,
            'cadence' => 'monthly', 'interval' => 1,
            'starts_on' => Carbon::now()->startOfMonth()->toDateString(),
            'next_run_at' => Carbon::now()->startOfMonth()->toDateString(),
        ]);
        RecurringTransaction::factory()->for($user)->for($account, 'account')->create([
            'type' => 'expense', 'amount' => 800,
            'cadence' => 'monthly', 'interval' => 1,
            'starts_on' => Carbon::now()->startOfMonth()->toDateString(),
            'next_run_at' => Carbon::now()->startOfMonth()->toDateString(),
        ]);
        // ricorrente disattivata: ignorata
        RecurringTransaction::factory()->for($user)->for($account, 'account')->create([
            'type' => 'expense', 'amount' => 9999,
            'cadence' => 'monthly', 'interval' => 1,
            'starts_on' => Carbon::now()->startOfMonth()->toDateString(),
            'next_run_at' => Carbon::now()->startOfMonth()->toDateString(),
            'is_active' => false,
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/reports/cash-flow-forecast?months=3')
            ->assertOk();

        $response->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.income', '2000.00')
            ->assertJsonPath('data.0.expense', '800.00')
            ->assertJsonPath('data.0.net', '1200.00')
            ->assertJsonPath('data.0.projected_net_worth', '2200.00')
            ->assertJsonPath('data.2.projected_net_worth', '4600.00');
    }
}

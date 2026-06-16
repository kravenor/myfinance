<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\RecurringTransaction;
use App\Models\Scenario;
use App\Models\ScenarioItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ExpenseForecastTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_recurring_baseline_when_no_budget(): void
    {
        Carbon::setTestNow('2026-06-01');

        $user = User::factory()->create(['currency' => 'EUR']);
        $account = Account::factory()->for($user)->create();
        $category = Category::factory()->for($user)->create(['type' => 'expense', 'name' => 'Alimentari']);

        RecurringTransaction::factory()->for($user)->create([
            'account_id' => $account->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 100,
            'currency' => 'EUR',
            'cadence' => 'monthly',
            'interval' => 1,
            'starts_on' => '2026-06-15',
            'next_run_at' => '2026-06-15',
            'is_active' => true,
        ]);

        $resp = $this->actingAs($user)
            ->getJson('/api/reports/expense-forecast?months=3')
            ->assertOk()
            ->json('data');

        $this->assertCount(3, $resp['months']);
        $this->assertSame(['2026-06', '2026-07', '2026-08'], $resp['months']);

        $row = collect($resp['categories'])->firstWhere('category_id', $category->id);
        $this->assertNotNull($row);
        $this->assertSame('100.00', $row['monthly'][0]['recurring']);
        $this->assertSame('100.00', $row['monthly'][0]['total']);
        $this->assertNull($row['monthly'][0]['budget']);
        $this->assertFalse($row['monthly'][0]['budget_breach']);

        // Totali: income=0, expense=100, net=-100
        $this->assertSame('0.00', $resp['totals_by_month'][0]['income']);
        $this->assertSame('100.00', $resp['totals_by_month'][0]['expense_total']);
        $this->assertSame('-100.00', $resp['totals_by_month'][0]['net']);
        $this->assertSame('-300.00', $resp['summary']['total_net']);
        $this->assertSame(3, $resp['summary']['months_count']);
    }

    public function test_net_residuo_combines_income_and_expense(): void
    {
        Carbon::setTestNow('2026-06-01');

        $user = User::factory()->create(['currency' => 'EUR']);
        $account = Account::factory()->for($user)->create();

        // Entrata mensile
        RecurringTransaction::factory()->for($user)->create([
            'account_id' => $account->id,
            'category_id' => null,
            'type' => 'income',
            'amount' => 2500,
            'currency' => 'EUR',
            'cadence' => 'monthly',
            'interval' => 1,
            'starts_on' => '2026-06-27',
            'next_run_at' => '2026-06-27',
            'is_active' => true,
        ]);

        // Spesa mensile
        $category = Category::factory()->for($user)->create(['type' => 'expense']);
        RecurringTransaction::factory()->for($user)->create([
            'account_id' => $account->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 800,
            'currency' => 'EUR',
            'cadence' => 'monthly',
            'interval' => 1,
            'starts_on' => '2026-06-15',
            'next_run_at' => '2026-06-15',
            'is_active' => true,
        ]);

        $resp = $this->actingAs($user)
            ->getJson('/api/reports/expense-forecast?months=2')
            ->assertOk()
            ->json('data');

        foreach ($resp['totals_by_month'] as $m) {
            $this->assertSame('2500.00', $m['income']);
            $this->assertSame('800.00', $m['expense_total']);
            $this->assertSame('1700.00', $m['net']);
        }
        $this->assertSame('5000.00', $resp['summary']['total_income']);
        $this->assertSame('1600.00', $resp['summary']['total_expense']);
        $this->assertSame('3400.00', $resp['summary']['total_net']);
        $this->assertSame('1700.00', $resp['summary']['min_monthly_net']);
    }

    public function test_budget_overrides_recurring_as_forecast_base(): void
    {
        Carbon::setTestNow('2026-06-01');

        $user = User::factory()->create(['currency' => 'EUR']);
        $account = Account::factory()->for($user)->create();
        $category = Category::factory()->for($user)->create(['type' => 'expense']);

        RecurringTransaction::factory()->for($user)->create([
            'account_id' => $account->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 100,
            'currency' => 'EUR',
            'cadence' => 'monthly',
            'interval' => 1,
            'starts_on' => '2026-06-15',
            'next_run_at' => '2026-06-15',
            'is_active' => true,
        ]);

        Budget::factory()->for($user)->create([
            'category_id' => $category->id,
            'year' => 2026,
            'month' => 6,
            'amount' => 300,
        ]);

        $resp = $this->actingAs($user)
            ->getJson('/api/reports/expense-forecast?months=2')
            ->assertOk()
            ->json('data');

        $row = collect($resp['categories'])->firstWhere('category_id', $category->id);
        $this->assertSame('300.00', $row['monthly'][0]['budget']);
        $this->assertSame('300.00', $row['monthly'][0]['forecast_base']);
        $this->assertSame('300.00', $row['monthly'][0]['total']);
        // Mese senza budget: ricade su ricorrenti
        $this->assertNull($row['monthly'][1]['budget']);
        $this->assertSame('100.00', $row['monthly'][1]['total']);
    }

    public function test_scenario_adds_to_forecast_and_flags_budget_breach(): void
    {
        Carbon::setTestNow('2026-06-01');

        $user = User::factory()->create(['currency' => 'EUR']);
        $category = Category::factory()->for($user)->create(['type' => 'expense']);

        Budget::factory()->for($user)->create([
            'category_id' => $category->id,
            'year' => 2026,
            'month' => 7,
            'amount' => 200,
        ]);

        $scenario = Scenario::factory()->for($user)->create();
        ScenarioItem::factory()->for($user)->for($scenario)->create([
            'category_id' => $category->id,
            'amount' => 500,
            'currency' => 'EUR',
            'cadence' => 'one_time',
            'starts_on' => '2026-07-10',
            'ends_on' => null,
        ]);

        $resp = $this->actingAs($user)
            ->getJson("/api/reports/expense-forecast?months=2&scenario_id={$scenario->id}")
            ->assertOk()
            ->json('data');

        $row = collect($resp['categories'])->firstWhere('category_id', $category->id);
        $july = $row['monthly'][1];
        $this->assertSame('200.00', $july['budget']);
        $this->assertSame('500.00', $july['scenario']);
        $this->assertSame('700.00', $july['total']);
        $this->assertTrue($july['budget_breach']);

        $this->assertSame($scenario->id, $resp['scenario']['id']);
    }

    public function test_compare_endpoint_returns_baseline_plus_active_scenarios(): void
    {
        Carbon::setTestNow('2026-06-01');

        $user = User::factory()->create(['currency' => 'EUR']);

        $active = Scenario::factory()->for($user)->create(['name' => 'Vacanza', 'is_active' => true]);
        ScenarioItem::factory()->for($user)->for($active)->create([
            'category_id' => null,
            'amount' => 300,
            'currency' => 'EUR',
            'cadence' => 'one_time',
            'starts_on' => '2026-06-10',
        ]);

        // scenario inattivo non incluso nel default
        Scenario::factory()->for($user)->create(['name' => 'Bozza', 'is_active' => false]);

        $resp = $this->actingAs($user)
            ->getJson('/api/reports/expense-forecast/compare?months=2')
            ->assertOk()
            ->json('data');

        $this->assertNotNull($resp['baseline']);
        $this->assertSame('0.00', $resp['baseline']['totals_by_month'][0]['expense_total']);
        $this->assertCount(1, $resp['scenarios']);
        $this->assertSame($active->id, $resp['scenarios'][0]['scenario']['id']);
        $this->assertSame('300.00', $resp['scenarios'][0]['totals_by_month'][0]['expense_total']);
    }

    public function test_scenario_monthly_cadence_expands_across_horizon(): void
    {
        Carbon::setTestNow('2026-06-01');

        $user = User::factory()->create(['currency' => 'EUR']);
        $scenario = Scenario::factory()->for($user)->create();
        ScenarioItem::factory()->for($user)->for($scenario)->create([
            'category_id' => null,
            'amount' => 50,
            'currency' => 'EUR',
            'cadence' => 'monthly',
            'interval' => 1,
            'starts_on' => '2026-06-01',
            'ends_on' => null,
        ]);

        $resp = $this->actingAs($user)
            ->getJson("/api/reports/expense-forecast?months=4&scenario_id={$scenario->id}")
            ->assertOk()
            ->json('data');

        $uncategorized = collect($resp['categories'])->firstWhere('category_id', null);
        $this->assertNotNull($uncategorized);
        $this->assertCount(4, $uncategorized['monthly']);
        foreach ($uncategorized['monthly'] as $cell) {
            $this->assertSame('50.00', $cell['scenario']);
            $this->assertSame('50.00', $cell['total']);
        }
    }
}

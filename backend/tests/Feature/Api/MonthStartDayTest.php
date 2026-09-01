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

class MonthStartDayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-10');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: User, 1: Category, 2: Account} */
    private function scenario(int $startDay): array
    {
        $user = User::factory()->create(['month_start_day' => $startDay]);
        $category = Category::factory()->for($user)->create(['type' => 'expense']);
        $account = Account::factory()->for($user)->create(['currency' => 'EUR']);

        // Una spesa nel ciclo "giugno" (27/06 → 26/07) e una nel ciclo successivo.
        foreach (['2026-06-28' => 100, '2026-07-28' => 50] as $date => $amount) {
            Transaction::factory()->for($user)->for($account)->for($category)->create([
                'type' => 'expense', 'amount' => $amount, 'currency' => 'EUR', 'occurred_at' => $date,
            ]);
        }

        return [$user, $category, $account];
    }

    public function test_budget_spent_uses_the_financial_cycle(): void
    {
        [$user, $category] = $this->scenario(27);
        Budget::factory()->for($user)->for($category)->create([
            'year' => 2026, 'month' => 6, 'amount' => 500,
        ]);

        // Il budget 2026-06 copre 27/06 → 26/07: prende la spesa del 28/06, non quella del 28/07.
        $this->actingAs($user)
            ->getJson('/api/budgets?year=2026&month=6')
            ->assertOk()
            ->assertJsonPath('data.0.spent', '100.00');
    }

    public function test_budget_spent_with_default_start_day_is_the_calendar_month(): void
    {
        [$user, $category] = $this->scenario(1);
        Budget::factory()->for($user)->for($category)->create([
            'year' => 2026, 'month' => 6, 'amount' => 500,
        ]);

        $this->actingAs($user)
            ->getJson('/api/budgets?year=2026&month=6')
            ->assertOk()
            ->assertJsonPath('data.0.spent', '100.00');

        // Luglio di calendario contiene solo la spesa del 28/07.
        Budget::factory()->for($user)->for($category)->create([
            'year' => 2026, 'month' => 7, 'amount' => 500,
        ]);
        $this->actingAs($user)
            ->getJson('/api/budgets?year=2026&month=7')
            ->assertOk()
            ->assertJsonPath('data.0.spent', '50.00');
    }

    public function test_period_comparison_range_follows_the_start_day(): void
    {
        [$user] = $this->scenario(27);

        // Oggi è il 10/07: il ciclo corrente è quello aperto il 27/06.
        $this->actingAs($user)
            ->getJson('/api/reports/period-comparison?unit=month')
            ->assertOk()
            ->assertJsonPath('data.current.label', '2026-06')
            ->assertJsonPath('data.current.from', '2026-06-27')
            ->assertJsonPath('data.current.to', '2026-07-26')
            ->assertJsonPath('data.current.expense', '100.00')
            ->assertJsonPath('data.previous.from', '2026-05-27')
            ->assertJsonPath('data.previous.to', '2026-06-26');
    }

    public function test_default_start_day_keeps_calendar_periods(): void
    {
        [$user] = $this->scenario(1);

        $this->actingAs($user)
            ->getJson('/api/reports/period-comparison?unit=month')
            ->assertOk()
            ->assertJsonPath('data.current.label', '2026-07')
            ->assertJsonPath('data.current.from', '2026-07-01')
            ->assertJsonPath('data.current.to', '2026-07-31');
    }

    public function test_preference_is_exposed_and_updatable(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/auth/me')->assertJsonPath('data.month_start_day', 1);

        $this->actingAs($user)
            ->putJson('/api/auth/preferences', ['month_start_day' => 27])
            ->assertOk()
            ->assertJsonPath('data.month_start_day', 27);

        $this->actingAs($user)
            ->putJson('/api/auth/preferences', ['month_start_day' => 31])
            ->assertStatus(422);
    }
}

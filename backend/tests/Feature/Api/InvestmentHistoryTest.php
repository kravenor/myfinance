<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\InstrumentPrice;
use App\Models\InvestmentHolding;
use App\Models\InvestmentTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvestmentHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function holding(User $user, array $attributes = []): InvestmentHolding
    {
        $account = Account::factory()->for($user)->create(['type' => 'investment', 'currency' => 'EUR']);

        return InvestmentHolding::factory()->for($user)->create(array_merge([
            'account_id' => $account->id,
            'currency' => 'EUR',
            'symbol' => 'VWCE',
            'quantity' => 0,
            'avg_cost' => 0,
            'last_price' => null,
        ], $attributes));
    }

    private function movement(User $user, InvestmentHolding $holding, array $attributes): void
    {
        InvestmentTransaction::factory()->for($user)->create(array_merge([
            'investment_holding_id' => $holding->id,
            'side' => 'buy',
            'fees' => 0,
        ], $attributes));
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/api/investments/history')->assertUnauthorized();
    }

    public function test_empty_portfolio_has_no_points(): void
    {
        $user = User::factory()->create(['currency' => 'EUR']);
        $this->holding($user);

        $this->actingAs($user)->getJson('/api/investments/history')
            ->assertOk()
            ->assertJsonPath('base_currency', 'EUR')
            ->assertJsonPath('points', []);
    }

    public function test_series_runs_monthly_from_the_first_movement_to_today(): void
    {
        $this->travelTo('2026-04-15');

        $user = User::factory()->create(['currency' => 'EUR']);
        $holding = $this->holding($user);

        $this->movement($user, $holding, ['occurred_at' => '2026-01-10', 'quantity' => 10, 'price' => 40]);
        $this->movement($user, $holding, ['occurred_at' => '2026-02-10', 'quantity' => 10, 'price' => 50]);

        // Una sola quotazione, a marzo: prima di quella data il valore non è ricostruibile.
        InstrumentPrice::create(['symbol' => 'VWCE', 'currency' => 'EUR', 'price' => 60, 'as_of' => '2026-03-31']);

        $points = $this->actingAs($user)->getJson('/api/investments/history')
            ->assertOk()
            ->json('points');

        $this->assertSame(['2026-01', '2026-02', '2026-03', '2026-04'], array_column($points, 'month'));
        // L'ultimo punto è oggi, così combacia con l'overview.
        $this->assertSame('2026-04-15', $points[3]['as_of']);

        // Senza quotazione il valore è il costo di quel momento: hai appena comprato a quel prezzo.
        $this->assertSame(['400.00', '400.00'], [$points[0]['invested'], $points[0]['market_value']]);
        $this->assertSame(['900.00', '900.00'], [$points[1]['invested'], $points[1]['market_value']]);

        // Da marzo la quotazione c'è e il valore si stacca dal versato.
        $this->assertSame('900.00', $points[2]['invested']);
        $this->assertSame('1200.00', $points[2]['market_value']);
        $this->assertSame('300.00', $points[2]['unrealized_pl']);

        // Ad aprile vale ancora l'ultima quotazione disponibile.
        $this->assertSame('1200.00', $points[3]['market_value']);
    }

    public function test_a_sell_lowers_both_the_invested_capital_and_the_position(): void
    {
        $this->travelTo('2026-03-15');

        $user = User::factory()->create(['currency' => 'EUR']);
        $holding = $this->holding($user);

        $this->movement($user, $holding, ['occurred_at' => '2026-01-10', 'quantity' => 10, 'price' => 40]);
        $this->movement($user, $holding, ['occurred_at' => '2026-02-10', 'side' => 'sell', 'quantity' => 4, 'price' => 50]);

        InstrumentPrice::create(['symbol' => 'VWCE', 'currency' => 'EUR', 'price' => 50, 'as_of' => '2026-02-01']);

        $points = $this->actingAs($user)->getJson('/api/investments/history')->assertOk()->json('points');

        // 400 versati meno 200 rientrati dalla vendita.
        $this->assertSame('200.00', $points[1]['invested']);
        // 6 quote residue quotate 50.
        $this->assertSame('300.00', $points[1]['market_value']);
    }

    public function test_history_is_scoped_to_the_authenticated_user(): void
    {
        $this->travelTo('2026-03-15');

        $mine = User::factory()->create(['currency' => 'EUR']);
        $other = User::factory()->create(['currency' => 'EUR']);
        $theirs = $this->holding($other);
        $this->movement($other, $theirs, ['occurred_at' => '2026-01-10', 'quantity' => 10, 'price' => 40]);

        $this->actingAs($mine)->getJson('/api/investments/history')
            ->assertOk()
            ->assertJsonPath('points', []);
    }

    public function test_xirr_annualizes_the_money_weighted_return(): void
    {
        $this->travelTo('2026-01-01');

        $user = User::factory()->create(['currency' => 'EUR']);
        $holding = $this->holding($user);

        // 1000 versati un anno fa, oggi valgono 1100: +10% annuo.
        $this->movement($user, $holding, ['occurred_at' => '2025-01-01', 'quantity' => 10, 'price' => 100]);
        InstrumentPrice::create(['symbol' => 'VWCE', 'currency' => 'EUR', 'price' => 110, 'as_of' => '2025-12-31']);

        $xirr = $this->actingAs($user)->getJson('/api/investments/history')->assertOk()->json('xirr_pct');

        $this->assertEqualsWithDelta(10.0, (float) $xirr, 0.01);
    }

    public function test_xirr_weights_a_later_contribution_less(): void
    {
        $this->travelTo('2026-01-01');

        $user = User::factory()->create(['currency' => 'EUR']);
        $holding = $this->holding($user);

        // Stesso guadagno assoluto del 10% sul totale, ma metà capitale è entrato a metà anno:
        // il rendimento money-weighted deve superare il 10%.
        $this->movement($user, $holding, ['occurred_at' => '2025-01-01', 'quantity' => 5, 'price' => 100]);
        $this->movement($user, $holding, ['occurred_at' => '2025-07-02', 'quantity' => 5, 'price' => 100]);
        InstrumentPrice::create(['symbol' => 'VWCE', 'currency' => 'EUR', 'price' => 110, 'as_of' => '2025-12-31']);

        $xirr = (float) $this->actingAs($user)->getJson('/api/investments/history')->json('xirr_pct');

        $this->assertGreaterThan(10.0, $xirr);
        $this->assertLessThan(15.0, $xirr);
    }

    public function test_xirr_is_null_under_one_year(): void
    {
        $this->travelTo('2026-03-15');

        $user = User::factory()->create(['currency' => 'EUR']);
        $holding = $this->holding($user);
        $this->movement($user, $holding, ['occurred_at' => '2026-01-10', 'quantity' => 10, 'price' => 40]);

        $this->actingAs($user)->getJson('/api/investments/history')
            ->assertOk()
            ->assertJsonPath('xirr_pct', null);
    }
}

<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\InstrumentPrice;
use App\Models\InvestmentHolding;
use App\Models\InvestmentTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Il patrimonio dei mesi passati usa la posizione di quel momento, non la quantità di oggi.
class NetWorthHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_past_months_use_the_position_and_price_of_that_time(): void
    {
        $this->travelTo('2026-04-15');
        $user = User::factory()->create(['currency' => 'EUR']);
        $account = Account::factory()->for($user)->create(['type' => 'investment', 'currency' => 'EUR', 'initial_balance' => 0]);
        // Cache di oggi: 20 quote e un prezzo manuale che nel passato non deve contare.
        $holding = InvestmentHolding::factory()->for($user)->for($account, 'account')->create([
            'currency' => 'EUR', 'asset_type' => 'etf', 'symbol' => 'VWCE', 'quantity' => 20, 'avg_cost' => 110, 'last_price' => 999,
        ]);
        foreach ([['2026-01-10', 100], ['2026-03-10', 120]] as [$date, $price]) {
            InvestmentTransaction::factory()->for($user)->create([
                'investment_holding_id' => $holding->id, 'side' => 'buy', 'occurred_at' => $date,
                'quantity' => 10, 'price' => $price, 'fees' => 0,
            ]);
        }
        InstrumentPrice::create(['provider' => 'yahoo', 'symbol' => 'VWCE', 'currency' => 'EUR', 'price' => 110, 'as_of' => '2026-02-15']);

        $this->actingAs($user)
            ->getJson('/api/reports/net-worth?from=2026-01-01&to=2026-03-31')
            ->assertOk()
            // gennaio: 10 quote, nessuna quotazione → costo medio di allora (100)
            ->assertJsonPath('data.0.net_worth', '1000.00')
            // febbraio: ancora 10 quote, quotazione 110 (prima del fix: 20 × 110)
            ->assertJsonPath('data.1.net_worth', '1100.00')
            // marzo: 20 quote alla quotazione più recente (110)
            ->assertJsonPath('data.2.net_worth', '2200.00');
    }
}

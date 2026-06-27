<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\ExchangeRate;
use App\Models\InstrumentPrice;
use App\Models\InvestmentHolding;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvestmentHoldingTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_requires_auth(): void
    {
        $this->getJson('/api/investment-holdings')->assertUnauthorized();
    }

    public function test_can_create_holding_for_investment_account(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create(['type' => 'investment', 'currency' => 'EUR']);

        $this->actingAs($user)->postJson('/api/investment-holdings', [
            'account_id' => $account->id,
            'name' => 'Vanguard FTSE All-World',
            'symbol' => 'VWCE',
            'asset_type' => 'etf',
            'currency' => 'EUR',
            'quantity' => 10,
            'avg_cost' => 40,
            'last_price' => 50,
        ])->assertCreated()
            ->assertJsonPath('data.market_value', '500.00')
            ->assertJsonPath('data.cost_basis', '400.00')
            ->assertJsonPath('data.unrealized_pl', '100.00')
            ->assertJsonPath('data.unrealized_pl_pct', '25.00')
            ->assertJsonPath('data.effective_price', '50.00')
            ->assertJsonPath('data.price_source', 'manual')
            ->assertJsonPath('data.price_as_of', null);
    }

    public function test_rejects_holding_on_non_investment_account(): void
    {
        $user = User::factory()->create();
        $bank = Account::factory()->for($user)->create(['type' => 'bank']);

        $this->actingAs($user)->postJson('/api/investment-holdings', [
            'account_id' => $bank->id,
            'name' => 'Whatever',
            'asset_type' => 'etf',
            'quantity' => 1,
            'avg_cost' => 1,
        ])->assertJsonValidationErrors('account_id');
    }

    public function test_net_worth_uses_market_value_and_ignores_transactions(): void
    {
        $user = User::factory()->create(['currency' => 'EUR']);
        $account = Account::factory()->for($user)->create(['type' => 'investment', 'currency' => 'EUR', 'initial_balance' => 0]);

        InvestmentHolding::factory()->for($user)->for($account, 'account')->create([
            'currency' => 'EUR', 'quantity' => 10, 'avg_cost' => 40, 'last_price' => 50,
        ]);

        // Una transazione sul conto investment NON deve incidere sul net worth.
        Transaction::factory()->for($user)->for($account, 'account')->create([
            'type' => 'income', 'amount' => 1000, 'currency' => 'EUR', 'occurred_at' => '2026-05-10',
        ]);

        $this->actingAs($user)
            ->getJson('/api/reports/summary?from=2026-05-01&to=2026-05-31')
            ->assertOk()
            ->assertJsonPath('data.net_worth', '500.00')
            ->assertJsonPath('data.accounts.0.balance', '500.00');
    }

    public function test_net_worth_converts_foreign_currency_holding(): void
    {
        ExchangeRate::query()->create(['date' => '2026-05-01', 'currency' => 'USD', 'rate' => 1.10]); // 1 EUR = 1.10 USD

        $user = User::factory()->create(['currency' => 'EUR']);
        $account = Account::factory()->for($user)->create(['type' => 'investment', 'currency' => 'EUR', 'initial_balance' => 0]);

        // 10 × 11 USD = 110 USD → 100 EUR
        InvestmentHolding::factory()->for($user)->for($account, 'account')->create([
            'currency' => 'USD', 'quantity' => 10, 'avg_cost' => 11, 'last_price' => 11,
        ]);

        $this->actingAs($user)
            ->getJson('/api/reports/summary?from=2026-05-01&to=2026-05-31')
            ->assertOk()
            ->assertJsonPath('data.net_worth', '100.00');
    }

    public function test_overview_returns_totals_and_allocation(): void
    {
        $user = User::factory()->create(['currency' => 'EUR']);
        $account = Account::factory()->for($user)->create(['type' => 'investment', 'currency' => 'EUR']);

        InvestmentHolding::factory()->for($user)->for($account, 'account')->create([
            'asset_type' => 'etf', 'currency' => 'EUR', 'quantity' => 10, 'avg_cost' => 40, 'last_price' => 50,
        ]);

        $this->actingAs($user)
            ->getJson('/api/investments/overview')
            ->assertOk()
            ->assertJsonPath('data.base_currency', 'EUR')
            ->assertJsonPath('data.total_market_value', '500.00')
            ->assertJsonPath('data.total_unrealized_pl', '100.00')
            ->assertJsonPath('data.by_asset_type.0.asset_type', 'etf')
            ->assertJsonPath('data.by_asset_type.0.pct', '100.00');
    }

    public function test_overview_uses_auto_quote_over_manual_price(): void
    {
        $user = User::factory()->create(['currency' => 'EUR']);
        $account = Account::factory()->for($user)->create(['type' => 'investment', 'currency' => 'EUR']);

        InvestmentHolding::factory()->for($user)->for($account, 'account')->create([
            'symbol' => 'VWCE.XETRA', 'asset_type' => 'etf', 'currency' => 'EUR',
            'quantity' => 10, 'avg_cost' => 40, 'last_price' => 50,
        ]);
        // Quota automatica più recente del prezzo manuale.
        InstrumentPrice::query()->create([
            'symbol' => 'VWCE.XETRA', 'currency' => 'EUR', 'price' => 60, 'as_of' => now()->toDateString(),
        ]);

        $this->actingAs($user)
            ->getJson('/api/investments/overview')
            ->assertOk()
            ->assertJsonPath('data.total_market_value', '600.00'); // 10×60 (quota), non 10×50 (manuale)
    }

    public function test_index_exposes_auto_price_source_and_as_of(): void
    {
        $user = User::factory()->create(['currency' => 'EUR']);
        $account = Account::factory()->for($user)->create(['type' => 'investment', 'currency' => 'EUR']);

        InvestmentHolding::factory()->for($user)->for($account, 'account')->create([
            'symbol' => 'VWCE.XETRA', 'asset_type' => 'etf', 'currency' => 'EUR',
            'quantity' => 10, 'avg_cost' => 40, 'last_price' => 50,
        ]);
        InstrumentPrice::query()->create([
            'symbol' => 'VWCE.XETRA', 'currency' => 'EUR', 'price' => 60, 'as_of' => '2026-06-20',
        ]);

        $this->actingAs($user)
            ->getJson('/api/investment-holdings')
            ->assertOk()
            ->assertJsonPath('data.0.price_source', 'auto')
            ->assertJsonPath('data.0.effective_price', '60.00')
            ->assertJsonPath('data.0.price_as_of', '2026-06-20')
            ->assertJsonPath('data.0.market_value', '600.00');
    }

    public function test_holdings_scoped_to_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $account = Account::factory()->for($other)->create(['type' => 'investment']);
        InvestmentHolding::factory()->for($other)->for($account, 'account')->create();

        $this->actingAs($user)
            ->getJson('/api/investment-holdings')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}

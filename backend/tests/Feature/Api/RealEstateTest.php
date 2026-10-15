<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\InstrumentPrice;
use App\Models\InvestmentHolding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RealEstateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 12:00:00');
        $this->user = User::factory()->create(['currency' => 'EUR']);
    }

    /** @param  array<string, mixed>  $attrs */
    private function createProperty(array $attrs = []): InvestmentHolding
    {
        $account = Account::factory()->for($this->user)->create(['type' => 'investment', 'currency' => 'EUR']);

        $id = $this->actingAs($this->user)->postJson('/api/investment-holdings', [
            'account_id' => $account->id,
            'name' => 'Casa',
            'asset_type' => 'real_estate',
            'quantity' => 1,
            'avg_cost' => 200000,
            'opened_at' => '2024-05-20', // acquisto nel Q2 2024
            ...$attrs,
        ])->assertCreated()->json('data.id');

        return InvestmentHolding::query()->findOrFail($id);
    }

    private function index(string $area, string $asOf, float $value): void
    {
        InstrumentPrice::query()->create(['provider' => 'istat', 'symbol' => $area, 'currency' => 'XXX', 'price' => $value, 'as_of' => $asOf]);
    }

    public function test_area_defaults_to_italy_and_must_be_a_published_one(): void
    {
        $this->assertSame('IT', $this->createProperty()->symbol);

        $account = Account::factory()->for($this->user)->create(['type' => 'investment']);
        $this->actingAs($this->user)->postJson('/api/investment-holdings', [
            'account_id' => $account->id, 'name' => 'Casa', 'asset_type' => 'real_estate',
            'symbol' => 'CSSPX.MI', 'quantity' => 1, 'avg_cost' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('symbol');
    }

    public function test_cost_is_indexed_from_the_purchase_quarter_to_the_latest_one(): void
    {
        $holding = $this->createProperty(['symbol' => 'ITC45']);
        $this->index('ITC45', '2024-01-01', 90);
        $this->index('ITC45', '2024-04-01', 100); // trimestre dell'acquisto
        $this->index('ITC45', '2026-04-01', 110); // ultimo pubblicato
        $this->index('IT', '2026-04-01', 999);    // altro territorio: ignorato

        $this->getJson("/api/investment-holdings/{$holding->id}")
            ->assertJsonPath('data.effective_price', '220000.00')
            ->assertJsonPath('data.price_source', 'auto')
            ->assertJsonPath('data.price_as_of', '2026-04-01');
    }

    public function test_manual_valuation_is_indexed_from_its_own_date(): void
    {
        $holding = $this->createProperty(['last_price' => 250000, 'last_price_at' => '2025-08-10']);
        $this->index('IT', '2024-04-01', 100);
        $this->index('IT', '2025-07-01', 120);
        $this->index('IT', '2026-04-01', 132);

        // 250.000 × 132 / 120
        $this->getJson("/api/investment-holdings/{$holding->id}")->assertJsonPath('data.effective_price', '275000.00');
    }

    public function test_without_index_falls_back_to_valuation_then_cost(): void
    {
        $holding = $this->createProperty(['last_price' => 250000, 'last_price_at' => '2025-08-10']);

        $this->getJson("/api/investment-holdings/{$holding->id}")
            ->assertJsonPath('data.effective_price', '250000.00')
            ->assertJsonPath('data.price_source', 'manual');
    }

    public function test_history_without_index_falls_back_to_cost(): void
    {
        $this->createProperty();

        $this->getJson('/api/investments/history')->assertOk()
            ->assertJsonPath('points.0.market_value', '200000.00');
    }

    public function test_history_indexes_cost_until_the_valuation_then_the_valuation(): void
    {
        $this->createProperty(['last_price' => 300000, 'last_price_at' => '2025-08-10']);
        $this->index('IT', '2024-04-01', 100);
        $this->index('IT', '2025-01-01', 110);
        $this->index('IT', '2025-07-01', 120);

        $points = collect($this->getJson('/api/investments/history')->assertOk()->json('points'))->keyBy('month');

        $this->assertSame('200000.00', $points['2024-05']['market_value']);
        $this->assertSame('220000.00', $points['2025-02']['market_value']); // costo × 110/100
        $this->assertSame('300000.00', $points['2025-09']['market_value']); // valutazione, stesso trimestre
        $this->assertSame('300000.00', $points['2026-10']['market_value']);
    }
}

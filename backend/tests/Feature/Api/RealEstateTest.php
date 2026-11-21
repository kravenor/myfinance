<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\InstrumentPrice;
use App\Models\InvestmentHolding;
use App\Models\OmiQuotation;
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

    private function omi(string $semester, float $min, float $max, ?User $user = null): void
    {
        OmiQuotation::query()->create([
            'user_id' => ($user ?? $this->user)->id, 'semester' => $semester, 'link_zona' => 'TO00000037',
            'comune' => 'TORINO', 'prov' => 'TO', 'zona' => 'B1', 'zona_descr' => 'CENTRO STORICO',
            'cod_tip' => 20, 'tipologia' => 'Abitazioni civili', 'stato' => 'NORMALE',
            'compr_min' => $min, 'compr_max' => $max, 'sup_nl' => 'L',
        ]);
    }

    /** @return array<string, mixed> */
    private function zone(): array
    {
        return ['symbol' => 'ITC11', 'omi_zone' => 'TO00000037', 'omi_type' => 20, 'omi_state' => 'NORMALE'];
    }

    public function test_zone_must_be_among_own_omi_quotations(): void
    {
        $this->omi('2024-01-01', 2000, 3000, User::factory()->create());

        $account = Account::factory()->for($this->user)->create(['type' => 'investment']);
        $this->actingAs($this->user)->postJson('/api/investment-holdings', [
            'account_id' => $account->id, 'name' => 'Casa', 'asset_type' => 'real_estate',
            'quantity' => 1, 'avg_cost' => 1, ...$this->zone(),
        ])->assertUnprocessable()->assertJsonValidationErrors('omi_zone');
    }

    public function test_omi_zone_drives_the_index_where_loaded(): void
    {
        $this->omi('2024-01-01', 2000, 3000); // 2500: semestre dell'acquisto (maggio 2024)
        $this->omi('2025-07-01', 2500, 3500); // 3000: ultimo caricato
        $this->index('ITC11', '2024-01-01', 100);
        $this->index('ITC11', '2025-07-01', 101); // ISTAT quasi fermo: con OMI non conta

        $holding = $this->createProperty($this->zone());

        // 200.000 × 3000 / 2500
        $this->getJson("/api/investment-holdings/{$holding->id}")
            ->assertJsonPath('data.effective_price', '240000.00')
            ->assertJsonPath('data.price_index', 'omi')
            ->assertJsonPath('data.price_as_of', '2025-07-01')
            ->assertJsonPath('data.omi_zone_label', 'TORINO B1 CENTRO STORICO');
    }

    public function test_istat_extends_the_omi_series_before_and_after(): void
    {
        $this->omi('2024-01-01', 2000, 3000); // 2500
        $this->index('ITC11', '2023-01-01', 80);  // prima di OMI: 2500 × 80/100 = 2000
        $this->index('ITC11', '2024-01-01', 100); // raccordo
        $this->index('ITC11', '2026-04-01', 110); // dopo OMI: 2500 × 110/100 = 2750

        $holding = $this->createProperty([...$this->zone(), 'opened_at' => '2023-03-10']);

        // 200.000 × 2750 / 2000
        $this->getJson("/api/investment-holdings/{$holding->id}")
            ->assertJsonPath('data.effective_price', '275000.00')
            ->assertJsonPath('data.price_index', 'istat')
            ->assertJsonPath('data.price_as_of', '2026-04-01');

        $points = collect($this->getJson('/api/investments/history')->assertOk()->json('points'))->keyBy('month');
        $this->assertSame('250000.00', $points['2024-06']['market_value']); // 200.000 × 2500 / 2000
    }

    public function test_zone_is_dropped_when_the_holding_is_not_a_property(): void
    {
        $this->omi('2024-01-01', 2000, 3000);
        $holding = $this->createProperty($this->zone());

        $this->putJson("/api/investment-holdings/{$holding->id}", ['asset_type' => 'other', 'symbol' => null])->assertOk();

        $this->assertNull($holding->fresh()->omi_zone);
    }

    public function test_surface_gives_the_omi_zone_average_for_comparison_only(): void
    {
        $this->omi('2024-01-01', 2000, 3000);
        $this->omi('2025-07-01', 2500, 3500);
        $holding = $this->createProperty([...$this->zone(), 'surface_sqm' => 80]);

        $this->getJson("/api/investment-holdings/{$holding->id}")
            ->assertJsonPath('data.effective_price', '240000.00') // invariato: la superficie non entra nel calcolo
            ->assertJsonPath('data.surface_sqm', '80.00')
            ->assertJsonPath('data.omi_comparison', [
                'semester' => '2025-07-01', 'surface_basis' => 'L',
                'min' => '200000.00', 'mid' => '240000.00', 'max' => '280000.00',
            ]);
    }

    public function test_no_comparison_without_zone_or_surface(): void
    {
        $this->omi('2025-07-01', 2500, 3500);

        $withoutZone = $this->createProperty(['surface_sqm' => 80]);
        $this->getJson("/api/investment-holdings/{$withoutZone->id}")->assertJsonPath('data.omi_comparison', null);

        $withoutSurface = $this->createProperty($this->zone());
        $this->getJson("/api/investment-holdings/{$withoutSurface->id}")->assertJsonPath('data.omi_comparison', null);

        $this->putJson("/api/investment-holdings/{$withoutZone->id}", ['asset_type' => 'other', 'symbol' => null])->assertOk();
        $this->assertNull($withoutZone->fresh()->surface_sqm);
    }
}

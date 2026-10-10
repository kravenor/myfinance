<?php

namespace Tests\Feature\Console;

use App\Models\Account;
use App\Models\InstrumentPrice;
use App\Models\InvestmentHolding;
use App\Models\InvestmentTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BackfillInstrumentPricesTest extends TestCase
{
    use RefreshDatabase;

    private function holdingWithMovement(string $symbol, string $assetType, string $firstMovement): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create(['type' => 'investment']);
        $holding = InvestmentHolding::factory()->for($user)->for($account, 'account')->create([
            'symbol' => $symbol, 'asset_type' => $assetType, 'currency' => 'EUR',
        ]);
        InvestmentTransaction::factory()->for($user)->create([
            'investment_holding_id' => $holding->id, 'side' => 'buy', 'occurred_at' => $firstMovement,
        ]);
    }

    /**
     * Risposta chart di Yahoo: [data locale, close] con timestamp all'apertura (09:00 a Milano).
     *
     * @param  list<array{0: string, 1: float|null}>  $days
     * @return array<string, mixed>
     */
    private function yahooHistory(array $days): array
    {
        $ts = array_map(fn ($d) => Carbon::parse($d[0].' 09:00', 'Europe/Rome')->timestamp, $days);

        return ['chart' => ['result' => [[
            'meta' => ['currency' => 'EUR', 'gmtoffset' => 3600],
            'timestamp' => $ts,
            'indicators' => ['quote' => [['close' => array_column($days, 1)]]],
        ]]]];
    }

    public function test_stores_the_last_valid_close_of_each_month_from_the_first_movement(): void
    {
        $this->travelTo('2026-03-10');
        $this->holdingWithMovement('VWCE.MI', 'etf', '2026-01-12');
        Http::fake(['*/v8/finance/chart/VWCE.MI*' => Http::response($this->yahooHistory([
            ['2026-01-29', 99.0], ['2026-01-30', 100.0],
            ['2026-02-25', 105.0], ['2026-02-26', null],
            ['2026-03-09', 107.5],
        ]))]);

        $this->artisan('prices:backfill')->assertSuccessful();

        $this->assertSame(
            [['2026-01-30', '100.00000000'], ['2026-02-25', '105.00000000'], ['2026-03-09', '107.50000000']],
            InstrumentPrice::query()->orderBy('as_of')->get()->map(fn ($p) => [$p->as_of->toDateString(), $p->price])->all(),
        );
        // Dal primo giorno del mese del primo movimento.
        Http::assertSent(fn (Request $r) => $r['period1'] === Carbon::parse('2026-01-01')->timestamp);
    }

    public function test_skips_covered_symbols_and_providers_without_history(): void
    {
        $this->travelTo('2026-03-10');
        $this->holdingWithMovement('COVERED.MI', 'etf', '2026-01-12');
        $this->holdingWithMovement('IT0005534984', 'bond', '2026-01-12');
        InstrumentPrice::create(['provider' => 'yahoo', 'symbol' => 'COVERED.MI', 'currency' => 'EUR', 'price' => 50, 'as_of' => '2026-01-30']);
        Http::fake(['*/v8/finance/chart/COVERED.MI*' => Http::response($this->yahooHistory([['2026-02-27', 55.0]]))]);

        // Già coperto dal mese del primo movimento; il BTP passa da Borsa Italiana, senza storico.
        $this->artisan('prices:backfill')->assertSuccessful();
        Http::assertNothingSent();

        $this->artisan('prices:backfill --force')->assertSuccessful();
        $this->assertDatabaseHas('instrument_prices', ['symbol' => 'COVERED.MI', 'as_of' => '2026-02-27']);
    }
}

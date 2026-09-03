<?php

namespace Tests\Feature\Console;

use App\Models\Account;
use App\Models\InstrumentPrice;
use App\Models\InvestmentHolding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FetchInstrumentPricesTest extends TestCase
{
    use RefreshDatabase;

    private function holding(User $user, string $symbol, string $assetType, string $currency = 'EUR'): void
    {
        $account = Account::factory()->for($user)->create(['type' => 'investment']);
        InvestmentHolding::factory()->for($user)->for($account, 'account')->create([
            'symbol' => $symbol, 'asset_type' => $assetType, 'currency' => $currency,
        ]);
    }

    /** @return array<string, mixed> */
    private function yahooChart(float $price, string $currency): array
    {
        return ['chart' => ['result' => [['meta' => [
            'regularMarketPrice' => $price, 'currency' => $currency,
        ]]]]];
    }

    public function test_fetches_yahoo_and_coingecko_using_currency_from_response(): void
    {
        Http::fake([
            '*/v8/finance/chart/CSSPX.MI*' => Http::response($this->yahooChart(696.41, 'EUR')),
            '*/v8/finance/chart/AAPL*' => Http::response($this->yahooChart(200.0, 'USD')),
            'api.coingecko.com/*' => Http::response(['bitcoin' => ['eur' => 50000]]),
        ]);

        $user = User::factory()->create();
        $this->holding($user, 'CSSPX.MI', 'etf');
        $this->holding($user, 'AAPL', 'stock');
        $this->holding($user, 'bitcoin', 'crypto');

        $this->artisan('prices:fetch')->assertSuccessful();

        $this->assertDatabaseHas('instrument_prices', ['symbol' => 'CSSPX.MI', 'currency' => 'EUR', 'price' => 696.41]);
        $this->assertDatabaseHas('instrument_prices', ['symbol' => 'AAPL', 'currency' => 'USD', 'price' => 200.0]);
        $this->assertDatabaseHas('instrument_prices', ['symbol' => 'bitcoin', 'currency' => 'EUR', 'price' => 50000]);
        $this->assertSame(3, InstrumentPrice::query()->count());
    }

    public function test_routes_bond_isin_to_borsa_italiana(): void
    {
        Http::fake([
            'borsaitaliana.it/*' => Http::response(
                '<strong>Prezzo di riferimento</strong><span class="t-text -right">101,51</span>'
                .'<strong>Data di riferimento</strong><span class="t-text -right">02/09/2026</span>'
            ),
        ]);

        $this->holding(User::factory()->create(), 'IT0005534984', 'bond');

        $this->artisan('prices:fetch')->assertSuccessful();

        // Prezzo in percentuale del nominale → 1,0151 per euro nominale.
        $this->assertDatabaseHas('instrument_prices', [
            'symbol' => 'IT0005534984', 'currency' => 'EUR', 'price' => 1.0151, 'as_of' => '2026-09-02',
        ]);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'IT0005534984.html'));
    }

    public function test_skips_symbol_when_quote_has_no_price(): void
    {
        Http::fake([
            '*/v8/finance/chart/*' => Http::response(['chart' => ['result' => [['meta' => ['currency' => 'EUR']]]]]),
        ]);

        $this->holding(User::factory()->create(), 'CSSPX.MI', 'etf');

        $this->artisan('prices:fetch')->assertSuccessful();

        $this->assertDatabaseMissing('instrument_prices', ['symbol' => 'CSSPX.MI']);
    }

    public function test_one_provider_failure_does_not_block_the_other(): void
    {
        Http::fake([
            '*/v8/finance/chart/*' => Http::response([], 500),
            'api.coingecko.com/*' => Http::response(['bitcoin' => ['eur' => 50000]]),
        ]);

        $user = User::factory()->create();
        $this->holding($user, 'CSSPX.MI', 'etf');
        $this->holding($user, 'bitcoin', 'crypto');

        $this->artisan('prices:fetch')->assertSuccessful();

        $this->assertDatabaseMissing('instrument_prices', ['symbol' => 'CSSPX.MI']);
        $this->assertDatabaseHas('instrument_prices', ['symbol' => 'bitcoin']);
    }

    public function test_collects_symbols_across_all_users(): void
    {
        Http::fake(['api.coingecko.com/*' => Http::response([
            'bitcoin' => ['eur' => 50000], 'ethereum' => ['eur' => 3000],
        ])]);

        $this->holding(User::factory()->create(), 'bitcoin', 'crypto');
        $this->holding(User::factory()->create(), 'ethereum', 'crypto');

        $this->artisan('prices:fetch')->assertSuccessful();

        $this->assertDatabaseHas('instrument_prices', ['symbol' => 'bitcoin']);
        $this->assertDatabaseHas('instrument_prices', ['symbol' => 'ethereum']);
    }

    public function test_symbol_option_limits_fetch(): void
    {
        Http::fake(['api.coingecko.com/*' => Http::response(['bitcoin' => ['eur' => 50000]])]);

        $user = User::factory()->create();
        $this->holding($user, 'bitcoin', 'crypto');
        $this->holding($user, 'ethereum', 'crypto');

        $this->artisan('prices:fetch', ['--symbol' => ['bitcoin']])->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'ids=bitcoin')
            && ! str_contains($request->url(), 'ethereum'));
        $this->assertDatabaseHas('instrument_prices', ['symbol' => 'bitcoin']);
        $this->assertDatabaseMissing('instrument_prices', ['symbol' => 'ethereum']);
    }
}

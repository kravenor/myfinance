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

    public function test_fetches_eodhd_and_coingecko_with_suffix_currency(): void
    {
        config(['finance.prices.eodhd.api_key' => 'test-key']);
        Http::fake([
            'eodhd.com/api/real-time/VWCE.XETRA*' => Http::response(['code' => 'VWCE.XETRA', 'close' => 107.5]),
            'eodhd.com/api/real-time/AAPL.US*' => Http::response(['code' => 'AAPL.US', 'close' => 200.0]),
            'api.coingecko.com/*' => Http::response(['bitcoin' => ['eur' => 50000]]),
        ]);

        $user = User::factory()->create();
        $this->holding($user, 'VWCE.XETRA', 'etf');
        $this->holding($user, 'AAPL.US', 'stock');
        $this->holding($user, 'bitcoin', 'crypto');

        $this->artisan('prices:fetch')->assertSuccessful();

        $this->assertDatabaseHas('instrument_prices', ['symbol' => 'VWCE.XETRA', 'currency' => 'EUR', 'price' => 107.5]);
        $this->assertDatabaseHas('instrument_prices', ['symbol' => 'AAPL.US', 'currency' => 'USD', 'price' => 200.0]);
        $this->assertDatabaseHas('instrument_prices', ['symbol' => 'bitcoin', 'currency' => 'EUR', 'price' => 50000]);
        $this->assertSame(3, InstrumentPrice::query()->count());
    }

    public function test_skips_eodhd_group_when_api_key_missing(): void
    {
        config(['finance.prices.eodhd.api_key' => null]);
        Http::fake(['api.coingecko.com/*' => Http::response(['bitcoin' => ['eur' => 50000]])]);

        $user = User::factory()->create();
        $this->holding($user, 'VWCE.XETRA', 'etf');
        $this->holding($user, 'bitcoin', 'crypto');

        $this->artisan('prices:fetch')->assertSuccessful();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'eodhd.com'));
        $this->assertDatabaseMissing('instrument_prices', ['symbol' => 'VWCE.XETRA']);
        $this->assertDatabaseHas('instrument_prices', ['symbol' => 'bitcoin']);
    }

    public function test_one_provider_failure_does_not_block_the_other(): void
    {
        config(['finance.prices.eodhd.api_key' => 'test-key']);
        Http::fake([
            'eodhd.com/*' => Http::response([], 500),
            'api.coingecko.com/*' => Http::response(['bitcoin' => ['eur' => 50000]]),
        ]);

        $user = User::factory()->create();
        $this->holding($user, 'VWCE.XETRA', 'etf');
        $this->holding($user, 'bitcoin', 'crypto');

        $this->artisan('prices:fetch')->assertSuccessful();

        $this->assertDatabaseMissing('instrument_prices', ['symbol' => 'VWCE.XETRA']);
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

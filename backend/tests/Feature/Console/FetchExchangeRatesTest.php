<?php

namespace Tests\Feature\Console;

use App\Models\ExchangeRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FetchExchangeRatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_fetch_latest_stores_rates_with_pivot(): void
    {
        Http::fake([
            '*/latest*' => Http::response([
                'amount' => 1.0,
                'base' => 'EUR',
                'date' => '2026-06-12',
                'rates' => ['USD' => 1.1567, 'GBP' => 0.8421],
            ]),
        ]);

        $this->artisan('exchange-rates:fetch')->assertSuccessful();

        $this->assertDatabaseHas('exchange_rates', ['date' => '2026-06-12', 'currency' => 'EUR', 'rate' => 1]);
        $this->assertDatabaseHas('exchange_rates', ['date' => '2026-06-12', 'currency' => 'USD']);
        $this->assertDatabaseHas('exchange_rates', ['date' => '2026-06-12', 'currency' => 'GBP']);
        $this->assertSame(3, ExchangeRate::query()->count());
    }

    public function test_backfill_stores_time_series(): void
    {
        Http::fake([
            '*' => Http::response([
                'amount' => 1.0,
                'base' => 'EUR',
                'start_date' => '2026-06-10',
                'end_date' => '2026-06-11',
                'rates' => [
                    '2026-06-10' => ['USD' => 1.10],
                    '2026-06-11' => ['USD' => 1.12],
                ],
            ]),
        ]);

        $this->artisan('exchange-rates:fetch', ['--from' => '2026-06-10', '--to' => '2026-06-11'])
            ->assertSuccessful();

        // 2 date × (EUR pivot + USD) = 4 righe
        $this->assertSame(4, ExchangeRate::query()->count());
        $this->assertDatabaseHas('exchange_rates', ['date' => '2026-06-11', 'currency' => 'USD', 'rate' => 1.12]);
    }
}

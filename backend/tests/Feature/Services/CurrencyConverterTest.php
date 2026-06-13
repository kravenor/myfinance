<?php

namespace Tests\Feature\Services;

use App\Models\ExchangeRate;
use App\Services\CurrencyConverter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CurrencyConverterTest extends TestCase
{
    use RefreshDatabase;

    private function rate(string $date, string $currency, float $rate): void
    {
        ExchangeRate::query()->create(['date' => $date, 'currency' => $currency, 'rate' => $rate]);
    }

    public function test_converts_between_currencies_via_pivot(): void
    {
        // 1 EUR = 1.10 USD, 1 EUR = 0.80 GBP
        $this->rate('2026-05-10', 'USD', 1.10);
        $this->rate('2026-05-10', 'GBP', 0.80);

        $converter = app(CurrencyConverter::class);
        $date = Carbon::parse('2026-05-10');

        $this->assertEqualsWithDelta(110.0, $converter->convert(100, 'EUR', 'USD', $date), 0.001);
        $this->assertEqualsWithDelta(100.0, $converter->convert(110, 'USD', 'EUR', $date), 0.001);
        // USD -> GBP passa dal pivot: 110 USD = 100 EUR = 80 GBP
        $this->assertEqualsWithDelta(80.0, $converter->convert(110, 'USD', 'GBP', $date), 0.001);
    }

    public function test_uses_rate_at_date(): void
    {
        $this->rate('2026-05-01', 'USD', 1.10);
        $this->rate('2026-05-20', 'USD', 1.20);

        $converter = app(CurrencyConverter::class);

        // 2026-05-10 usa l'ultimo tasso <= data (1.10)
        $this->assertEqualsWithDelta(110.0, $converter->convert(100, 'EUR', 'USD', Carbon::parse('2026-05-10')), 0.001);
        // 2026-05-25 usa 1.20
        $this->assertEqualsWithDelta(120.0, $converter->convert(100, 'EUR', 'USD', Carbon::parse('2026-05-25')), 0.001);
        // data precedente allo storico → primo tasso disponibile
        $this->assertEqualsWithDelta(110.0, $converter->convert(100, 'EUR', 'USD', Carbon::parse('2026-04-01')), 0.001);
    }

    public function test_parity_when_no_rate_available(): void
    {
        $converter = app(CurrencyConverter::class);

        $this->assertSame(100.0, $converter->convert(100, 'EUR', 'USD', Carbon::now()));
        $this->assertSame(1.0, $converter->rateFor('USD', Carbon::now()));
    }
}

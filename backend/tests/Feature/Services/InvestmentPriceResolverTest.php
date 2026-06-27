<?php

namespace Tests\Feature\Services;

use App\Models\Account;
use App\Models\ExchangeRate;
use App\Models\InstrumentPrice;
use App\Models\InvestmentHolding;
use App\Models\User;
use App\Services\InvestmentPriceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class InvestmentPriceResolverTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function holding(array $attrs = []): InvestmentHolding
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create(['type' => 'investment', 'currency' => 'EUR']);

        return InvestmentHolding::factory()->for($user)->for($account, 'account')->create($attrs);
    }

    public function test_auto_quote_overrides_manual_price(): void
    {
        $h = $this->holding([
            'symbol' => 'VWCE.XETRA', 'currency' => 'EUR',
            'quantity' => 2, 'avg_cost' => 8, 'last_price' => 10,
        ]);
        InstrumentPrice::query()->create([
            'symbol' => 'VWCE.XETRA', 'currency' => 'EUR', 'price' => 12, 'as_of' => '2026-06-27',
        ]);

        app(InvestmentPriceResolver::class)->hydrate(collect([$h]));

        $this->assertSame(12.0, $h->effectivePrice());
        $this->assertSame(24.0, $h->marketValue());
    }

    public function test_converts_quote_currency_to_holding_currency(): void
    {
        // 1 EUR = 1.10 USD; quota in USD, holding in EUR.
        ExchangeRate::query()->create(['date' => '2026-06-27', 'currency' => 'USD', 'rate' => 1.10]);

        $h = $this->holding([
            'symbol' => 'AAPL.US', 'currency' => 'EUR',
            'quantity' => 1, 'avg_cost' => 100, 'last_price' => null,
        ]);
        InstrumentPrice::query()->create([
            'symbol' => 'AAPL.US', 'currency' => 'USD', 'price' => 110, 'as_of' => '2026-06-27',
        ]);

        app(InvestmentPriceResolver::class)->hydrate(collect([$h]));

        // 110 USD → 100 EUR
        $this->assertEqualsWithDelta(100.0, $h->effectivePrice(), 0.001);
    }

    public function test_falls_back_to_manual_then_cost_when_no_quote(): void
    {
        $manual = $this->holding(['symbol' => 'NOQUOTE', 'currency' => 'EUR', 'avg_cost' => 8, 'last_price' => 10]);
        $cost = $this->holding(['symbol' => null, 'currency' => 'EUR', 'avg_cost' => 8, 'last_price' => null]);

        app(InvestmentPriceResolver::class)->hydrate(collect([$manual, $cost]));

        $this->assertSame(10.0, $manual->effectivePrice()); // nessuna quota → prezzo manuale
        $this->assertSame(8.0, $cost->effectivePrice());     // né quota né manuale → costo medio
    }

    public function test_picks_latest_quote_not_after_as_of(): void
    {
        $h = $this->holding(['symbol' => 'VWCE.XETRA', 'currency' => 'EUR', 'quantity' => 1, 'last_price' => 1]);
        InstrumentPrice::query()->create(['symbol' => 'VWCE.XETRA', 'currency' => 'EUR', 'price' => 100, 'as_of' => '2026-06-01']);
        InstrumentPrice::query()->create(['symbol' => 'VWCE.XETRA', 'currency' => 'EUR', 'price' => 200, 'as_of' => '2026-06-20']);

        app(InvestmentPriceResolver::class)->hydrate(collect([$h]), Carbon::parse('2026-06-10'));

        $this->assertSame(100.0, $h->effectivePrice()); // ignora la quota del 20 (> as_of)
    }
}

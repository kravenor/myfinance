<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InvestmentLookupTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function chart(float $price, string $currency): array
    {
        return ['chart' => ['result' => [['meta' => [
            'regularMarketPrice' => $price, 'currency' => $currency,
        ]]]]];
    }

    public function test_lookup_requires_auth(): void
    {
        $this->getJson('/api/investments/lookup?q=IE00B5BMR087')->assertUnauthorized();
    }

    public function test_lookup_requires_query(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/investments/lookup')
            ->assertJsonValidationErrors('q');
    }

    public function test_lookup_resolves_isin_to_symbols_preferring_currency(): void
    {
        Http::fake([
            '*/v1/finance/search*' => Http::response(['quotes' => [
                ['symbol' => 'CSPX.L', 'quoteType' => 'ETF', 'shortname' => 'iShares S&P 500', 'exchDisp' => 'London'],
                ['symbol' => 'CSSPX.MI', 'quoteType' => 'ETF', 'longname' => 'iShares Core S&P 500 UCITS', 'exchDisp' => 'Milan'],
                ['symbol' => '^GSPC', 'quoteType' => 'INDEX', 'shortname' => 'S&P 500'],
            ]]),
            '*/v8/finance/chart/CSSPX.MI*' => Http::response($this->chart(696.41, 'EUR')),
            '*/v8/finance/chart/CSPX.L*' => Http::response($this->chart(794.13, 'USD')),
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/investments/lookup?q=IE00B5BMR087&currency=EUR')
            ->assertOk()
            ->assertJsonCount(2, 'data') // l'INDEX non quotabile è escluso
            ->assertJsonPath('data.0.symbol', 'CSSPX.MI') // valuta preferita in cima
            ->assertJsonPath('data.0.currency', 'EUR')
            ->assertJsonPath('data.0.price', 696.41)
            ->assertJsonPath('data.1.symbol', 'CSPX.L')
            ->assertJsonPath('data.1.currency', 'USD');
    }

    public function test_lookup_returns_empty_when_search_fails(): void
    {
        Http::fake(['*/v1/finance/search*' => Http::response([], 500)]);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/investments/lookup?q=NOPE')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}

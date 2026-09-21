<?php

namespace Tests\Feature\Services;

use App\Services\Prices\TeleborsaProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TeleborsaProviderTest extends TestCase
{
    /** Frammento reale della scheda: `<label>` e valore in `<span id="…">`. */
    private function scheda(?string $reference): string
    {
        $row = fn (string $label, string $value) => <<<HTML
                <div class="scheda-panel--body--data--item">
                    <label>{$label}</label>
                    <span id="ctl00_lblItem">{$value}</span>
                </div>
            HTML;

        $rows = $row('Min-Max oggi', '0,413 - 0,515');
        if ($reference !== null) {
            $rows .= $row('Prezzo di riferimento', $reference);
        }

        return '<html><body>'.$rows.'</body></html>';
    }

    public function test_reads_reference_price_and_date(): void
    {
        Http::fake(['*x-de000vy746g1-REUwMDBWWTc0Nkcx*' => Http::response($this->scheda('0,465 - 18/09/2026'))]);

        $this->assertSame([[
            'symbol' => 'DE000VY746G1',
            'price' => 0.465,
            'currency' => 'EUR',
            'as_of' => '2026-09-18',
        ]], app(TeleborsaProvider::class)->fetch(['DE000VY746G1']));
    }

    public function test_parses_italian_thousands_separator(): void
    {
        Http::fake(['*' => Http::response($this->scheda('1.234,56 - 18/09/2026'))]);

        $this->assertSame(1234.56, app(TeleborsaProvider::class)->fetch(['DE000UR12285'])[0]['price']);
    }

    /** Certificato scaduto o senza scambi: la scheda esce senza il prezzo di riferimento. */
    public function test_skips_page_without_reference_price(): void
    {
        Http::fake(['*' => Http::response($this->scheda(null))]);

        $this->assertSame([], app(TeleborsaProvider::class)->fetch(['DE000SX1Y9K9']));
    }

    public function test_skips_invalid_date(): void
    {
        Http::fake(['*' => Http::response($this->scheda('0,465 - 31/02/2026'))]);

        $this->assertSame([], app(TeleborsaProvider::class)->fetch(['DE000SX1Y9K9']));
    }

    public function test_skips_symbols_that_are_not_isin(): void
    {
        Http::fake();

        $this->assertSame([], app(TeleborsaProvider::class)->fetch(['CSSPX.MI']));
        Http::assertNothingSent();
    }
}

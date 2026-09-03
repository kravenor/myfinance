<?php

namespace Tests\Feature\Services;

use App\Services\Prices\BorsaItalianaProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BorsaItalianaProviderTest extends TestCase
{
    /**
     * Frammento della scheda titolo reale: `<strong>etichetta</strong>` e valore
     * in `<span class="t-text -right">`, con gli a capo e i tab del sito.
     */
    private function scheda(?string $price, ?string $date, string $currency = 'EUR/EUR'): string
    {
        $row = fn (string $label, string $value) => <<<HTML
                <tr>
                    <td class="l-screen -xs-half">
                        <span class="t-text"><strong>{$label}</strong></span>
                    </td>
                    <td>
                        <span class="t-text -right">{$value}</span>
                    </td>
                </tr>
            HTML;

        $rows = $row('Valuta di Negoziazione/ Liquidazione', $currency);
        if ($price !== null) {
            $rows .= $row('Prezzo di riferimento', $price);
        }
        if ($date !== null) {
            $rows .= $row('Data di riferimento', $date);
        }

        return '<html><body><table>'.$rows.'</table></body></html>';
    }

    public function test_reads_reference_price_as_fraction_of_nominal(): void
    {
        Http::fake(['*/IT0005534984.html*' => Http::response($this->scheda('101,51', '02/09/2026'))]);

        $quotes = app(BorsaItalianaProvider::class)->fetch(['IT0005534984']);

        // 101,51% del nominale → 1,0151 per euro nominale, così che
        // quantity (il nominale) * price dia il valore di mercato.
        $this->assertSame([[
            'symbol' => 'IT0005534984',
            'price' => 1.0151,
            'currency' => 'EUR',
            'as_of' => '2026-09-02',
        ]], $quotes);
    }

    public function test_parses_italian_thousands_separator(): void
    {
        Http::fake(['*' => Http::response($this->scheda('1.234,56', '02/09/2026'))]);

        $this->assertSame(12.3456, app(BorsaItalianaProvider::class)->fetch(['IT0003256820'])[0]['price']);
    }

    public function test_reads_currency_from_the_page(): void
    {
        Http::fake(['*' => Http::response($this->scheda('98,68', '02/09/2026', 'USD/USD'))]);

        $this->assertSame('USD', app(BorsaItalianaProvider::class)->fetch(['XS0000000019'])[0]['currency']);
    }

    /** ISIN inesistente: il sito risponde 200 con la pagina priva dei campi prezzo. */
    public function test_skips_page_without_price(): void
    {
        Http::fake(['*' => Http::response($this->scheda(null, null))]);

        $this->assertSame([], app(BorsaItalianaProvider::class)->fetch(['IT0009999999']));
    }

    public function test_skips_invalid_date(): void
    {
        Http::fake(['*' => Http::response($this->scheda('101,51', '31/02/2026'))]);

        $this->assertSame([], app(BorsaItalianaProvider::class)->fetch(['IT0005534984']));
    }

    public function test_skips_symbol_that_is_not_an_isin_without_calling_the_site(): void
    {
        Http::fake();

        $this->assertSame([], app(BorsaItalianaProvider::class)->fetch(['BTP 2033', '']));

        Http::assertNothingSent();
    }

    public function test_skips_symbol_when_the_site_fails(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $this->assertSame([], app(BorsaItalianaProvider::class)->fetch(['IT0005534984']));
    }
}

<?php

namespace Tests\Feature\Services;

use App\Services\Prices\IstatHousePriceProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IstatHousePriceProviderTest extends TestCase
{
    /** Risposta reale SDMX-CSV ridotta, con un valore provvisorio e uno mancante. */
    private const CSV = <<<'CSV'
        DATAFLOW,FREQ,REF_AREA,DATA_TYPE,MEASURE,PURCHASES_DWELLINGS,TIME_PERIOD,OBS_VALUE,OBS_STATUS
        IT1:143_497(1.0),Q,IT,105,4,EXST_DW,2026-Q1,102.5,
        IT1:143_497(1.0),Q,IT,105,4,EXST_DW,2026-Q2,104,p
        IT1:143_497(1.0),Q,ITC45,105,4,EXST_DW,2026-Q2,,
        CSV;

    public function test_reads_the_series_dated_at_quarter_start(): void
    {
        Http::fake(['esploradati.istat.it/*' => Http::response(self::CSV)]);

        $this->assertSame([
            ['symbol' => 'IT', 'price' => 102.5, 'currency' => 'XXX', 'as_of' => '2026-01-01'],
            ['symbol' => 'IT', 'price' => 104.0, 'currency' => 'XXX', 'as_of' => '2026-04-01'],
        ], app(IstatHousePriceProvider::class)->fetch(['IT', 'ITC45']));

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/data/IT1,143_497,1.0/Q.IT+ITC45.105.4.EXST_DW?startPeriod=2010-Q1'));
    }

    public function test_unknown_areas_never_reach_the_url(): void
    {
        Http::fake();

        $this->assertSame([], app(IstatHousePriceProvider::class)->fetch(['../x', 'CSSPX.MI']));
        Http::assertNothingSent();
    }
}

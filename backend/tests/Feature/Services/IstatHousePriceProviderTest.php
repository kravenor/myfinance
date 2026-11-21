<?php

namespace Tests\Feature\Services;

use App\Services\Prices\IstatHousePriceProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IstatHousePriceProviderTest extends TestCase
{
    /** Risposta reale SDMX-CSV ridotta, con un valore provvisorio e uno mancante. */
    private const ISTAT = <<<'CSV'
        DATAFLOW,FREQ,REF_AREA,DATA_TYPE,MEASURE,PURCHASES_DWELLINGS,TIME_PERIOD,OBS_VALUE,OBS_STATUS
        IT1:143_497(1.0),Q,IT,105,4,EXST_DW,2010-Q1,110,
        IT1:143_497(1.0),Q,IT,105,4,EXST_DW,2026-Q2,104,p
        IT1:143_497(1.0),Q,ITC45,105,4,EXST_DW,2026-Q2,,
        CSV;

    private const BIS = <<<'CSV'
        FREQ,REF_AREA,VALUE,UNIT_MEASURE,UNIT_MULT,BREAKS,COVERAGE,TITLE_TS,TIME_PERIOD,OBS_VALUE,OBS_STATUS,OBS_CONF,OBS_PRE_BREAK
        Q,IT,N,628,0,,,,2003-Q1,76,A,F,
        Q,IT,N,628,0,,,,2010-Q1,100,A,F,
        Q,IT,N,628,0,,,,2026-Q2,102.5,A,F,
        CSV;

    public function test_reads_the_series_dated_at_quarter_start_and_extends_it_with_bis(): void
    {
        Http::fake([
            'esploradati.istat.it/*' => Http::response(self::ISTAT),
            'stats.bis.org/*' => Http::response(self::BIS),
        ]);

        $this->assertSame([
            // 110 × 76 / 100: andamento BIS agganciato al primo trimestre ISTAT
            ['symbol' => 'IT', 'price' => 83.6, 'currency' => 'XXX', 'as_of' => '2003-01-01'],
            ['symbol' => 'IT', 'price' => 110.0, 'currency' => 'XXX', 'as_of' => '2010-01-01'],
            ['symbol' => 'IT', 'price' => 104.0, 'currency' => 'XXX', 'as_of' => '2026-04-01'],
        ], app(IstatHousePriceProvider::class)->fetch(['IT', 'ITC45']));

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/data/IT1,143_497,1.0/Q.IT+ITC45.105.4.EXST_DW?startPeriod=2010-Q1'));
    }

    public function test_istat_series_is_kept_when_bis_is_down(): void
    {
        Http::fake([
            'esploradati.istat.it/*' => Http::response(self::ISTAT),
            'stats.bis.org/*' => Http::response('', 503),
        ]);

        $this->assertSame(['2010-01-01', '2026-04-01'], array_column(app(IstatHousePriceProvider::class)->fetch(['IT']), 'as_of'));
    }

    public function test_unknown_areas_never_reach_the_url(): void
    {
        Http::fake();

        $this->assertSame([], app(IstatHousePriceProvider::class)->fetch(['../x', 'CSSPX.MI']));
        Http::assertNothingSent();
    }
}

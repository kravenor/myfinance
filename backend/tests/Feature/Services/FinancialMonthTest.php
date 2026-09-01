<?php

namespace Tests\Feature\Services;

use App\Support\FinancialMonth;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FinancialMonthTest extends TestCase
{
    public function test_start_day_one_is_identical_to_calendar_month(): void
    {
        foreach (['2026-01-01', '2026-02-14', '2026-02-28', '2026-12-31'] as $date) {
            $ref = Carbon::parse($date);
            [$start, $end] = FinancialMonth::range($ref, 1);

            $this->assertSame($ref->copy()->startOfMonth()->toDateTimeString(), $start->toDateTimeString(), $date);
            $this->assertSame($ref->copy()->endOfMonth()->toDateTimeString(), $end->toDateTimeString(), $date);
        }
    }

    public function test_cycle_containing_the_reference_date(): void
    {
        // Il 27 apre il ciclo etichettato con il proprio mese…
        [$start, $end] = FinancialMonth::range(Carbon::parse('2026-06-27'), 27);
        $this->assertSame('2026-06-27 00:00:00', $start->toDateTimeString());
        $this->assertSame('2026-07-26 23:59:59', $end->toDateTimeString());

        // …e i giorni precedenti appartengono ancora al ciclo di maggio.
        [$start, $end] = FinancialMonth::range(Carbon::parse('2026-06-26'), 27);
        $this->assertSame('2026-05-27 00:00:00', $start->toDateTimeString());
        $this->assertSame('2026-06-26 23:59:59', $end->toDateTimeString());
    }

    public function test_february_and_year_boundary(): void
    {
        [$start, $end] = FinancialMonth::range(Carbon::parse('2026-02-28'), 28);
        $this->assertSame('2026-02-28 00:00:00', $start->toDateTimeString());
        $this->assertSame('2026-03-27 23:59:59', $end->toDateTimeString());

        [$start, $end] = FinancialMonth::range(Carbon::parse('2027-01-05'), 27);
        $this->assertSame('2026-12-27 00:00:00', $start->toDateTimeString());
        $this->assertSame('2027-01-26 23:59:59', $end->toDateTimeString());
    }

    public function test_key_and_from_key_round_trip(): void
    {
        $this->assertSame('2026-06', FinancialMonth::key(Carbon::parse('2026-07-10'), 27));
        $this->assertSame('2026-07', FinancialMonth::key(Carbon::parse('2026-07-27'), 27));

        [$start, $end] = FinancialMonth::fromKey('2026-06', 27);
        $this->assertSame('2026-06-27 00:00:00', $start->toDateTimeString());
        $this->assertSame('2026-07-26 23:59:59', $end->toDateTimeString());
    }

    public function test_start_day_is_clamped(): void
    {
        $this->assertSame(28, FinancialMonth::clamp(31));
        $this->assertSame(1, FinancialMonth::clamp(0));
        $this->assertSame(1, FinancialMonth::clamp(null));
    }
}

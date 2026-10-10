<?php

namespace Tests\Feature\Services;

use App\Support\BusinessDay;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BusinessDayTest extends TestCase
{
    public function test_working_day_is_unchanged(): void
    {
        $this->assertSame('2026-10-08', BusinessDay::next(Carbon::parse('2026-10-08'))->toDateString());
    }

    public function test_weekend_moves_to_monday(): void
    {
        $this->assertSame('2026-10-12', BusinessDay::next(Carbon::parse('2026-10-10'))->toDateString());
        $this->assertSame('2026-10-12', BusinessDay::next(Carbon::parse('2026-10-11'))->toDateString());
    }

    public function test_italian_holidays_are_skipped(): void
    {
        // Pasquetta 2026 = 6 aprile; domenica 5 (Pasqua) salta anche il lunedì.
        $this->assertSame('2026-04-07', BusinessDay::next(Carbon::parse('2026-04-05'))->toDateString());
        // 2 giugno (martedì).
        $this->assertSame('2026-06-03', BusinessDay::next(Carbon::parse('2026-06-02'))->toDateString());
        // Natale venerdì 25/12/2026 + Santo Stefano sabato + domenica → lunedì 28.
        $this->assertSame('2026-12-28', BusinessDay::next(Carbon::parse('2026-12-25'))->toDateString());
    }

    public function test_easter_matches_known_dates(): void
    {
        // Pasquetta = Pasqua + 1: il lunedì non è lavorativo, il martedì sì.
        foreach (['2024-04-01', '2025-04-21', '2027-03-29', '2038-04-26'] as $easterMonday) {
            $day = Carbon::parse($easterMonday);
            $this->assertFalse(BusinessDay::isBusinessDay($day), $easterMonday);
            $this->assertTrue(BusinessDay::isBusinessDay($day->copy()->addDay()), $easterMonday);
        }
    }

    public function test_unknown_country_only_skips_weekends(): void
    {
        $this->assertSame('2026-12-25', BusinessDay::next(Carbon::parse('2026-12-25'), 'XX')->toDateString());
    }
}

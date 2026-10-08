<?php

namespace Tests\Feature\Services;

use App\Support\Cadence;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CadenceTest extends TestCase
{
    /** @return array<int, string> */
    private function chain(string $from, string $cadence, int $steps, ?int $anchorDay): array
    {
        $cursor = Carbon::parse($from);
        $out = [];
        for ($i = 0; $i < $steps; $i++) {
            $cursor = Cadence::advance($cursor, $cadence, 1, $anchorDay);
            $out[] = $cursor->toDateString();
        }

        return $out;
    }

    public function test_monthly_returns_to_anchor_day_after_short_months(): void
    {
        $this->assertSame(
            ['2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31'],
            $this->chain('2026-01-31', 'monthly', 4, 31),
        );
    }

    public function test_quarterly_on_the_31st(): void
    {
        $this->assertSame(['2026-04-30', '2026-07-31', '2026-10-31'], $this->chain('2026-01-31', 'quarterly', 3, 31));
    }

    public function test_yearly_on_leap_day(): void
    {
        $this->assertSame(['2029-02-28', '2030-02-28', '2031-02-28', '2032-02-29'], $this->chain('2028-02-29', 'yearly', 4, 29));
    }

    public function test_weekly_ignores_anchor_day(): void
    {
        $this->assertSame(['2026-02-07', '2026-02-14'], $this->chain('2026-01-31', 'weekly', 2, 31));
    }
}

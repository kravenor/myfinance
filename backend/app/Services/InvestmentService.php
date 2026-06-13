<?php

namespace App\Services;

use App\Models\InvestmentHolding;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class InvestmentService
{
    public function __construct(private readonly CurrencyConverter $converter) {}

    /**
     * Riepilogo del portafoglio convertito nella valuta base dell'utente
     * (al tasso corrente): totali, P/L latente, allocazione per asset type e
     * per conto.
     *
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $base = strtoupper(Auth::user()->currency);
        $now = Carbon::now();

        $holdings = InvestmentHolding::query()->with('account')->get();

        $totalMarketValue = 0.0;
        $totalCostBasis = 0.0;
        /** @var array<string, float> $byType */
        $byType = [];
        /** @var array<int, array{account_id: int, name: ?string, currency: ?string, market_value: float, cost_basis: float}> $byAccount */
        $byAccount = [];

        foreach ($holdings as $h) {
            $mv = $this->converter->convert($h->marketValue(), $h->currency, $base, $now);
            $cb = $this->converter->convert($h->costBasis(), $h->currency, $base, $now);

            $totalMarketValue += $mv;
            $totalCostBasis += $cb;

            $byType[$h->asset_type] = ($byType[$h->asset_type] ?? 0.0) + $mv;

            $aid = $h->account_id;
            if (! isset($byAccount[$aid])) {
                $byAccount[$aid] = [
                    'account_id' => $aid,
                    'name' => $h->account?->name,
                    'currency' => $h->account?->currency,
                    'market_value' => 0.0,
                    'cost_basis' => 0.0,
                ];
            }
            $byAccount[$aid]['market_value'] += $mv;
            $byAccount[$aid]['cost_basis'] += $cb;
        }

        $pl = $totalMarketValue - $totalCostBasis;

        return [
            'base_currency' => $base,
            'holdings_count' => $holdings->count(),
            'total_market_value' => $this->fmt($totalMarketValue),
            'total_cost_basis' => $this->fmt($totalCostBasis),
            'total_unrealized_pl' => $this->fmt($pl),
            'total_unrealized_pl_pct' => $totalCostBasis > 0 ? $this->fmt($pl / $totalCostBasis * 100) : null,
            'by_asset_type' => collect($byType)
                ->map(fn ($value, $type) => [
                    'asset_type' => $type,
                    'market_value' => $this->fmt($value),
                    'pct' => $totalMarketValue > 0 ? $this->fmt($value / $totalMarketValue * 100) : '0.00',
                ])
                ->sortByDesc(fn ($row) => (float) $row['market_value'])
                ->values()
                ->all(),
            'accounts' => collect($byAccount)
                ->map(fn ($a) => [
                    'account_id' => $a['account_id'],
                    'name' => $a['name'],
                    'currency' => $a['currency'],
                    'market_value' => $this->fmt($a['market_value']),
                    'cost_basis' => $this->fmt($a['cost_basis']),
                    'unrealized_pl' => $this->fmt($a['market_value'] - $a['cost_basis']),
                ])
                ->sortByDesc(fn ($row) => (float) $row['market_value'])
                ->values()
                ->all(),
        ];
    }

    private function fmt(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}

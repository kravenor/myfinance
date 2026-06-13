<?php

namespace App\Http\Resources;

use App\Models\InvestmentHolding;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin InvestmentHolding
 */
class InvestmentHoldingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $costBasis = $this->costBasis();
        $marketValue = $this->marketValue();
        $pl = $marketValue - $costBasis;

        return [
            'id' => $this->id,
            'account_id' => $this->account_id,
            'name' => $this->name,
            'symbol' => $this->symbol,
            'asset_type' => $this->asset_type,
            'currency' => $this->currency,
            'quantity' => $this->quantity,
            'avg_cost' => $this->avg_cost,
            'last_price' => $this->last_price,
            'last_price_at' => $this->last_price_at?->toIso8601String(),
            'notes' => $this->notes,
            // Valori calcolati nella valuta dell'holding.
            'cost_basis' => $this->money($costBasis),
            'market_value' => $this->money($marketValue),
            'unrealized_pl' => $this->money($pl),
            'unrealized_pl_pct' => $costBasis > 0 ? $this->money($pl / $costBasis * 100) : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}

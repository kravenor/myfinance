<?php

namespace App\Http\Resources;

use App\Models\InvestmentTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin InvestmentTransaction
 */
class InvestmentTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'investment_holding_id' => $this->investment_holding_id,
            'side' => $this->side,
            'occurred_at' => $this->occurred_at->toDateString(),
            'quantity' => $this->quantity,
            'price' => $this->price,
            'fees' => $this->fees,
            'notes' => $this->notes,
            // Cassa mossa dal movimento, nella valuta dell'holding.
            'cash_flow' => number_format($this->cashFlow(), 2, '.', ''),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

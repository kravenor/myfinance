<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecurringTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_id' => $this->account_id,
            'category_id' => $this->category_id,
            'transfer_account_id' => $this->transfer_account_id,
            'type' => $this->type,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'description' => $this->description,
            'cadence' => $this->cadence,
            'interval' => $this->interval,
            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'next_run_at' => $this->next_run_at?->toDateString(),
            'last_run_at' => $this->last_run_at?->toDateString(),
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

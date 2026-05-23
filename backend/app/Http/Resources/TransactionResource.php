<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
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
            'recurring_transaction_id' => $this->recurring_transaction_id,
            'type' => $this->type,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'occurred_at' => $this->occurred_at?->toDateString(),
            'description' => $this->description,
            'notes' => $this->notes,
            'external_id' => $this->external_id,
            'tags' => TagResource::collection($this->whenLoaded('tags')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

<?php

namespace App\Http\Resources;

use App\Models\SavingsGoalMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SavingsGoalMovement
 */
class SavingsGoalMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'savings_goal_id' => $this->savings_goal_id,
            'account_id' => $this->account_id,
            'direction' => $this->direction,
            'amount' => $this->amount,
            'occurred_at' => $this->occurred_at->toDateString(),
            'note' => $this->note,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

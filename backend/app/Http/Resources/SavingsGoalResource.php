<?php

namespace App\Http\Resources;

use App\Models\SavingsGoal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SavingsGoal
 *
 * @property string|null $saved
 * @property float|null $progress
 * @property string|null $remaining
 * @property string|null $period_start
 * @property string|null $period_end
 * @property array<string, mixed>|null $pace
 */
class SavingsGoalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'target_amount' => $this->target_amount,
            'currency' => $this->currency,
            'account_id' => $this->account_id,
            'target_date' => $this->target_date?->toDateString(),
            'recurrence' => $this->recurrence,
            'start_date' => $this->start_date?->toDateString(),
            'color' => $this->color,
            'icon' => $this->icon,
            'status' => $this->status,
            'notes' => $this->notes,
            'saved' => $this->when($this->saved !== null, fn () => $this->saved),
            'progress' => $this->when($this->progress !== null, fn () => $this->progress),
            'remaining' => $this->when($this->remaining !== null, fn () => $this->remaining),
            'period_start' => $this->when($this->saved !== null, fn () => $this->period_start),
            'period_end' => $this->when($this->saved !== null, fn () => $this->period_end),
            'pace' => $this->when($this->saved !== null, fn () => $this->pace),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

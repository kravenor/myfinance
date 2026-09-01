<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Support\FinancialMonth;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'currency' => $this->currency,
            'locale' => $this->locale,
            'date_format' => $this->date_format,
            'month_start_day' => FinancialMonth::clamp($this->month_start_day),
            'notification_preferences' => $this->notificationPreferences(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

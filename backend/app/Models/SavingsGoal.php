<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $target_amount
 * @property string $currency
 * @property Carbon|null $target_date
 * @property string|null $color
 * @property string|null $icon
 * @property string $status
 * @property string|null $notes
 * @property-read Collection<int, SavingsGoalMovement> $movements
 */
class SavingsGoal extends Model
{
    use BelongsToUser, HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'target_amount',
        'currency',
        'target_date',
        'color',
        'icon',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'target_amount' => 'decimal:2',
            'target_date' => 'date',
        ];
    }

    public function movements(): HasMany
    {
        return $this->hasMany(SavingsGoalMovement::class);
    }
}

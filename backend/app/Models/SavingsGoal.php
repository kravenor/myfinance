<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $target_amount
 * @property string $currency
 * @property int|null $account_id
 * @property Carbon|null $target_date
 * @property string $recurrence
 * @property Carbon|null $start_date
 * @property string|null $color
 * @property string|null $icon
 * @property string $status
 * @property string|null $notes
 */
class SavingsGoal extends Model
{
    use BelongsToUser, HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'target_amount',
        'currency',
        'account_id',
        'target_date',
        'recurrence',
        'start_date',
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
            'start_date' => 'date',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}

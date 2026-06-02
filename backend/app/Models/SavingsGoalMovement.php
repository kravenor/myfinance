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
 * @property int $savings_goal_id
 * @property int|null $account_id
 * @property string $direction
 * @property string $amount
 * @property Carbon $occurred_at
 * @property string|null $note
 * @property-read SavingsGoal $savingsGoal
 * @property-read Account|null $account
 */
class SavingsGoalMovement extends Model
{
    use BelongsToUser, HasFactory;

    protected $fillable = [
        'user_id',
        'savings_goal_id',
        'account_id',
        'direction',
        'amount',
        'occurred_at',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'occurred_at' => 'date',
        ];
    }

    public function savingsGoal(): BelongsTo
    {
        return $this->belongsTo(SavingsGoal::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $account_id
 * @property int|null $category_id
 * @property int|null $transfer_account_id
 * @property string $type
 * @property string $amount
 * @property string $currency
 * @property string|null $description
 * @property string $cadence
 * @property int $interval
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 * @property Carbon $next_run_at
 * @property Carbon|null $last_run_at
 * @property bool $is_active
 */
class RecurringTransaction extends Model
{
    use BelongsToUser, HasFactory;

    protected $fillable = [
        'user_id',
        'account_id',
        'category_id',
        'transfer_account_id',
        'type',
        'amount',
        'currency',
        'description',
        'cadence',
        'interval',
        'starts_on',
        'ends_on',
        'next_run_at',
        'last_run_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'interval' => 'integer',
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
            'next_run_at' => 'date:Y-m-d',
            'last_run_at' => 'date:Y-m-d',
            'is_active' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function transferAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'transfer_account_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}

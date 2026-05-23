<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

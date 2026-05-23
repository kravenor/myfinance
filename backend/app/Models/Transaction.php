<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Transaction extends Model
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
        'occurred_at',
        'description',
        'notes',
        'external_id',
        'recurring_transaction_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'occurred_at' => 'date:Y-m-d',
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

    public function recurringTransaction(): BelongsTo
    {
        return $this->belongsTo(RecurringTransaction::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }
}

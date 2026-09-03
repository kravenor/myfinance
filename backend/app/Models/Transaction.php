<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $account_id
 * @property int|null $category_id
 * @property int|null $transfer_account_id
 * @property int|null $recurring_transaction_id
 * @property string $type
 * @property bool $is_adjustment
 * @property string $amount
 * @property string|null $transfer_amount
 * @property string $currency
 * @property Carbon $occurred_at
 * @property string|null $description
 * @property string|null $notes
 * @property string|null $external_id
 */
class Transaction extends Model
{
    use BelongsToUser, HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_adjustment' => false,
    ];

    protected $fillable = [
        'user_id',
        'account_id',
        'category_id',
        'transfer_account_id',
        'type',
        'is_adjustment',
        'amount',
        'transfer_amount',
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
            'transfer_amount' => 'decimal:2',
            'occurred_at' => 'date:Y-m-d',
            'is_adjustment' => 'boolean',
        ];
    }

    /**
     * Le rettifiche di riconciliazione tengono in piedi il **saldo** ma non
     * sono entrate o uscite vere: vanno escluse da ogni statistica (totali,
     * per categoria, per tag, timeline, trend, speso dei budget). I saldi e il
     * patrimonio netto invece le contano, altrimenti la riconciliazione non
     * servirebbe a niente. Colonna qualificata: alcune query fanno join.
     *
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    public function scopeExcludingAdjustments(Builder $query): Builder
    {
        return $query->where('transactions.is_adjustment', false);
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

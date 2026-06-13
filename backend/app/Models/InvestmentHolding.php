<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Posizione (holding) su un asset detenuto in un conto di tipo investment.
 * Prezzo corrente inserito manualmente (`last_price`); architettura pronta a
 * un eventuale auto-fetch per `symbol`.
 *
 * @property int $id
 * @property int $user_id
 * @property int $account_id
 * @property string $name
 * @property string|null $symbol
 * @property string $asset_type
 * @property string $currency
 * @property string $quantity
 * @property string $avg_cost
 * @property string|null $last_price
 * @property Carbon|null $last_price_at
 * @property string|null $notes
 * @property-read Account|null $account
 */
class InvestmentHolding extends Model
{
    use BelongsToUser, HasFactory;

    protected $fillable = [
        'user_id',
        'account_id',
        'name',
        'symbol',
        'asset_type',
        'currency',
        'quantity',
        'avg_cost',
        'last_price',
        'last_price_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:8',
            'avg_cost' => 'decimal:8',
            'last_price' => 'decimal:8',
            'last_price_at' => 'datetime',
        ];
    }

    /**
     * Prezzo da usare per il valore di mercato: `last_price` se presente,
     * altrimenti il costo medio (parità in assenza di quotazione).
     */
    public function effectivePrice(): float
    {
        return (float) ($this->last_price ?? $this->avg_cost);
    }

    public function marketValue(): float
    {
        return (float) $this->quantity * $this->effectivePrice();
    }

    public function costBasis(): float
    {
        return (float) $this->quantity * (float) $this->avg_cost;
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Posizione (holding) su un asset detenuto in un conto di tipo investment.
 * Il prezzo effettivo segue la precedenza: quotazione automatica risolta
 * (da `instrument_prices`, via InvestmentPriceResolver) → `last_price` manuale
 * → costo medio.
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

    /**
     * Prezzo da quotazione automatica (valuta dell'holding), impostato in modo
     * transitorio da InvestmentPriceResolver::hydrate(); null se non risolto.
     */
    private ?float $resolvedPrice = null;

    /** Data (Y-m-d) della quotazione automatica risolta; null se non risolta. */
    private ?string $resolvedAsOf = null;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:8',
            'avg_cost' => 'decimal:8',
            'last_price' => 'decimal:8',
            'last_price_at' => 'datetime',
        ];
    }

    public function usingResolvedPrice(?float $price, ?string $asOf = null): static
    {
        $this->resolvedPrice = $price;
        $this->resolvedAsOf = $asOf;

        return $this;
    }

    public function resolvedPrice(): ?float
    {
        return $this->resolvedPrice;
    }

    public function resolvedAsOf(): ?string
    {
        return $this->resolvedAsOf;
    }

    /**
     * Origine del prezzo effettivo: 'auto' (quotazione risolta) → 'manual'
     * (`last_price`) → 'cost' (costo medio). Stessa precedenza di effectivePrice().
     */
    public function priceSource(): string
    {
        if ($this->resolvedPrice !== null) {
            return 'auto';
        }

        return $this->last_price !== null ? 'manual' : 'cost';
    }

    /**
     * Prezzo per il valore di mercato, in ordine di precedenza: quotazione
     * automatica risolta → `last_price` manuale → costo medio (parità).
     */
    public function effectivePrice(): float
    {
        return (float) ($this->resolvedPrice ?? $this->last_price ?? $this->avg_cost);
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

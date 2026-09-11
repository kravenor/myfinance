<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Movimento del registro di un holding: acquisto (anche la rata di un PAC),
 * vendita o costo puro (`fee`: bollo, custodia, gestione — non muove quote).
 * La posizione dell'holding — quantity, avg_cost, realized_pl,
 * net_invested — è derivata da questi movimenti via HoldingPositionRecalculator.
 *
 * @property int $id
 * @property int $user_id
 * @property int $investment_holding_id
 * @property string $side
 * @property Carbon $occurred_at
 * @property string $quantity
 * @property string $price
 * @property string $fees
 * @property string|null $notes
 * @property-read InvestmentHolding|null $holding
 */
class InvestmentTransaction extends Model
{
    use BelongsToUser, HasFactory;

    protected $fillable = [
        'user_id',
        'investment_holding_id',
        'side',
        'occurred_at',
        'quantity',
        'price',
        'fees',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'date',
            'quantity' => 'decimal:8',
            'price' => 'decimal:8',
            'fees' => 'decimal:2',
        ];
    }

    /** Controvalore del movimento al lordo delle commissioni. */
    public function grossAmount(): float
    {
        return (float) $this->quantity * (float) $this->price;
    }

    /**
     * Cassa mossa dal movimento nella valuta dell'holding: quanto è uscito per
     * un acquisto (commissioni incluse) o per un costo, quanto è rientrato per
     * una vendita (commissioni escluse).
     */
    public function cashFlow(): float
    {
        return match ($this->side) {
            'sell' => $this->grossAmount() - (float) $this->fees,
            'fee' => (float) $this->fees,
            default => $this->grossAmount() + (float) $this->fees,
        };
    }

    public function holding(): BelongsTo
    {
        return $this->belongsTo(InvestmentHolding::class, 'investment_holding_id');
    }
}

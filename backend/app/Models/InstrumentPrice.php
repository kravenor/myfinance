<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Quotazione di chiusura (EOD) di uno strumento identificato dal `symbol`.
 * Dato globale (non scoped per utente), sullo stesso modello di ExchangeRate:
 * accumula una riga per (symbol, as_of). Il `price` è nella valuta `currency`
 * (la valuta nativa di quotazione), non necessariamente quella dell'holding.
 *
 * @property int $id
 * @property string $symbol
 * @property string $currency
 * @property string $price
 * @property Carbon $as_of
 */
class InstrumentPrice extends Model
{
    use HasFactory;

    protected $fillable = [
        'symbol',
        'currency',
        'price',
        'as_of',
    ];

    protected function casts(): array
    {
        return [
            'as_of' => 'date:Y-m-d',
            'price' => 'decimal:8',
        ];
    }
}

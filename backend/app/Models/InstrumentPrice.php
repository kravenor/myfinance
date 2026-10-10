<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Quotazione di chiusura (EOD) di uno strumento identificato da (`provider`, `symbol`).
 * Dato globale (non scoped per utente), sullo stesso modello di ExchangeRate:
 * accumula una riga per (provider, symbol, as_of). Il provider è nella chiave perché
 * lo stesso simbolo può indicare strumenti diversi su provider diversi. Il `price` è nella valuta `currency`
 * (la valuta nativa di quotazione), non necessariamente quella dell'holding.
 *
 * @property int $id
 * @property string $provider
 * @property string $symbol
 * @property string $currency
 * @property string $price
 * @property Carbon $as_of
 */
class InstrumentPrice extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider',
        'symbol',
        'currency',
        'price',
        'as_of',
    ];

    /** Chiave con cui i lettori raggruppano le quote: mai il solo simbolo. */
    public static function key(?string $provider, ?string $symbol): string
    {
        return $provider.'|'.$symbol;
    }

    protected function casts(): array
    {
        return [
            'as_of' => 'date:Y-m-d',
            'price' => 'decimal:8',
        ];
    }
}

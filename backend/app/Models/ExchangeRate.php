<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Tasso di cambio giornaliero rispetto alla valuta pivot (config `finance.pivot_currency`).
 * Dato globale (non scoped per utente).
 *
 * @property int $id
 * @property Carbon $date
 * @property string $currency
 * @property string $rate
 */
class ExchangeRate extends Model
{
    use HasFactory;

    protected $fillable = [
        'date',
        'currency',
        'rate',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'rate' => 'decimal:10',
        ];
    }
}

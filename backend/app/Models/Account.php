<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $type
 * @property string $currency
 * @property string $initial_balance
 * @property string|null $color
 * @property string|null $icon
 * @property bool $is_archived
 * @property bool $include_in_net_worth
 * @property string|null $notes
 */
class Account extends Model
{
    use BelongsToUser, HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'type',
        'currency',
        'initial_balance',
        'color',
        'icon',
        'is_archived',
        'include_in_net_worth',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'initial_balance' => 'decimal:2',
            'is_archived' => 'boolean',
            'include_in_net_worth' => 'boolean',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}

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
        'is_primary',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'initial_balance' => 'decimal:2',
            'is_archived' => 'boolean',
            'include_in_net_worth' => 'boolean',
            'is_primary' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Account $account) {
            if (! $account->exists && ! $account->is_primary) {
                $hasPrimary = static::withoutGlobalScopes()
                    ->where('user_id', $account->user_id)
                    ->where('is_primary', true)
                    ->exists();

                if (! $hasPrimary) {
                    $account->is_primary = true;
                }
            }

            if ($account->is_primary) {
                static::withoutGlobalScopes()
                    ->where('user_id', $account->user_id)
                    ->where('id', '!=', $account->id ?? 0)
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }
        });

        static::saved(function (Account $account) {
            if (! $account->is_primary) {
                $hasPrimary = static::withoutGlobalScopes()
                    ->where('user_id', $account->user_id)
                    ->where('is_primary', true)
                    ->exists();

                if (! $hasPrimary) {
                    $replacement = static::withoutGlobalScopes()
                        ->where('user_id', $account->user_id)
                        ->where('id', '!=', $account->id)
                        ->orderBy('created_at')
                        ->first();

                    if ($replacement) {
                        $replacement->is_primary = true;
                        $replacement->saveQuietly();
                    }
                }
            }
        });

        static::deleted(function (Account $account) {
            if ($account->is_primary) {
                $replacement = static::withoutGlobalScopes()
                    ->where('user_id', $account->user_id)
                    ->orderBy('created_at')
                    ->first();

                if ($replacement) {
                    $replacement->is_primary = true;
                    $replacement->saveQuietly();
                }
            }
        });
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $scenario_id
 * @property string $type
 * @property int|null $account_id
 * @property int|null $category_id
 * @property string|null $description
 * @property string $amount
 * @property string $currency
 * @property string $cadence
 * @property int $interval
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 * @property-read Scenario $scenario
 * @property-read Account|null $account
 * @property-read Category|null $category
 */
class ScenarioItem extends Model
{
    use BelongsToUser, HasFactory;

    /**
     * Il default del DB non arriva al model appena creato: senza questo la
     * risposta del POST avrebbe `type: null` fino al primo reload.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => 'expense',
    ];

    protected $fillable = [
        'user_id',
        'scenario_id',
        'type',
        'account_id',
        'category_id',
        'description',
        'amount',
        'currency',
        'cadence',
        'interval',
        'starts_on',
        'ends_on',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'interval' => 'integer',
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
        ];
    }

    public function scenario(): BelongsTo
    {
        return $this->belongsTo(Scenario::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}

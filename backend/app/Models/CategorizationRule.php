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
 * @property int $category_id
 * @property string $name
 * @property string $match_type
 * @property string $pattern
 * @property string $applies_to_type
 * @property int $priority
 * @property bool $is_active
 * @property int $times_applied
 * @property Carbon|null $last_applied_at
 */
class CategorizationRule extends Model
{
    use BelongsToUser, HasFactory;

    protected $fillable = [
        'user_id',
        'category_id',
        'name',
        'match_type',
        'pattern',
        'applies_to_type',
        'priority',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'is_active' => 'boolean',
            'times_applied' => 'integer',
            'last_applied_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}

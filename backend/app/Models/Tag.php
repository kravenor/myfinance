<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string|null $color
 */
class Tag extends Model
{
    use BelongsToUser, HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'color',
    ];

    public function transactions(): BelongsToMany
    {
        return $this->belongsToMany(Transaction::class);
    }
}

<?php

namespace App\Models\Concerns;

use App\Models\Scopes\UserScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

trait BelongsToUser
{
    public static function bootBelongsToUser(): void
    {
        static::addGlobalScope(new UserScope);

        static::creating(function ($model) {
            if (! $model->getAttribute('user_id') && Auth::check()) {
                $model->setAttribute('user_id', Auth::id());
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

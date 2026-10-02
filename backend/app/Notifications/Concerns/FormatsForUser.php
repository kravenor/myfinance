<?php

namespace App\Notifications\Concerns;

use App\Models\User;
use Illuminate\Support\Carbon;

trait FormatsForUser
{
    /** Data nel formato scelto dall'utente in Impostazioni (lo stesso che vede nell'app). */
    protected function userDate(object $notifiable, Carbon|string $date): string
    {
        $format = $notifiable instanceof User && $notifiable->date_format ? $notifiable->date_format : 'd/m/Y';

        return Carbon::parse($date)->format($format);
    }
}

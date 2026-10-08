<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Eventi di sicurezza (accessi, 2FA, password) sul canale `security`: file giornaliero
 * separato, conservato LOG_SECURITY_DAYS giorni. Mai password, codici o token nel contesto.
 */
final class SecurityLog
{
    /** @param  array<string, mixed>  $context */
    public static function record(string $event, ?int $userId = null, array $context = []): void
    {
        $request = request();

        Log::channel('security')->info($event, array_filter([
            'user_id' => $userId ?? Auth::id(),
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 200, ''),
            'route' => $request->route()?->getName(),
            ...$context,
        ], fn ($value) => $value !== null && $value !== ''));
    }
}

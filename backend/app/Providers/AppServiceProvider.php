<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Tetto generale delle API: largo per l'uso normale (le pagine fanno più chiamate in parallelo),
        // ferma i loop. Le rotte costose hanno un throttle proprio.
        // Le email riportano descrizioni importate dalla banca: niente link markdown o HTML costruiti da quel testo.
        Markdown::withSecuredEncoding();

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(300)->by($request->user()?->id ?: $request->ip()));

        // Il link di reset password punta alla rotta SPA del frontend.
        ResetPassword::createUrlUsing(function (object $notifiable, string $token): string {
            $base = rtrim((string) config('app.frontend_url'), '/');
            $email = method_exists($notifiable, 'getEmailForPasswordReset')
                ? $notifiable->getEmailForPasswordReset()
                : (string) $notifiable->getAttribute('email');

            return $base.'/reset-password?token='.$token.'&email='.urlencode($email);
        });
    }
}

<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public const TWO_FACTOR_SESSION_KEY = 'login.two_factor';

    public const TWO_FACTOR_TTL_MINUTES = 5;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Con la 2FA attiva la password non basta: nessun login, l'utente in attesa
     * va in sessione e lo completa la challenge. Ritorna false in quel caso.
     */
    public function authenticate(): bool
    {
        $this->ensureIsNotRateLimited();

        /** @var SessionGuard $guard */
        $guard = Auth::guard('web');

        if (! $guard->validate($this->only('email', 'password'))) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        /** @var User $user */
        $user = $guard->getLastAttempted();

        if ($user->hasTwoFactor()) {
            if (! $this->hasSession()) {
                throw ValidationException::withMessages(['email' => 'Accesso con verifica in due passaggi disponibile solo dall\'app web.']);
            }

            $this->session()->put(self::TWO_FACTOR_SESSION_KEY, [
                'id' => $user->id,
                'remember' => $this->boolean('remember'),
                'expires' => now()->addMinutes(self::TWO_FACTOR_TTL_MINUTES)->getTimestamp(),
            ]);

            return false;
        }

        $guard->login($user, $this->boolean('remember'));

        return true;
    }

    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower((string) $this->input('email')).'|'.$this->ip());
    }
}

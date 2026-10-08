<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Support\SecurityLog;
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

    /** Limite per sola email: ferma il brute force distribuito su più IP contro lo stesso account. */
    private const EMAIL_MAX_ATTEMPTS = 10;

    private const EMAIL_DECAY_SECONDS = 900;

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
            $attempted = $guard->getLastAttempted(); // null se l'email non esiste
            SecurityLog::record('login.failed', $attempted instanceof User ? $attempted->id : null, ['email' => Str::lower((string) $this->input('email'))]);
            RateLimiter::hit($this->throttleKey());
            RateLimiter::hit($this->emailThrottleKey(), self::EMAIL_DECAY_SECONDS);

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        RateLimiter::clear($this->emailThrottleKey());

        /** @var User $user */
        $user = $guard->getLastAttempted();

        if ($user->hasTwoFactor()) {
            if (! $this->hasSession()) {
                throw ValidationException::withMessages(['email' => 'Accesso con verifica in due passaggi disponibile solo dall\'app web.']);
            }

            // Password giusta: se il codice non arriva mai, qualcuno la conosce.
            SecurityLog::record('login.two_factor_required', $user->id);
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
        $key = match (true) {
            RateLimiter::tooManyAttempts($this->throttleKey(), 5) => $this->throttleKey(),
            RateLimiter::tooManyAttempts($this->emailThrottleKey(), self::EMAIL_MAX_ATTEMPTS) => $this->emailThrottleKey(),
            default => null,
        };
        if ($key === null) {
            return;
        }

        event(new Lockout($this));
        SecurityLog::record('login.lockout', null, ['email' => Str::lower((string) $this->input('email'))]);

        $seconds = RateLimiter::availableIn($key);

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

    private function emailThrottleKey(): string
    {
        return 'login-email:'.Str::transliterate(Str::lower((string) $this->input('email')));
    }
}

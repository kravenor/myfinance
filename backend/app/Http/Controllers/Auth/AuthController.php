<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\UpdatePasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\TwoFactorAuthenticator;
use App\Support\FinancialMonth;
use Database\Seeders\CategorySeeder;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const TWO_FACTOR_MAX_ATTEMPTS = 5;

    private const TWO_FACTOR_LOCKOUT_SECONDS = 900;

    private const SECOND_FACTOR_RULES = [
        'code' => ['nullable', 'string', 'max:10', 'required_without:recovery_code'],
        'recovery_code' => ['nullable', 'string', 'max:20'],
    ];

    public function registrationStatus(): JsonResponse
    {
        return response()->json(['enabled' => (bool) config('finance.registration')]);
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'currency' => $data['currency'] ?? 'EUR',
                'locale' => $data['locale'] ?? 'it',
            ]);

            (new CategorySeeder)->seedFor($user);

            return $user;
        });

        Auth::login($user);

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return (new UserResource($user))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function login(LoginRequest $request): UserResource|JsonResponse
    {
        if (! $request->authenticate()) {
            return response()->json(['two_factor' => true]);
        }

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        /** @var User $user */
        $user = $request->user();

        return new UserResource($user);
    }

    /**
     * Invia il link di reset password. Risposta generica per non rivelare l'esistenza dell'email.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $status = Password::sendResetLink($request->only('email'));

        return response()->json(['message' => __($status)]);
    }

    /**
     * Reimposta la password a partire dal token ricevuto via email.
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->setRememberToken(Str::random(60));

                $user->save();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PasswordReset) {
            return response()->json(['message' => __($status)]);
        }

        return response()->json(['message' => __($status)], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->rotateRememberToken($request, $user, ['password' => Hash::make($request->validated('password'))]);

        return response()->json(['message' => 'Password aggiornata.']);
    }

    /**
     * Secondo passo del login: l'utente arriva solo dalla sessione scritta da LoginRequest, mai dal body.
     */
    public function twoFactorChallenge(Request $request, TwoFactorAuthenticator $twoFactor): UserResource
    {
        $data = $request->validate(self::SECOND_FACTOR_RULES);

        $pending = $request->hasSession() ? $request->session()->get(LoginRequest::TWO_FACTOR_SESSION_KEY) : null;
        $user = is_array($pending) && $pending['expires'] >= now()->getTimestamp() ? User::find($pending['id']) : null;

        if (! $user?->hasTwoFactor()) {
            if ($request->hasSession()) {
                $request->session()->forget(LoginRequest::TWO_FACTOR_SESSION_KEY);
            }

            // Su «email»: il client torna al primo passaggio.
            throw ValidationException::withMessages(['email' => 'Accesso scaduto: inserisci di nuovo email e password.']);
        }

        $this->verifySecondFactor($user, $data, $twoFactor);

        $request->session()->forget(LoginRequest::TWO_FACTOR_SESSION_KEY);
        Auth::guard('web')->login($user, (bool) $pending['remember']);
        $request->session()->regenerate();

        return new UserResource($user);
    }

    public function enableTwoFactor(Request $request, TwoFactorAuthenticator $twoFactor): JsonResponse
    {
        $request->validate(['current_password' => ['required', 'current_password']]);

        /** @var User $user */
        $user = $request->user();
        abort_if($user->hasTwoFactor(), Response::HTTP_CONFLICT, 'La verifica in due passaggi è già attiva.');

        return response()->json($twoFactor->enable($user));
    }

    public function confirmTwoFactor(Request $request, TwoFactorAuthenticator $twoFactor): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);

        /** @var User $user */
        $user = $request->user();
        abort_if($user->hasTwoFactor(), Response::HTTP_CONFLICT, 'La verifica in due passaggi è già attiva.');

        $codes = $twoFactor->confirm($user, $data['code']);
        if ($codes === null) {
            throw ValidationException::withMessages(['code' => 'Codice non valido.']);
        }

        // Come al cambio password: i dispositivi con «Ricordami» devono rientrare passando dal codice.
        $this->rotateRememberToken($request, $user);

        return response()->json(['recovery_codes' => $codes]);
    }

    // Password e codice: chi ha una sessione aperta e la password non basta a spegnere la 2FA.
    public function disableTwoFactor(Request $request, TwoFactorAuthenticator $twoFactor): Response
    {
        $data = $request->validate(['current_password' => ['required', 'current_password'], ...self::SECOND_FACTOR_RULES]);

        /** @var User $user */
        $user = $request->user();
        if ($user->hasTwoFactor()) {
            $this->verifySecondFactor($user, $data, $twoFactor);
        }
        $twoFactor->disable($user);

        return response()->noContent();
    }

    public function regenerateRecoveryCodes(Request $request, TwoFactorAuthenticator $twoFactor): JsonResponse
    {
        $request->validate(['current_password' => ['required', 'current_password']]);

        /** @var User $user */
        $user = $request->user();
        abort_unless($user->hasTwoFactor(), Response::HTTP_CONFLICT, 'La verifica in due passaggi non è attiva.');

        return response()->json(['recovery_codes' => $twoFactor->regenerateRecoveryCodes($user)]);
    }

    public function updatePreferences(Request $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'date_format' => ['sometimes', 'string', Rule::in(config('finance.date_formats'))],
            'month_start_day' => ['sometimes', 'integer', 'between:1,'.FinancialMonth::MAX_START_DAY],
        ]);

        $user->update($data);

        return new UserResource($user);
    }

    public function logout(Request $request): Response
    {
        // Solo questo dispositivo: logout() rigenererebbe il remember_token, unico per utente,
        // e farebbe perdere il «Ricordami» a tutti gli altri dispositivi (es. la PWA).
        /** @var SessionGuard $guard */
        $guard = Auth::guard('web');
        $guard->logoutCurrentDevice();
        Auth::guard('sanctum')->forgetUser();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }

    public function me(Request $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();

        return new UserResource($user);
    }

    /**
     * Codice dell'app o di recupero. Limite per utente oltre al throttle della rotta:
     * chi ha la password non può provare codici in parallelo da tanti indirizzi.
     *
     * @param  array<string, mixed>  $data
     */
    private function verifySecondFactor(User $user, array $data, TwoFactorAuthenticator $twoFactor): void
    {
        $field = isset($data['recovery_code']) ? 'recovery_code' : 'code';
        $limiterKey = 'two-factor:'.$user->id;

        if (RateLimiter::tooManyAttempts($limiterKey, self::TWO_FACTOR_MAX_ATTEMPTS)) {
            $minutes = (int) ceil(RateLimiter::availableIn($limiterKey) / 60);
            throw ValidationException::withMessages([$field => "Troppi codici errati: riprova tra {$minutes} minuti."]);
        }

        $valid = $field === 'recovery_code'
            ? $twoFactor->useRecoveryCode($user, (string) $data['recovery_code'])
            : $twoFactor->verify($user, (string) $data['code']);

        if (! $valid) {
            RateLimiter::hit($limiterKey, self::TWO_FACTOR_LOCKOUT_SECONDS);
            throw ValidationException::withMessages([$field => 'Codice non valido.']);
        }

        RateLimiter::clear($limiterKey);
    }

    /**
     * Nuovo remember_token: gli altri dispositivi perdono il «Ricordami» alla scadenza della loro sessione.
     * Questo dispositivo resta collegato e, se usava «Ricordami», riceve il cookie con il token nuovo.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function rotateRememberToken(Request $request, User $user, array $attributes = []): void
    {
        /** @var SessionGuard $guard */
        $guard = Auth::guard('web');
        $remembered = $request->cookies->has($guard->getRecallerName());

        $user->forceFill([...$attributes, 'remember_token' => Str::random(60)])->save();

        $guard->login($user, $remembered);
    }
}

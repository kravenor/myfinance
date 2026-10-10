<?php

namespace Tests\Feature\Auth;

use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\TwoFactorAuthenticator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    // Come la SPA: richiesta stateful (Referer), così Sanctum avvia la sessione.
    private function spa(): static
    {
        return $this->withHeader('Referer', 'http://localhost')->withCredentials();
    }

    private function otp(User $user): string
    {
        return app(Google2FA::class)->getCurrentOtp((string) $user->fresh()->two_factor_secret);
    }

    /**
     * @return array{0: User, 1: list<string>}
     */
    private function userWithTwoFactor(): array
    {
        $user = User::factory()->create(['password' => Hash::make('Password123!')]);
        $twoFactor = app(TwoFactorAuthenticator::class);
        $twoFactor->enable($user);
        $codes = $twoFactor->confirm($user, $this->otp($user));
        // Il codice di conferma ha già bruciato l'intervallo corrente: i test ripartono da zero.
        $user->forceFill(['two_factor_last_timestep' => null])->save();

        return [$user->fresh(), $codes];
    }

    private function pending(User $user, bool $remember = false, int $expiresIn = 300): array
    {
        return [LoginRequest::TWO_FACTOR_SESSION_KEY => ['id' => $user->id, 'remember' => $remember, 'expires' => now()->getTimestamp() + $expiresIn]];
    }

    public function test_enable_and_confirm_returns_recovery_codes_once(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Password123!')]);

        $this->spa()->actingAs($user)->postJson('/api/auth/two-factor', ['current_password' => 'sbagliata'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->spa()->actingAs($user)->postJson('/api/auth/two-factor', ['current_password' => 'Password123!'])
            ->assertOk()->assertJsonStructure(['secret', 'otpauth_url', 'qr_svg']);
        $this->assertFalse($user->fresh()->hasTwoFactor());

        $this->spa()->actingAs($user)->postJson('/api/auth/two-factor/confirm', ['code' => '000000'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');

        $response = $this->spa()->actingAs($user)->postJson('/api/auth/two-factor/confirm', ['code' => $this->otp($user)])
            ->assertOk()->assertJsonCount(TwoFactorAuthenticator::RECOVERY_CODES, 'recovery_codes');

        $user->refresh();
        $this->assertTrue($user->hasTwoFactor());
        $this->assertNotContains($response->json('recovery_codes.0'), $user->two_factor_recovery_codes);
        $this->spa()->actingAs($user)->getJson('/api/auth/me')
            ->assertJsonPath('data.two_factor_enabled', true)
            ->assertJsonMissingPath('data.two_factor_secret');
    }

    public function test_login_without_two_factor_is_unchanged(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Password123!')]);

        $this->spa()->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'Password123!'])
            ->assertOk()->assertJsonPath('data.id', $user->id);
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_login_with_two_factor_stops_before_authenticating(): void
    {
        [$user] = $this->userWithTwoFactor();

        $this->spa()->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'Password123!', 'remember' => true])
            ->assertOk()->assertExactJson(['two_factor' => true])
            ->assertSessionHas(LoginRequest::TWO_FACTOR_SESSION_KEY.'.id', $user->id);
        $this->assertGuest('web');
    }

    public function test_challenge_with_valid_code_logs_in_and_keeps_remember(): void
    {
        [$user] = $this->userWithTwoFactor();

        $response = $this->spa()->withSession($this->pending($user, remember: true))
            ->postJson('/api/auth/two-factor-challenge', ['code' => $this->otp($user)])
            ->assertOk()->assertJsonPath('data.id', $user->id)
            ->assertSessionMissing(LoginRequest::TWO_FACTOR_SESSION_KEY);

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotNull($response->getCookie(Auth::guard('web')->getRecallerName()));
    }

    public function test_challenge_rejects_wrong_and_reused_codes(): void
    {
        [$user] = $this->userWithTwoFactor();
        $code = $this->otp($user);

        $this->spa()->withSession($this->pending($user))
            ->postJson('/api/auth/two-factor-challenge', ['code' => $code === '123456' ? '654321' : '123456'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');

        $twoFactor = app(TwoFactorAuthenticator::class);
        $this->assertTrue($twoFactor->verify($user, $code));
        $this->assertFalse($twoFactor->verify($user->fresh(), $code));
    }

    public function test_recovery_code_works_once(): void
    {
        [$user, $codes] = $this->userWithTwoFactor();

        $this->spa()->withSession($this->pending($user))
            ->postJson('/api/auth/two-factor-challenge', ['recovery_code' => $codes[0]])
            ->assertOk();
        $this->assertCount(TwoFactorAuthenticator::RECOVERY_CODES - 1, $user->fresh()->two_factor_recovery_codes);

        Auth::guard('web')->logout();
        $this->spa()->withSession($this->pending($user))
            ->postJson('/api/auth/two-factor-challenge', ['recovery_code' => $codes[0]])
            ->assertUnprocessable()->assertJsonValidationErrors('recovery_code');
    }

    public function test_challenge_without_pending_login_or_expired_is_rejected(): void
    {
        [$user] = $this->userWithTwoFactor();

        $this->spa()->postJson('/api/auth/two-factor-challenge', ['code' => $this->otp($user)])
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->spa()->withSession($this->pending($user, expiresIn: -1))
            ->postJson('/api/auth/two-factor-challenge', ['code' => $this->otp($user)])
            ->assertUnprocessable();

        $this->assertGuest('web');
    }

    public function test_confirm_rotates_the_remember_token(): void
    {
        $user = User::factory()->create(['remember_token' => 'token-vecchio']);
        app(TwoFactorAuthenticator::class)->enable($user);

        $this->spa()->actingAs($user)->postJson('/api/auth/two-factor/confirm', ['code' => $this->otp($user)])->assertOk();

        $this->assertNotSame('token-vecchio', $user->fresh()->remember_token);
    }

    public function test_regenerate_and_disable_require_password_and_second_factor(): void
    {
        [$user, $codes] = $this->userWithTwoFactor();

        $this->spa()->actingAs($user)->postJson('/api/auth/two-factor/recovery-codes', ['current_password' => 'sbagliata', 'code' => $this->otp($user)])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        // La sola password non basta: codici nuovi valgono come accesso senza telefono.
        $this->spa()->actingAs($user)->postJson('/api/auth/two-factor/recovery-codes', ['current_password' => 'Password123!'])
            ->assertJsonValidationErrors('code');
        $fresh = $this->spa()->actingAs($user)->postJson('/api/auth/two-factor/recovery-codes', ['current_password' => 'Password123!', 'code' => $this->otp($user)])
            ->assertOk()->json('recovery_codes');
        $this->assertNotEquals($codes, $fresh);

        $this->spa()->actingAs($user)->deleteJson('/api/auth/two-factor', ['current_password' => 'sbagliata', 'code' => $this->otp($user)])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->assertTrue($user->fresh()->hasTwoFactor());
    }

    public function test_disable_requires_a_valid_second_factor(): void
    {
        [$user, $codes] = $this->userWithTwoFactor();
        $wrong = $this->otp($user) === '123456' ? '654321' : '123456';

        $this->spa()->actingAs($user)->deleteJson('/api/auth/two-factor', ['current_password' => 'Password123!'])
            ->assertJsonValidationErrors('code');
        $this->spa()->actingAs($user)->deleteJson('/api/auth/two-factor', ['current_password' => 'Password123!', 'code' => $wrong])
            ->assertJsonValidationErrors(['code' => 'Codice non valido.']);
        $this->assertTrue($user->fresh()->hasTwoFactor());

        $this->spa()->actingAs($user)->deleteJson('/api/auth/two-factor', ['current_password' => 'Password123!', 'recovery_code' => $codes[0]])
            ->assertNoContent();
        $this->assertFalse($user->fresh()->hasTwoFactor());
    }

    public function test_console_command_disables_two_factor(): void
    {
        [$user] = $this->userWithTwoFactor();

        $this->artisan('user:two-factor-disable', ['email' => $user->email])->assertSuccessful();
        $this->artisan('user:two-factor-disable', ['email' => 'nessuno@example.com'])->assertFailed();

        $this->assertFalse($user->fresh()->hasTwoFactor());
    }

    public function test_challenge_is_limited_per_user_across_ips(): void
    {
        [$user] = $this->userWithTwoFactor();
        $wrong = $this->otp($user) === '123456' ? '654321' : '123456';

        // Un IP per tentativo: il throttle della rotta non scatta, quello per utente sì.
        for ($i = 1; $i <= 5; $i++) {
            $this->spa()->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$i}"])->withSession($this->pending($user))
                ->postJson('/api/auth/two-factor-challenge', ['code' => $wrong])
                ->assertJsonValidationErrors(['code' => 'Codice non valido.']);
        }

        $this->spa()->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])->withSession($this->pending($user))
            ->postJson('/api/auth/two-factor-challenge', ['code' => $this->otp($user)])
            ->assertUnprocessable();
        $this->assertGuest('web');
    }

    public function test_a_stale_model_cannot_reuse_a_code(): void
    {
        [$user, $codes] = $this->userWithTwoFactor();
        $twoFactor = app(TwoFactorAuthenticator::class);
        // Due istanze caricate prima di qualsiasi uso: come due richieste parallele.
        $first = $user->fresh();
        $second = $user->fresh();
        $code = $this->otp($user);

        $this->assertTrue($twoFactor->verify($first, $code));
        $this->assertFalse($twoFactor->verify($second, $code));

        $this->assertTrue($twoFactor->useRecoveryCode($first, $codes[0]));
        $this->assertFalse($twoFactor->useRecoveryCode($second, $codes[0]));
    }
}

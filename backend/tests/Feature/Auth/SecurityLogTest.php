<?php

namespace Tests\Feature\Auth;

use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\TwoFactorAuthenticator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use PragmaRX\Google2FA\Google2FA;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class SecurityLogTest extends TestCase
{
    use RefreshDatabase;

    private function securityLog(): MockInterface
    {
        $logger = Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')->with('security')->andReturn($logger);

        return $logger;
    }

    /** Il contesto non deve mai contenere segreti. */
    private function hasNoSecrets(array $context): bool
    {
        return array_intersect(array_keys($context), ['password', 'current_password', 'code', 'recovery_code', 'token']) === [];
    }

    public function test_failed_and_successful_logins_are_recorded_without_secrets(): void
    {
        $user = User::factory()->create(['email' => 'mario@example.com', 'password' => Hash::make('Password123!')]);
        $log = $this->securityLog();

        $this->postJson('/api/auth/login', ['email' => 'mario@example.com', 'password' => 'sbagliata'])->assertUnprocessable();
        $this->postJson('/api/auth/login', ['email' => 'mario@example.com', 'password' => 'Password123!'])->assertOk();

        $log->shouldHaveReceived('info')->with('login.failed', Mockery::on(fn (array $c) => $c['email'] === 'mario@example.com'
            && $c['user_id'] === $user->id && isset($c['ip']) && $this->hasNoSecrets($c)))->once();
        $log->shouldHaveReceived('info')->with('login.success', Mockery::on(fn (array $c) => $c['user_id'] === $user->id
            && $c['route'] === 'auth.login' && $this->hasNoSecrets($c)))->once();
    }

    public function test_wrong_two_factor_code_is_recorded(): void
    {
        $user = User::factory()->create();
        $twoFactor = app(TwoFactorAuthenticator::class);
        $twoFactor->enable($user);
        $twoFactor->confirm($user, app(Google2FA::class)->getCurrentOtp((string) $user->fresh()->two_factor_secret));
        $log = $this->securityLog();

        $this->withHeader('Referer', 'http://localhost')->withCredentials()
            ->withSession([LoginRequest::TWO_FACTOR_SESSION_KEY => ['id' => $user->id, 'remember' => false, 'expires' => now()->getTimestamp() + 300]])
            ->postJson('/api/auth/two-factor-challenge', ['code' => '000000'])
            ->assertUnprocessable();

        $log->shouldHaveReceived('info')->with('two_factor.failed', Mockery::on(fn (array $c) => $c['user_id'] === $user->id
            && $c['method'] === 'code' && $this->hasNoSecrets($c)))->once();
    }

    public function test_password_change_is_recorded(): void
    {
        $user = User::factory()->create(['password' => Hash::make('OldPassword123!')]);
        $log = $this->securityLog();

        $this->actingAs($user)->putJson('/api/auth/password', [
            'current_password' => 'OldPassword123!',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ])->assertOk();

        $log->shouldHaveReceived('info')->with('password.changed', Mockery::on(fn (array $c) => $c['user_id'] === $user->id && $this->hasNoSecrets($c)))->once();
        $log->shouldNotHaveReceived('info', ['login.success', Mockery::any()]);
    }
}

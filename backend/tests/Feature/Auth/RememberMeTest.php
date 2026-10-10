<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

// Il remember_token è unico per utente e condiviso dai dispositivi (browser, PWA):
// uscire da uno non deve scollegare gli altri; cambiare password sì.
class RememberMeTest extends TestCase
{
    use RefreshDatabase;

    private function recaller(): string
    {
        return Auth::guard('web')->getRecallerName();
    }

    // Come la SPA: richiesta stateful (Referer) con i cookie, compreso quello di «Ricordami».
    private function fromRememberedDevice(User $user): static
    {
        return $this->withHeader('Referer', 'http://localhost')->withCredentials()
            ->actingAs($user)->withCookie($this->recaller(), 'cookie-del-dispositivo');
    }

    public function test_logout_keeps_the_remember_token_of_other_devices(): void
    {
        $user = User::factory()->create(['remember_token' => 'token-condiviso']);

        $this->fromRememberedDevice($user)
            ->postJson('/api/auth/logout')
            ->assertNoContent()
            // Su questo dispositivo il cookie viene comunque eliminato.
            ->assertCookieExpired($this->recaller());

        $this->assertSame('token-condiviso', $user->fresh()->remember_token);
    }

    public function test_password_change_rotates_the_token_and_keeps_this_device_remembered(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Password123!'), 'remember_token' => 'token-vecchio']);
        $payload = ['current_password' => 'Password123!', 'password' => 'NuovaPassword456!', 'password_confirmation' => 'NuovaPassword456!'];

        $response = $this->fromRememberedDevice($user)->putJson('/api/auth/password', $payload)->assertOk();

        $token = $user->fresh()->remember_token;
        $this->assertNotSame('token-vecchio', $token);
        $this->assertStringContainsString($token, $response->getCookie($this->recaller())->getValue());
    }

    public function test_password_change_without_remember_does_not_issue_a_recaller(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Password123!')]);

        $this->withHeader('Referer', 'http://localhost')->withCredentials()->actingAs($user)
            ->putJson('/api/auth/password', ['current_password' => 'Password123!', 'password' => 'NuovaPassword456!', 'password_confirmation' => 'NuovaPassword456!'])
            ->assertOk()
            ->assertCookieMissing($this->recaller());
    }

    public function test_background_commands_keep_the_remember_token(): void
    {
        $user = User::factory()->create(['remember_token' => 'token-del-telefono']);

        // notifications:scan gira ogni mattina: prima rigenerava il token di tutti gli utenti.
        $this->artisan('notifications:scan')->assertSuccessful();
        $this->artisan('rules:apply --dry-run')->assertSuccessful();

        $this->assertSame('token-del-telefono', $user->fresh()->remember_token);
    }

    public function test_logout_other_devices_revokes_their_sessions_and_remember_cookies(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Password123!'), 'remember_token' => 'token-condiviso']);
        // Un altro dispositivo: sessione con l'hash della password di adesso.
        $otherDevice = ['password_hash_web' => Auth::guard('web')->hashPasswordForCookie($user->password)];

        $this->fromRememberedDevice($user)->postJson('/api/auth/logout-other-devices', ['current_password' => 'sbagliata'])
            ->assertJsonValidationErrors('current_password');
        $this->assertSame('token-condiviso', $user->fresh()->remember_token);

        $this->fromRememberedDevice($user)->postJson('/api/auth/logout-other-devices', ['current_password' => 'Password123!'])
            ->assertNoContent()
            // Questo dispositivo resta ricordato, con il token nuovo.
            ->assertCookie($this->recaller());

        $fresh = $user->fresh();
        $this->assertNotSame('token-condiviso', $fresh->remember_token);
        $this->assertTrue(Hash::check('Password123!', $fresh->password));

        Auth::forgetGuards();
        $this->withHeader('Referer', 'http://localhost')->withCredentials()->withSession($otherDevice)
            ->actingAs($fresh)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_remember_cookie_lasts_ninety_days(): void
    {
        User::factory()->create(['email' => 'mario@example.com', 'password' => Hash::make('Password123!')]);

        $this->postJson('/api/auth/login', ['email' => 'mario@example.com', 'password' => 'Password123!', 'remember' => true])->assertOk();

        $expires = Cookie::queued($this->recaller())->getExpiresTime();
        $this->assertEqualsWithDelta(now()->addMinutes(60 * 24 * 90)->getTimestamp(), $expires, 60);
    }
}

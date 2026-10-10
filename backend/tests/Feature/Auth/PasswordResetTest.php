<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_sends_reset_link_for_existing_email(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])
            ->assertOk();

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_forgot_password_answers_the_same_for_unknown_and_throttled_email(): void
    {
        Notification::fake();

        $known = User::factory()->create();
        $expected = $this->postJson('/api/auth/forgot-password', ['email' => $known->email])->json('message');

        $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.com'])
            ->assertOk()->assertJsonPath('message', $expected);
        // Un secondo invio per la stessa email è in throttle nel broker: il messaggio non cambia.
        $this->postJson('/api/auth/forgot-password', ['email' => $known->email])
            ->assertOk()->assertJsonPath('message', $expected);

        Notification::assertSentToTimes($known, ResetPassword::class, 1);
    }

    public function test_reset_password_with_valid_token_updates_password(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
    }

    public function test_reset_password_with_invalid_token_fails(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/auth/reset-password', [
            'token' => 'wrong-token',
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertStatus(422)->assertJsonPath('message', __('passwords.token'));

        // Email inesistente: stessa risposta del token errato.
        $this->postJson('/api/auth/reset-password', [
            'token' => 'wrong-token',
            'email' => 'nobody@example.com',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertStatus(422)->assertJsonPath('message', __('passwords.token'));
    }

    public function test_reset_password_requires_confirmation(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'mismatch-999',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_password_reset_endpoints_are_rate_limited(): void
    {
        Notification::fake();

        foreach (range(1, 5) as $i) {
            $this->postJson('/api/auth/forgot-password', ['email' => "user{$i}@example.com"])->assertOk();
        }

        $this->postJson('/api/auth/forgot-password', ['email' => 'user6@example.com'])->assertTooManyRequests();

        // throttle senza nome conta per IP su tutte le rotte anonime: si aspetta il reset della finestra.
        $this->travel(61)->seconds();

        foreach (range(1, 5) as $i) {
            $this->postJson('/api/auth/reset-password', ['token' => 'x', 'email' => 'a@example.com'])->assertUnprocessable();
        }

        $this->postJson('/api/auth/reset-password', ['token' => 'x', 'email' => 'a@example.com'])->assertTooManyRequests();
    }
}

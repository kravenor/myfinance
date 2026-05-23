<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'mario@example.com',
            'password' => Hash::make('Password123!'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'mario@example.com',
            'password' => 'Password123!',
        ]);

        $response->assertOk()->assertJsonPath('data.email', 'mario@example.com');
        $this->assertAuthenticatedAs($user);
    }

    public function test_user_cannot_login_with_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'mario@example.com',
            'password' => Hash::make('Password123!'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'mario@example.com',
            'password' => 'wrong',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertGuest();
    }

    public function test_login_requires_email_and_password(): void
    {
        $response = $this->postJson('/api/auth/login', []);

        $response->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
    }
}

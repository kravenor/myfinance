<?php

namespace Tests\Feature\Auth;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Mario Rossi',
            'email' => 'mario@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.email', 'mario@example.com')
            ->assertJsonPath('data.currency', 'EUR')
            ->assertJsonPath('data.locale', 'it');

        $this->assertDatabaseHas('users', ['email' => 'mario@example.com']);

        $user = User::where('email', 'mario@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);

        $this->assertGreaterThan(
            0,
            Category::withoutGlobalScopes()->where('user_id', $user->id)->count(),
            'Default categories should be seeded for new user',
        );
    }

    public function test_email_must_be_unique(): void
    {
        User::factory()->create(['email' => 'dup@example.com']);

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Mario',
            'email' => 'dup@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_password_must_be_confirmed(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Mario',
            'email' => 'mario@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'wrong',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('password');
    }
}

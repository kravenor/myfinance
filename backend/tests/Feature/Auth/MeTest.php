<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_authenticated_user(): void
    {
        $user = User::factory()->create([
            'name' => 'Mario',
            'email' => 'mario@example.com',
            'currency' => 'EUR',
            'locale' => 'it',
        ]);

        $response = $this->actingAs($user)->getJson('/api/auth/me');

        $response->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', 'mario@example.com');
    }

    public function test_guest_cannot_access_me(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }
}

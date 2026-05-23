<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/auth/logout');

        $response->assertNoContent();
        $this->assertGuest();
    }

    public function test_guest_cannot_logout(): void
    {
        $response = $this->postJson('/api/auth/logout');

        $response->assertUnauthorized();
    }
}

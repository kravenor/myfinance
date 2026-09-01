<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DateFormatPreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_is_exposed_and_can_be_updated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.date_format', 'd/m/Y');

        $this->actingAs($user)
            ->putJson('/api/auth/preferences', ['date_format' => 'Y-m-d'])
            ->assertOk()
            ->assertJsonPath('data.date_format', 'Y-m-d');

        $this->assertSame('Y-m-d', $user->fresh()->date_format);
    }

    public function test_format_outside_whitelist_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->putJson('/api/auth/preferences', ['date_format' => 'd/m/Y; DROP'])
            ->assertStatus(422);
    }

    public function test_requires_authentication(): void
    {
        $this->putJson('/api/auth/preferences', ['date_format' => 'Y-m-d'])->assertStatus(401);
    }
}

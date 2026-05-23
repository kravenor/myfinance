<?php

namespace Tests\Feature\Api;

use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TagTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_creates_tag(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/tags', ['name' => 'viaggi'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'viaggi');
    }

    public function test_unique_name_per_user(): void
    {
        $user = User::factory()->create();
        Tag::factory()->for($user)->create(['name' => 'casa']);

        $this->actingAs($user)
            ->postJson('/api/tags', ['name' => 'casa'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_same_name_allowed_across_users(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        Tag::factory()->for($other)->create(['name' => 'viaggi']);

        $this->actingAs($user)
            ->postJson('/api/tags', ['name' => 'viaggi'])
            ->assertCreated();
    }

    public function test_update_and_destroy(): void
    {
        $user = User::factory()->create();
        $tag = Tag::factory()->for($user)->create(['name' => 'old']);

        $this->actingAs($user)
            ->patchJson("/api/tags/{$tag->id}", ['name' => 'new'])
            ->assertOk()
            ->assertJsonPath('data.name', 'new');

        $this->actingAs($user)
            ->deleteJson("/api/tags/{$tag->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
    }
}

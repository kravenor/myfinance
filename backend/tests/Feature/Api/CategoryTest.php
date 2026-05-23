<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_requires_auth(): void
    {
        $this->getJson('/api/categories')->assertUnauthorized();
    }

    public function test_store_creates_category(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/categories', [
                'name' => 'Spesa alimentare',
                'type' => 'expense',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Spesa alimentare');

        $this->assertDatabaseHas('categories', [
            'user_id' => $user->id,
            'name' => 'Spesa alimentare',
        ]);
    }

    public function test_store_rejects_parent_with_different_type(): void
    {
        $user = User::factory()->create();
        $parent = Category::factory()->for($user)->create(['type' => 'income']);

        $this->actingAs($user)
            ->postJson('/api/categories', [
                'name' => 'Sotto',
                'type' => 'expense',
                'parent_id' => $parent->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_id']);
    }

    public function test_store_rejects_parent_of_other_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $parent = Category::factory()->for($other)->create(['type' => 'expense']);

        $this->actingAs($user)
            ->postJson('/api/categories', [
                'name' => 'Sub',
                'type' => 'expense',
                'parent_id' => $parent->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_id']);
    }

    public function test_update_rejects_self_as_parent(): void
    {
        $user = User::factory()->create();
        $cat = Category::factory()->for($user)->create(['type' => 'expense']);

        $this->actingAs($user)
            ->patchJson("/api/categories/{$cat->id}", ['parent_id' => $cat->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_id']);
    }

    public function test_destroy_deletes_category(): void
    {
        $user = User::factory()->create();
        $cat = Category::factory()->for($user)->create();

        $this->actingAs($user)
            ->deleteJson("/api/categories/{$cat->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('categories', ['id' => $cat->id]);
    }
}

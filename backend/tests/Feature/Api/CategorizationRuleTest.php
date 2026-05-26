<?php

namespace Tests\Feature\Api;

use App\Models\CategorizationRule;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategorizationRuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_requires_auth(): void
    {
        $this->getJson('/api/categorization-rules')->assertUnauthorized();
    }

    public function test_store_creates_rule(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create(['type' => 'expense']);

        $this->actingAs($user)
            ->postJson('/api/categorization-rules', [
                'category_id' => $category->id,
                'name' => 'Esselunga',
                'match_type' => 'contains',
                'pattern' => 'esselunga',
                'applies_to_type' => 'expense',
                'priority' => 50,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Esselunga')
            ->assertJsonPath('data.category.id', $category->id);
    }

    public function test_cannot_attach_rule_to_other_user_category(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $foreignCategory = Category::factory()->for($other)->create();

        $this->actingAs($user)
            ->postJson('/api/categorization-rules', [
                'category_id' => $foreignCategory->id,
                'name' => 'x',
                'match_type' => 'contains',
                'pattern' => 'x',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id']);
    }

    public function test_invalid_regex_rejected(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create();

        $this->actingAs($user)
            ->postJson('/api/categorization-rules', [
                'category_id' => $category->id,
                'name' => 'broken',
                'match_type' => 'regex',
                'pattern' => '[unclosed',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pattern']);
    }

    public function test_index_is_scoped_to_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        CategorizationRule::factory()->for($user)->for(Category::factory()->for($user))->create();
        CategorizationRule::factory()->for($other)->for(Category::factory()->for($other))->create();

        $this->actingAs($user)
            ->getJson('/api/categorization-rules')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_update_and_destroy(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create();
        $rule = CategorizationRule::factory()->for($user)->for($category)->create([
            'name' => 'old',
        ]);

        $this->actingAs($user)
            ->patchJson("/api/categorization-rules/{$rule->id}", ['name' => 'new'])
            ->assertOk()
            ->assertJsonPath('data.name', 'new');

        $this->actingAs($user)
            ->deleteJson("/api/categorization-rules/{$rule->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('categorization_rules', ['id' => $rule->id]);
    }

    public function test_other_user_cannot_update_rule(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $category = Category::factory()->for($owner)->create();
        $rule = CategorizationRule::factory()->for($owner)->for($category)->create();

        $this->actingAs($intruder)
            ->patchJson("/api/categorization-rules/{$rule->id}", ['name' => 'hack'])
            ->assertNotFound();
    }
}

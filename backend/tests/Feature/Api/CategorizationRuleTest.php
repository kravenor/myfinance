<?php

namespace Tests\Feature\Api;

use App\Models\CategorizationRule;
use App\Models\Category;
use App\Models\User;
use App\Services\CategorizationRuleMatcher;
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

    public function test_regex_rules_are_capped_per_user(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create();
        CategorizationRule::factory()->for($user)->for($category)->count(CategorizationRule::MAX_REGEX_RULES)
            ->create(['match_type' => 'regex', 'pattern' => 'x']);
        $payload = ['category_id' => $category->id, 'name' => 'r', 'match_type' => 'regex', 'pattern' => 'y'];

        $this->actingAs($user)->postJson('/api/categorization-rules', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors(['match_type']);
        $this->actingAs($user)->postJson('/api/categorization-rules', ['match_type' => 'contains'] + $payload)
            ->assertCreated();
        $contains = CategorizationRule::query()->where('match_type', 'contains')->firstOrFail();
        $this->actingAs($user)->patchJson("/api/categorization-rules/{$contains->id}", ['match_type' => 'regex'])
            ->assertUnprocessable()->assertJsonValidationErrors(['match_type']);
    }

    public function test_catastrophic_regex_is_cheap_and_skipped_after_its_first_error(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create(['type' => 'expense']);
        CategorizationRule::factory()->for($user)->for($category)->create([
            'match_type' => 'regex', 'pattern' => '(a+)+$', 'applies_to_type' => 'any', 'priority' => 0, 'is_active' => true,
        ]);
        $fallback = CategorizationRule::factory()->for($user)->for($category)->create([
            'match_type' => 'contains', 'pattern' => 'aaa', 'applies_to_type' => 'any', 'priority' => 1, 'is_active' => true,
        ]);
        $this->actingAs($user);
        $matcher = app(CategorizationRuleMatcher::class);
        $limit = ini_get('pcre.backtrack_limit');

        $start = hrtime(true);
        for ($i = 0; $i < 1000; $i++) {
            $this->assertSame($fallback->id, $matcher->match(str_repeat('a', 40).'!', 'expense')?->id);
        }

        // Prima del limite: ~2 ms a valutazione, oltre 2 s per 1000 righe.
        $this->assertLessThan(1.0, (hrtime(true) - $start) / 1e9);
        $this->assertSame($limit, ini_get('pcre.backtrack_limit'));
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

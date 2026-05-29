<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\CategorizationRule;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RetroactiveRuleApplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_matches_but_does_not_update(): void
    {
        [$user, $account, $category] = $this->scenario();

        Transaction::factory()->for($user)->for($account)->create(['description' => 'ESSELUNGA via Roma', 'type' => 'expense', 'category_id' => null]);
        Transaction::factory()->for($user)->for($account)->create(['description' => 'ESSELUNGA centro', 'type' => 'expense', 'category_id' => null]);
        Transaction::factory()->for($user)->for($account)->create(['description' => 'Sconosciuto', 'type' => 'expense', 'category_id' => null]);

        CategorizationRule::factory()->for($user)->for($category)->create([
            'match_type' => 'contains', 'pattern' => 'esselunga', 'applies_to_type' => 'any',
        ]);

        $this->actingAs($user)
            ->postJson('/api/categorization-rules/apply', ['dry_run' => true])
            ->assertOk()
            ->assertJsonPath('data.matched', 2)
            ->assertJsonPath('data.updated', 0);

        $this->assertSame(3, Transaction::query()->whereNull('category_id')->count());
        $this->assertDatabaseHas('categorization_rules', ['pattern' => 'esselunga', 'times_applied' => 0]);
    }

    public function test_commit_updates_matching_transactions_and_increments_times_applied(): void
    {
        [$user, $account, $category] = $this->scenario();

        Transaction::factory()->for($user)->for($account)->create(['description' => 'ESSELUNGA via Roma', 'type' => 'expense', 'category_id' => null]);
        Transaction::factory()->for($user)->for($account)->create(['description' => 'ESSELUNGA centro', 'type' => 'expense', 'category_id' => null]);
        Transaction::factory()->for($user)->for($account)->create(['description' => 'Sconosciuto', 'type' => 'expense', 'category_id' => null]);

        $rule = CategorizationRule::factory()->for($user)->for($category)->create([
            'match_type' => 'contains', 'pattern' => 'esselunga', 'applies_to_type' => 'any',
        ]);

        $this->actingAs($user)
            ->postJson('/api/categorization-rules/apply', ['dry_run' => false])
            ->assertOk()
            ->assertJsonPath('data.matched', 2)
            ->assertJsonPath('data.updated', 2);

        $this->assertSame(2, Transaction::query()->where('category_id', $category->id)->count());
        $this->assertSame(2, (int) $rule->fresh()->times_applied);
    }

    public function test_only_uncategorized_false_overwrites_existing_category(): void
    {
        [$user, $account, $category] = $this->scenario();
        $other = Category::factory()->for($user)->create(['type' => 'expense']);

        Transaction::factory()->for($user)->for($account)->create([
            'description' => 'ESSELUNGA', 'type' => 'expense', 'category_id' => $other->id,
        ]);

        CategorizationRule::factory()->for($user)->for($category)->create([
            'match_type' => 'contains', 'pattern' => 'esselunga', 'applies_to_type' => 'any',
        ]);

        $this->actingAs($user)
            ->postJson('/api/categorization-rules/apply', ['dry_run' => false, 'only_uncategorized' => false])
            ->assertOk()
            ->assertJsonPath('data.updated', 1);

        $this->assertSame(1, Transaction::query()->where('category_id', $category->id)->count());
    }

    public function test_account_filter_is_respected(): void
    {
        [$user, $account, $category] = $this->scenario();
        $other = Account::factory()->for($user)->create();

        Transaction::factory()->for($user)->for($account)->create(['description' => 'ESSELUNGA', 'type' => 'expense', 'category_id' => null]);
        Transaction::factory()->for($user)->for($other)->create(['description' => 'ESSELUNGA', 'type' => 'expense', 'category_id' => null]);

        CategorizationRule::factory()->for($user)->for($category)->create([
            'match_type' => 'contains', 'pattern' => 'esselunga', 'applies_to_type' => 'any',
        ]);

        $this->actingAs($user)
            ->postJson('/api/categorization-rules/apply', ['dry_run' => false, 'account_id' => $account->id])
            ->assertOk()
            ->assertJsonPath('data.updated', 1);
    }

    /**
     * @return array{0: User, 1: Account, 2: Category}
     */
    private function scenario(): array
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $category = Category::factory()->for($user)->create(['type' => 'expense']);

        return [$user, $account, $category];
    }
}

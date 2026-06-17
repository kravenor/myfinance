<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\Scenario;
use App\Models\ScenarioItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScenarioTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_requires_auth(): void
    {
        $this->getJson('/api/scenarios')->assertUnauthorized();
    }

    public function test_store_creates_scenario(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/scenarios', [
                'name' => 'Vacanza 2026',
                'description' => 'Estate',
                'color' => '#ff0',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Vacanza 2026')
            ->assertJsonPath('data.is_active', true);
    }

    public function test_cannot_access_other_users_scenario(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $scenario = Scenario::factory()->for($owner)->create();

        $this->actingAs($other)
            ->getJson("/api/scenarios/{$scenario->id}")
            ->assertNotFound();
    }

    public function test_destroy_removes_scenario_and_items(): void
    {
        $user = User::factory()->create();
        $scenario = Scenario::factory()->for($user)->create();
        ScenarioItem::factory()->for($user)->for($scenario)->create();

        $this->actingAs($user)
            ->deleteJson("/api/scenarios/{$scenario->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('scenarios', ['id' => $scenario->id]);
        $this->assertDatabaseCount('scenario_items', 0);
    }

    public function test_nested_item_create(): void
    {
        $user = User::factory()->create();
        $scenario = Scenario::factory()->for($user)->create();
        $account = Account::factory()->for($user)->create();

        $this->actingAs($user)
            ->postJson("/api/scenarios/{$scenario->id}/items", [
                'amount' => 500,
                'cadence' => 'one_time',
                'starts_on' => '2026-07-15',
                'description' => 'Volo aereo',
                'account_id' => $account->id,
                'currency' => 'USD',
            ])
            ->assertCreated()
            ->assertJsonPath('data.amount', '500.00')
            ->assertJsonPath('data.cadence', 'one_time')
            ->assertJsonPath('data.account_id', $account->id)
            ->assertJsonPath('data.currency', 'USD');
    }

    public function test_nested_item_rejects_cross_scenario_access(): void
    {
        $user = User::factory()->create();
        $a = Scenario::factory()->for($user)->create();
        $b = Scenario::factory()->for($user)->create();
        $item = ScenarioItem::factory()->for($user)->for($b)->create();

        $this->actingAs($user)
            ->getJson("/api/scenarios/{$a->id}/items/{$item->id}")
            ->assertNotFound();
    }
}

<?php

namespace Tests\Feature\Api;

use App\Models\SavingsGoal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SavingsGoalTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_requires_auth(): void
    {
        $this->getJson('/api/savings-goals')->assertUnauthorized();
    }

    public function test_store_creates_goal_with_zero_progress(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/savings-goals', [
                'name' => 'Vacanza',
                'target_amount' => 2000,
                'target_date' => '2026-12-31',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Vacanza')
            ->assertJsonPath('data.target_amount', '2000.00')
            ->assertJsonPath('data.saved', '0.00')
            ->assertJsonPath('data.progress', 0)
            ->assertJsonPath('data.remaining', '2000.00')
            ->assertJsonPath('data.pace.target_date', '2026-12-31');
    }

    public function test_store_rejects_non_positive_target(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/savings-goals', ['name' => 'X', 'target_amount' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['target_amount']);
    }

    public function test_pace_absent_without_target_date(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/savings-goals', ['name' => 'Fondo', 'target_amount' => 500])
            ->assertCreated()
            ->assertJsonPath('data.pace', null);
    }

    public function test_update_changes_fields(): void
    {
        $user = User::factory()->create();
        $goal = SavingsGoal::factory()->for($user)->create(['name' => 'Vecchio']);

        $this->actingAs($user)
            ->patchJson("/api/savings-goals/{$goal->id}", ['name' => 'Nuovo', 'status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Nuovo')
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_destroy_removes_goal(): void
    {
        $user = User::factory()->create();
        $goal = SavingsGoal::factory()->for($user)->create();

        $this->actingAs($user)
            ->deleteJson("/api/savings-goals/{$goal->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('savings_goals', ['id' => $goal->id]);
    }

    public function test_cannot_access_other_users_goal(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $goal = SavingsGoal::factory()->for($owner)->create();

        $this->actingAs($other)
            ->getJson("/api/savings-goals/{$goal->id}")
            ->assertNotFound();
    }
}

<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\SavingsGoal;
use App\Models\SavingsGoalMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SavingsGoalMovementTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_movement_and_progress_is_net_of_in_and_out(): void
    {
        $user = User::factory()->create();
        $goal = SavingsGoal::factory()->for($user)->create(['target_amount' => 1000]);

        $this->actingAs($user)
            ->postJson("/api/savings-goals/{$goal->id}/movements", [
                'direction' => 'in',
                'amount' => 300,
                'occurred_at' => '2026-05-01',
            ])
            ->assertCreated()
            ->assertJsonPath('data.direction', 'in')
            ->assertJsonPath('data.amount', '300.00');

        $this->actingAs($user)
            ->postJson("/api/savings-goals/{$goal->id}/movements", [
                'direction' => 'out',
                'amount' => 100,
                'occurred_at' => '2026-05-10',
            ])
            ->assertCreated();

        $this->actingAs($user)
            ->getJson("/api/savings-goals/{$goal->id}")
            ->assertOk()
            ->assertJsonPath('data.saved', '200.00')
            ->assertJsonPath('data.progress', 20)
            ->assertJsonPath('data.remaining', '800.00')
            ->assertJsonPath('data.movements_count', 2);
    }

    public function test_movement_can_reference_owned_account(): void
    {
        $user = User::factory()->create();
        $goal = SavingsGoal::factory()->for($user)->create();
        $account = Account::factory()->for($user)->create();

        $this->actingAs($user)
            ->postJson("/api/savings-goals/{$goal->id}/movements", [
                'account_id' => $account->id,
                'direction' => 'in',
                'amount' => 50,
                'occurred_at' => '2026-05-01',
            ])
            ->assertCreated()
            ->assertJsonPath('data.account_id', $account->id);
    }

    public function test_movement_rejects_account_of_other_user(): void
    {
        $user = User::factory()->create();
        $goal = SavingsGoal::factory()->for($user)->create();
        $foreignAccount = Account::factory()->for(User::factory()->create())->create();

        $this->actingAs($user)
            ->postJson("/api/savings-goals/{$goal->id}/movements", [
                'account_id' => $foreignAccount->id,
                'direction' => 'in',
                'amount' => 50,
                'occurred_at' => '2026-05-01',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['account_id']);
    }

    public function test_index_lists_movements_of_goal(): void
    {
        $user = User::factory()->create();
        $goal = SavingsGoal::factory()->for($user)->create();
        SavingsGoalMovement::factory()->for($user)->for($goal)->count(3)->create();

        $this->actingAs($user)
            ->getJson("/api/savings-goals/{$goal->id}/movements")
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_delete_movement_updates_progress(): void
    {
        $user = User::factory()->create();
        $goal = SavingsGoal::factory()->for($user)->create(['target_amount' => 1000]);
        $movement = SavingsGoalMovement::factory()->for($user)->for($goal)->create([
            'direction' => 'in',
            'amount' => 400,
        ]);

        $this->actingAs($user)
            ->deleteJson("/api/savings-goals/{$goal->id}/movements/{$movement->id}")
            ->assertNoContent();

        $this->actingAs($user)
            ->getJson("/api/savings-goals/{$goal->id}")
            ->assertJsonPath('data.saved', '0.00');
    }

    public function test_cannot_add_movement_to_other_users_goal(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $goal = SavingsGoal::factory()->for($owner)->create();

        $this->actingAs($other)
            ->postJson("/api/savings-goals/{$goal->id}/movements", [
                'direction' => 'in',
                'amount' => 50,
                'occurred_at' => '2026-05-01',
            ])
            ->assertNotFound();
    }

    public function test_movement_scoped_to_its_goal(): void
    {
        $user = User::factory()->create();
        $goalA = SavingsGoal::factory()->for($user)->create();
        $goalB = SavingsGoal::factory()->for($user)->create();
        $movement = SavingsGoalMovement::factory()->for($user)->for($goalB)->create();

        // movimento di goalB richiesto sotto goalA → non trovato (scoped binding)
        $this->actingAs($user)
            ->getJson("/api/savings-goals/{$goalA->id}/movements/{$movement->id}")
            ->assertNotFound();
    }
}

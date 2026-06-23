<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\SavingsGoal;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    public function test_saved_is_net_flow_of_linked_account_in_period(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $other = Account::factory()->for($user)->create();

        // Nel periodo: +1000 entrata, +500 transfer in entrata, -200 uscita = 1300.
        Transaction::factory()->for($user)->create([
            'account_id' => $account->id, 'type' => 'income', 'amount' => 1000, 'occurred_at' => '2026-06-10',
        ]);
        Transaction::factory()->for($user)->create([
            'account_id' => $other->id, 'transfer_account_id' => $account->id,
            'type' => 'transfer', 'amount' => 500, 'occurred_at' => '2026-06-12',
        ]);
        Transaction::factory()->for($user)->create([
            'account_id' => $account->id, 'type' => 'expense', 'amount' => 200, 'occurred_at' => '2026-06-15',
        ]);
        // Fuori periodo: ignorata.
        Transaction::factory()->for($user)->create([
            'account_id' => $account->id, 'type' => 'income', 'amount' => 9999, 'occurred_at' => '2026-05-01',
        ]);

        $goal = SavingsGoal::factory()->for($user)->create([
            'target_amount' => 2000,
            'account_id' => $account->id,
            'recurrence' => 'none',
            'start_date' => '2026-06-01',
            'target_date' => '2026-06-30',
        ]);

        $this->actingAs($user)
            ->getJson("/api/savings-goals/{$goal->id}")
            ->assertOk()
            ->assertJsonPath('data.saved', '1300.00')
            ->assertJsonPath('data.progress', 65)
            ->assertJsonPath('data.remaining', '700.00');
    }

    public function test_recurring_monthly_goal_uses_current_month(): void
    {
        Carbon::setTestNow('2026-06-23');
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();

        Transaction::factory()->for($user)->create([
            'account_id' => $account->id, 'type' => 'income', 'amount' => 300, 'occurred_at' => '2026-06-05',
        ]);
        Transaction::factory()->for($user)->create([
            'account_id' => $account->id, 'type' => 'income', 'amount' => 999, 'occurred_at' => '2026-05-31',
        ]);

        $goal = SavingsGoal::factory()->for($user)->create([
            'target_amount' => 500,
            'account_id' => $account->id,
            'recurrence' => 'monthly',
        ]);

        $this->actingAs($user)
            ->getJson("/api/savings-goals/{$goal->id}")
            ->assertOk()
            ->assertJsonPath('data.saved', '300.00')
            ->assertJsonPath('data.period_start', '2026-06-01')
            ->assertJsonPath('data.period_end', '2026-06-30');

        Carbon::setTestNow();
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

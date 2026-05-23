<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\RecurringTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecurringTransactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_requires_auth(): void
    {
        $this->getJson('/api/recurring-transactions')->assertUnauthorized();
    }

    public function test_store_defaults_next_run_to_starts_on(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();

        $this->actingAs($user)
            ->postJson('/api/recurring-transactions', [
                'account_id' => $account->id,
                'type' => 'expense',
                'amount' => 50,
                'cadence' => 'monthly',
                'starts_on' => '2026-06-01',
            ])
            ->assertCreated()
            ->assertJsonPath('data.next_run_at', '2026-06-01')
            ->assertJsonPath('data.interval', 1)
            ->assertJsonPath('data.is_active', true);
    }

    public function test_store_requires_transfer_account_when_type_transfer(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();

        $this->actingAs($user)
            ->postJson('/api/recurring-transactions', [
                'account_id' => $account->id,
                'type' => 'transfer',
                'amount' => 50,
                'cadence' => 'monthly',
                'starts_on' => '2026-06-01',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['transfer_account_id']);
    }

    public function test_store_rejects_account_of_other_user(): void
    {
        $user = User::factory()->create();
        $foreign = Account::factory()->for(User::factory())->create();

        $this->actingAs($user)
            ->postJson('/api/recurring-transactions', [
                'account_id' => $foreign->id,
                'type' => 'expense',
                'amount' => 50,
                'cadence' => 'monthly',
                'starts_on' => '2026-06-01',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['account_id']);
    }

    public function test_update_and_destroy(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $recurring = RecurringTransaction::factory()->for($user)->for($account, 'account')->create();

        $this->actingAs($user)
            ->patchJson("/api/recurring-transactions/{$recurring->id}", ['amount' => 999])
            ->assertOk()
            ->assertJsonPath('data.amount', '999.00');

        $this->actingAs($user)
            ->deleteJson("/api/recurring-transactions/{$recurring->id}")
            ->assertNoContent();
    }
}

<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_requires_auth(): void
    {
        $this->getJson('/api/accounts')->assertUnauthorized();
    }

    public function test_index_returns_only_current_user_accounts(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        Account::factory()->for($user)->create(['name' => 'Mine']);
        Account::factory()->for($other)->create(['name' => 'Theirs']);

        $this->actingAs($user)
            ->getJson('/api/accounts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Mine');
    }

    public function test_store_creates_account_for_current_user(): void
    {
        $user = User::factory()->create();

        $payload = [
            'name' => 'Conto corrente',
            'type' => 'bank',
            'currency' => 'EUR',
            'initial_balance' => 1500.50,
        ];

        $this->actingAs($user)
            ->postJson('/api/accounts', $payload)
            ->assertCreated()
            ->assertJsonPath('data.name', 'Conto corrente')
            ->assertJsonPath('data.type', 'bank');

        $this->assertDatabaseHas('accounts', [
            'user_id' => $user->id,
            'name' => 'Conto corrente',
            'type' => 'bank',
        ]);
    }

    public function test_store_validates_type(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/accounts', ['name' => 'X', 'type' => 'invalid'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);
    }

    public function test_show_returns_404_for_other_user_account(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $account = Account::factory()->for($other)->create();

        $this->actingAs($user)
            ->getJson("/api/accounts/{$account->id}")
            ->assertNotFound();
    }

    public function test_update_modifies_account(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create(['name' => 'Old']);

        $this->actingAs($user)
            ->patchJson("/api/accounts/{$account->id}", ['name' => 'New'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New');

        $this->assertSame('New', $account->fresh()->name);
    }

    public function test_destroy_deletes_account(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();

        $this->actingAs($user)
            ->deleteJson("/api/accounts/{$account->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('accounts', ['id' => $account->id]);
    }
}

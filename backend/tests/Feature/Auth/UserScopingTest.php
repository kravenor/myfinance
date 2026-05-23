<?php

namespace Tests\Feature\Auth;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserScopingTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_scope_isolates_records_between_users(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        Account::withoutGlobalScopes()->create([
            'user_id' => $alice->id,
            'name' => 'Alice account',
            'type' => 'cash',
            'currency' => 'EUR',
            'initial_balance' => 0,
        ]);
        Account::withoutGlobalScopes()->create([
            'user_id' => $bob->id,
            'name' => 'Bob account',
            'type' => 'cash',
            'currency' => 'EUR',
            'initial_balance' => 0,
        ]);

        $this->actingAs($alice);

        $this->assertCount(1, Account::all());
        $this->assertSame('Alice account', Account::first()->name);
        $this->assertCount(2, Account::withoutGlobalScopes()->get());
    }

    public function test_creating_model_autofills_authenticated_user_id(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $account = Account::create([
            'name' => 'Auto',
            'type' => 'cash',
            'currency' => 'EUR',
            'initial_balance' => 0,
        ]);

        $this->assertSame($user->id, $account->user_id);
    }
}

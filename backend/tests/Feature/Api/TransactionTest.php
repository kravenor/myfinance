<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\Category;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_requires_auth(): void
    {
        $this->getJson('/api/transactions')->assertUnauthorized();
    }

    public function test_store_creates_expense_transaction_with_tags(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $category = Category::factory()->for($user)->create(['type' => 'expense']);
        $tag = Tag::factory()->for($user)->create();

        $payload = [
            'account_id' => $account->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 42.50,
            'occurred_at' => '2026-05-23',
            'description' => 'Spesa',
            'tag_ids' => [$tag->id],
        ];

        $this->actingAs($user)
            ->postJson('/api/transactions', $payload)
            ->assertCreated()
            ->assertJsonPath('data.amount', '42.50')
            ->assertJsonCount(1, 'data.tags');

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'account_id' => $account->id,
            'category_id' => $category->id,
        ]);
    }

    public function test_store_requires_transfer_account_when_type_transfer(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();

        $this->actingAs($user)
            ->postJson('/api/transactions', [
                'account_id' => $account->id,
                'type' => 'transfer',
                'amount' => 10,
                'occurred_at' => '2026-05-23',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['transfer_account_id']);
    }

    public function test_store_rejects_transfer_account_equal_to_account(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();

        $this->actingAs($user)
            ->postJson('/api/transactions', [
                'account_id' => $account->id,
                'transfer_account_id' => $account->id,
                'type' => 'transfer',
                'amount' => 10,
                'occurred_at' => '2026-05-23',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['transfer_account_id']);
    }

    public function test_store_rejects_account_of_other_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $foreignAccount = Account::factory()->for($other)->create();

        $this->actingAs($user)
            ->postJson('/api/transactions', [
                'account_id' => $foreignAccount->id,
                'type' => 'expense',
                'amount' => 10,
                'occurred_at' => '2026-05-23',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['account_id']);
    }

    public function test_index_filters_by_account_and_date_range(): void
    {
        $user = User::factory()->create();
        $a = Account::factory()->for($user)->create();
        $b = Account::factory()->for($user)->create();

        Transaction::factory()->for($user)->for($a, 'account')->create([
            'occurred_at' => '2026-04-01',
            'type' => 'expense',
            'amount' => 10,
        ]);
        Transaction::factory()->for($user)->for($a, 'account')->create([
            'occurred_at' => '2026-05-15',
            'type' => 'expense',
            'amount' => 20,
        ]);
        Transaction::factory()->for($user)->for($b, 'account')->create([
            'occurred_at' => '2026-05-20',
            'type' => 'expense',
            'amount' => 30,
        ]);

        $this->actingAs($user)
            ->getJson("/api/transactions?account_id={$a->id}&from=2026-05-01&to=2026-05-31")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.amount', '20.00');
    }

    public function test_update_syncs_tags(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $transaction = Transaction::factory()->for($user)->for($account, 'account')->create();
        $tag1 = Tag::factory()->for($user)->create();
        $tag2 = Tag::factory()->for($user)->create();

        $transaction->tags()->sync([$tag1->id]);

        $this->actingAs($user)
            ->patchJson("/api/transactions/{$transaction->id}", [
                'tag_ids' => [$tag2->id],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data.tags')
            ->assertJsonPath('data.tags.0.id', $tag2->id);
    }

    public function test_destroy_deletes_transaction(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $transaction = Transaction::factory()->for($user)->for($account, 'account')->create();

        $this->actingAs($user)
            ->deleteJson("/api/transactions/{$transaction->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('transactions', ['id' => $transaction->id]);
    }

    public function test_show_returns_404_for_other_user_transaction(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $account = Account::factory()->for($other)->create();
        $transaction = Transaction::factory()->for($other)->for($account, 'account')->create();

        $this->actingAs($user)
            ->getJson("/api/transactions/{$transaction->id}")
            ->assertNotFound();
    }
}

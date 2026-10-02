<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

// Gli avvisi si ricalcolano subito dopo le scritture, non solo alla scansione delle 07:00.
class RealtimeNotificationsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{User, Account, Category} */
    private function budgetOf(float $amount): array
    {
        $this->travelTo('2026-05-15');
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $category = Category::factory()->for($user)->create(['type' => 'expense']);
        Budget::factory()->for($user)->for($category)->create(['year' => 2026, 'month' => 5, 'amount' => $amount]);

        return [$user, $account, $category];
    }

    private function expense(User $user, Account $account, Category $category, float $amount): TestResponse
    {
        return $this->actingAs($user)->postJson('/api/transactions', [
            'account_id' => $account->id, 'category_id' => $category->id, 'type' => 'expense',
            'amount' => $amount, 'occurred_at' => '2026-05-15',
        ]);
    }

    public function test_an_expense_that_crosses_the_threshold_notifies_right_away(): void
    {
        [$user, $account, $category] = $this->budgetOf(100);

        $this->expense($user, $account, $category, 85)->assertCreated();

        $this->assertSame(1, $user->notifications()->count());
        $this->assertSame('warning', $user->notifications()->first()->data['level']);

        // Sforamento: nuova notifica; la stessa condizione non si ripete.
        $this->expense($user, $account, $category, 20)->assertCreated();
        $this->expense($user, $account, $category, 5)->assertCreated();
        $this->assertSame(2, $user->notifications()->count());
    }

    public function test_reads_and_failed_writes_do_not_scan(): void
    {
        [$user, $account, $category] = $this->budgetOf(100);
        Transaction::factory()->for($user)->for($account)->for($category)->create([
            'type' => 'expense', 'amount' => 150, 'occurred_at' => '2026-05-10',
        ]);

        $this->actingAs($user)->getJson('/api/transactions')->assertOk();
        $this->expense($user, $account, $category, 0)->assertUnprocessable();

        $this->assertSame(0, $user->notifications()->count());
    }
}

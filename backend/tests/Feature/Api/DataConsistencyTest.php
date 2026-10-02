<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\CategorizationRule;
use App\Models\Category;
use App\Models\ExchangeRate;
use App\Models\SavingsGoal;
use App\Models\Scenario;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

// Fix di docs/analysis/DATA-CONSISTENCY-ANALYSIS.md: valuta obiettivi, dedup CSV,
// regole sui giroconti, tipo della categoria.
class DataConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_goal_saved_is_converted_from_account_currency(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create(['currency' => 'USD']);
        ExchangeRate::query()->create(['date' => '2026-01-01', 'currency' => 'USD', 'rate' => 1.25]);
        Transaction::factory()->for($user)->for($account)->create([
            'type' => 'income', 'amount' => 100, 'currency' => 'USD', 'occurred_at' => '2026-02-01',
        ]);
        $goal = SavingsGoal::factory()->for($user)->create([
            'account_id' => $account->id, 'currency' => 'EUR', 'target_amount' => 160,
            'recurrence' => 'none', 'start_date' => null, 'target_date' => null,
        ]);

        $this->actingAs($user)
            ->getJson("/api/savings-goals/{$goal->id}")
            ->assertOk()
            ->assertJsonPath('data.saved', '80.00')
            ->assertJsonPath('data.remaining', '80.00')
            ->assertJsonPath('data.progress', 50);
    }

    public function test_csv_preview_suggests_external_id_and_import_dedups_on_it(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $csv = "occurred_at,amount,description,external_id\n2026-05-10,-30,ESSELUNGA,TX-1\n2026-05-10,-30,ESSELUNGA,TX-1\n";
        $file = fn () => UploadedFile::fake()->createWithContent('export.csv', $csv);

        $this->actingAs($user)
            ->post('/api/transactions/import/preview', ['file' => $file()])
            ->assertOk()
            ->assertJsonPath('data.suggested.external_id', 'external_id');

        $this->actingAs($user)->post('/api/transactions/import', [
            'file' => $file(),
            'account_id' => $account->id,
            'mapping' => ['date' => 'occurred_at', 'amount' => 'amount', 'description' => 'description', 'external_id' => 'external_id'],
        ])->assertOk()
            ->assertJsonPath('data.imported', 1)
            ->assertJsonPath('data.duplicates', 1);
    }

    public function test_rules_never_categorize_transfers(): void
    {
        $user = User::factory()->create();
        $from = Account::factory()->for($user)->create();
        $to = Account::factory()->for($user)->create();
        $category = Category::factory()->for($user)->create(['type' => 'expense']);
        CategorizationRule::factory()->for($user)->for($category)->create([
            'match_type' => 'contains', 'pattern' => 'risparmio', 'applies_to_type' => 'any',
        ]);
        $transfer = Transaction::factory()->for($user)->for($from)->create([
            'type' => 'transfer', 'transfer_account_id' => $to->id, 'description' => 'Giroconto risparmio',
        ]);

        $this->actingAs($user)
            ->postJson('/api/categorization-rules/apply', ['dry_run' => false])
            ->assertOk()
            ->assertJsonPath('data.matched', 0);

        $this->assertNull($transfer->fresh()->category_id);
    }

    public function test_transaction_category_must_match_type(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $other = Account::factory()->for($user)->create();
        $income = Category::factory()->for($user)->create(['type' => 'income', 'name' => 'Stipendio']);
        $expense = Category::factory()->for($user)->create(['type' => 'expense']);
        $base = ['account_id' => $account->id, 'amount' => 10, 'occurred_at' => '2026-05-10'];

        $this->actingAs($user)
            ->postJson('/api/transactions', $base + ['type' => 'expense', 'category_id' => $income->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category_id');

        $this->actingAs($user)
            ->postJson('/api/transactions', $base + ['type' => 'transfer', 'transfer_account_id' => $other->id, 'category_id' => $expense->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category_id');

        $created = $this->actingAs($user)
            ->postJson('/api/transactions', $base + ['type' => 'expense', 'category_id' => $expense->id])
            ->assertCreated()
            ->json('data.id');

        // Cambiare solo il tipo, senza reinviare la categoria, viene controllato sul valore salvato.
        $this->actingAs($user)
            ->patchJson("/api/transactions/{$created}", ['type' => 'income'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category_id');
    }

    public function test_recurring_category_must_match_type(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $income = Category::factory()->for($user)->create(['type' => 'income']);

        $this->actingAs($user)
            ->postJson('/api/recurring-transactions', [
                'account_id' => $account->id, 'category_id' => $income->id, 'type' => 'expense',
                'amount' => 50, 'cadence' => 'monthly', 'interval' => 1, 'starts_on' => '2026-05-01',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category_id');
    }

    public function test_recurring_saves_matching_category(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $expense = Category::factory()->for($user)->create(['type' => 'expense']);

        $this->actingAs($user)
            ->postJson('/api/recurring-transactions', [
                'account_id' => $account->id, 'category_id' => $expense->id, 'type' => 'expense',
                'amount' => 50, 'cadence' => 'monthly', 'interval' => 1, 'starts_on' => '2026-05-01',
            ])
            ->assertCreated()
            ->assertJsonPath('data.category_id', $expense->id);
    }

    public function test_scenario_item_category_must_match_type(): void
    {
        $user = User::factory()->create();
        $scenario = Scenario::factory()->for($user)->create();
        $expense = Category::factory()->for($user)->create(['type' => 'expense']);
        $base = ['amount' => 100, 'cadence' => 'monthly', 'starts_on' => '2026-06-01', 'category_id' => $expense->id];

        $this->actingAs($user)
            ->postJson("/api/scenarios/{$scenario->id}/items", $base + ['type' => 'income'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category_id');

        // Senza type la voce è un'uscita: la categoria di uscita è valida.
        $itemId = $this->actingAs($user)
            ->postJson("/api/scenarios/{$scenario->id}/items", $base)
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($user)
            ->patchJson("/api/scenarios/{$scenario->id}/items/{$itemId}", ['type' => 'income'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category_id');
    }
}

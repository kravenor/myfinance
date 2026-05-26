<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\CategorizationRule;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class TransactionImportAutoCategorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_rule_assigns_category_when_mapping_has_no_category_column(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $category = Category::factory()->for($user)->create(['type' => 'expense']);

        CategorizationRule::factory()->for($user)->for($category)->create([
            'match_type' => 'contains',
            'pattern' => 'esselunga',
            'applies_to_type' => 'expense',
            'priority' => 10,
        ]);

        $csv = "Data,Importo,Descrizione\n2026-05-10,-30,ESSELUNGA via Roma\n";
        $file = UploadedFile::fake()->createWithContent('m.csv', $csv);

        $response = $this->actingAs($user)->post('/api/transactions/import', [
            'file' => $file,
            'account_id' => $account->id,
            'mapping' => ['date' => 'Data', 'amount' => 'Importo', 'description' => 'Descrizione'],
        ])->assertOk();

        $response->assertJsonPath('data.imported', 1)
            ->assertJsonPath('data.auto_categorized', 1);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'category_id' => $category->id,
            'description' => 'ESSELUNGA via Roma',
        ]);

        $this->assertDatabaseHas('categorization_rules', [
            'id' => CategorizationRule::query()->first()->id,
            'times_applied' => 1,
        ]);
    }

    public function test_csv_category_column_wins_over_rules(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $catFromCsv = Category::factory()->for($user)->create(['name' => 'Alimentari', 'type' => 'expense']);
        $catFromRule = Category::factory()->for($user)->create(['type' => 'expense']);

        CategorizationRule::factory()->for($user)->for($catFromRule)->create([
            'match_type' => 'contains', 'pattern' => 'esselunga', 'applies_to_type' => 'any',
        ]);

        $csv = "Data,Importo,Descrizione,Cat\n2026-05-10,-30,ESSELUNGA,Alimentari\n";
        $file = UploadedFile::fake()->createWithContent('m.csv', $csv);

        $this->actingAs($user)->post('/api/transactions/import', [
            'file' => $file,
            'account_id' => $account->id,
            'mapping' => [
                'date' => 'Data', 'amount' => 'Importo', 'description' => 'Descrizione', 'category' => 'Cat',
            ],
        ])->assertJsonPath('data.auto_categorized', 0);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'category_id' => $catFromCsv->id,
        ]);
    }

    public function test_applies_to_type_filter_is_respected(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $category = Category::factory()->for($user)->create(['type' => 'income']);

        CategorizationRule::factory()->for($user)->for($category)->create([
            'match_type' => 'contains', 'pattern' => 'esselunga', 'applies_to_type' => 'income',
        ]);

        $csv = "Data,Importo,Descrizione\n2026-05-10,-30,Esselunga\n";
        $file = UploadedFile::fake()->createWithContent('m.csv', $csv);

        $this->actingAs($user)->post('/api/transactions/import', [
            'file' => $file,
            'account_id' => $account->id,
            'mapping' => ['date' => 'Data', 'amount' => 'Importo', 'description' => 'Descrizione'],
        ])->assertJsonPath('data.auto_categorized', 0);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'category_id' => null,
        ]);
    }

    public function test_priority_order_is_respected(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $winning = Category::factory()->for($user)->create(['name' => 'Carburante']);
        $losing = Category::factory()->for($user)->create(['name' => 'Generico']);

        CategorizationRule::factory()->for($user)->for($losing)->create([
            'match_type' => 'contains', 'pattern' => 'eni', 'priority' => 500,
        ]);
        CategorizationRule::factory()->for($user)->for($winning)->create([
            'match_type' => 'contains', 'pattern' => 'eni stazione', 'priority' => 10,
        ]);

        $csv = "Data,Importo,Descrizione\n2026-05-10,-40,ENI Stazione 12\n";
        $file = UploadedFile::fake()->createWithContent('m.csv', $csv);

        $this->actingAs($user)->post('/api/transactions/import', [
            'file' => $file,
            'account_id' => $account->id,
            'mapping' => ['date' => 'Data', 'amount' => 'Importo', 'description' => 'Descrizione'],
        ])->assertJsonPath('data.imported', 1);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'category_id' => $winning->id,
        ]);
    }

    public function test_preview_predictions_endpoint(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create();
        CategorizationRule::factory()->for($user)->for($category)->create([
            'match_type' => 'contains', 'pattern' => 'amazon', 'applies_to_type' => 'any',
        ]);

        $csv = "Data,Importo,Descrizione\n2026-05-10,-30,AMAZON ORDER\n2026-05-11,-10,Sconosciuto\n";
        $file = UploadedFile::fake()->createWithContent('m.csv', $csv);

        $response = $this->actingAs($user)
            ->post('/api/transactions/import/preview-predictions', [
                'file' => $file,
                'mapping' => [
                    'date' => 'Data', 'amount' => 'Importo', 'description' => 'Descrizione',
                ],
            ])
            ->assertOk();

        $response->assertJsonPath('data.0.category_id', $category->id)
            ->assertJsonPath('data.1.category_id', null);
    }
}

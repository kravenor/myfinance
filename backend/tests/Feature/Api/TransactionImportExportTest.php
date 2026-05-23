<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class TransactionImportExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_requires_auth(): void
    {
        $this->get('/api/transactions/export')->assertUnauthorized();
    }

    public function test_export_returns_csv_of_user_transactions_only(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $account = Account::factory()->for($user)->create(['name' => 'Conto']);
        $foreignAccount = Account::factory()->for($other)->create();

        Transaction::factory()->for($user)->for($account, 'account')->create([
            'type' => 'expense', 'amount' => 50, 'occurred_at' => '2026-05-10', 'description' => 'Spesa',
        ]);
        Transaction::factory()->for($other)->for($foreignAccount, 'account')->create([
            'type' => 'expense', 'amount' => 999, 'occurred_at' => '2026-05-10', 'description' => 'Altro user',
        ]);

        $response = $this->actingAs($user)->get('/api/transactions/export');
        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $body = $response->streamedContent();
        $this->assertStringContainsString('occurred_at,type,amount', $body);
        $this->assertStringContainsString('Spesa', $body);
        $this->assertStringNotContainsString('Altro user', $body);
    }

    public function test_export_filters_by_date_range(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        Transaction::factory()->for($user)->for($account, 'account')->create([
            'amount' => 10, 'occurred_at' => '2026-04-01', 'description' => 'fuori',
        ]);
        Transaction::factory()->for($user)->for($account, 'account')->create([
            'amount' => 20, 'occurred_at' => '2026-05-15', 'description' => 'dentro',
        ]);

        $body = $this->actingAs($user)
            ->get('/api/transactions/export?from=2026-05-01&to=2026-05-31')
            ->streamedContent();

        $this->assertStringContainsString('dentro', $body);
        $this->assertStringNotContainsString('fuori', $body);
    }

    public function test_import_preview_returns_headers_and_sample(): void
    {
        $user = User::factory()->create();

        $csv = "Data,Importo,Descrizione\n2026-05-10,42.50,Spesa A\n2026-05-11,-30,Spesa B\n";
        $file = UploadedFile::fake()->createWithContent('estratto.csv', $csv);

        $response = $this->actingAs($user)
            ->post('/api/transactions/import/preview', ['file' => $file])
            ->assertOk();

        $response->assertJsonPath('data.headers', ['Data', 'Importo', 'Descrizione'])
            ->assertJsonPath('data.suggested.date', 'Data')
            ->assertJsonPath('data.suggested.amount', 'Importo')
            ->assertJsonPath('data.suggested.description', 'Descrizione')
            ->assertJsonCount(2, 'data.sample')
            ->assertJsonPath('data.sample.0.Descrizione', 'Spesa A');
    }

    public function test_import_commits_rows_with_type_inferred_from_sign(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();

        $csv = "Data;Importo;Descrizione\n2026-05-10;-42,50;Spesa A\n2026-05-11;100,00;Stipendio\n";
        $file = UploadedFile::fake()->createWithContent('movimenti.csv', $csv);

        $response = $this->actingAs($user)->post('/api/transactions/import', [
            'file' => $file,
            'account_id' => $account->id,
            'mapping' => [
                'date' => 'Data',
                'amount' => 'Importo',
                'description' => 'Descrizione',
            ],
            'date_format' => 'Y-m-d',
        ])->assertOk();

        $response->assertJsonPath('data.imported', 2)
            ->assertJsonPath('data.skipped', 0);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'account_id' => $account->id,
            'type' => 'expense',
            'amount' => '42.50',
            'description' => 'Spesa A',
        ]);
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'type' => 'income',
            'amount' => '100.00',
            'description' => 'Stipendio',
        ]);
    }

    public function test_import_skips_invalid_rows(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();

        $csv = "Data,Importo,Descrizione\n2026-05-10,42.50,OK\n,, \nbad-date,12,X\n";
        $file = UploadedFile::fake()->createWithContent('estratto.csv', $csv);

        $response = $this->actingAs($user)->post('/api/transactions/import', [
            'file' => $file,
            'account_id' => $account->id,
            'mapping' => [
                'date' => 'Data',
                'amount' => 'Importo',
                'description' => 'Descrizione',
            ],
            'date_format' => 'Y-m-d',
        ])->assertOk();

        $response->assertJsonPath('data.imported', 1)
            ->assertJsonPath('data.skipped', 1)
            ->assertJsonCount(1, 'data.errors');
    }

    public function test_import_matches_category_by_name(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $category = Category::factory()->for($user)->create(['name' => 'Spesa alimentare', 'type' => 'expense']);

        $csv = "Data,Importo,Descrizione,Categoria\n2026-05-10,-30.00,Esselunga,spesa alimentare\n";
        $file = UploadedFile::fake()->createWithContent('estratto.csv', $csv);

        $this->actingAs($user)->post('/api/transactions/import', [
            'file' => $file,
            'account_id' => $account->id,
            'mapping' => [
                'date' => 'Data',
                'amount' => 'Importo',
                'description' => 'Descrizione',
                'category' => 'Categoria',
            ],
            'date_format' => 'Y-m-d',
        ])->assertOk();

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'category_id' => $category->id,
            'description' => 'Esselunga',
        ]);
    }
}

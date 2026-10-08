<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\InvestmentHolding;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RequestLimitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_range_is_validated_and_capped(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/reports/net-worth?from=1000-01-01&to=9999-12-31')
            ->assertJsonValidationErrors('from');
        $this->actingAs($user)->getJson('/api/reports/timeline?from=abc')
            ->assertJsonValidationErrors('from');
        $this->actingAs($user)->getJson('/api/reports/summary?from=2026-06-01&to=2026-05-01')
            ->assertJsonValidationErrors('from');

        $this->actingAs($user)->getJson('/api/reports/net-worth?from=2017-01-01&to=2026-12-31')->assertOk();
    }

    public function test_per_page_is_clamped(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/tags?per_page=1000000')->assertJsonPath('meta.per_page', 200);
        $this->actingAs($user)->getJson('/api/tags?per_page=-1')->assertJsonPath('meta.per_page', 1);
    }

    public function test_refresh_prices_touches_only_own_symbols_and_is_throttled(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $other = User::factory()->create();
        InvestmentHolding::factory()->for($user)->create(['symbol' => 'MINE.MI', 'asset_type' => 'stock']);
        InvestmentHolding::factory()->for($other)->create(['symbol' => 'THEIRS.MI', 'asset_type' => 'stock']);

        $this->actingAs($user)->postJson('/api/investments/refresh-prices')->assertOk();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'MINE.MI'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'THEIRS.MI'));

        $this->actingAs($user)->postJson('/api/investments/refresh-prices')->assertOk();
        $this->actingAs($user)->postJson('/api/investments/refresh-prices')->assertTooManyRequests();
    }

    public function test_recurring_cannot_start_in_the_remote_past(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();

        $this->actingAs($user)->postJson('/api/recurring-transactions', [
            'account_id' => $account->id,
            'type' => 'expense',
            'amount' => 10,
            'cadence' => 'daily',
            'starts_on' => '1000-01-01',
        ])->assertJsonValidationErrors('starts_on');
    }

    public function test_import_rejects_files_over_the_row_limit(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $csv = "Data,Importo\n".str_repeat("2026-05-10,1\n", TransactionImportService::MAX_ROWS + 1);

        $this->actingAs($user)->post('/api/transactions/import', [
            'file' => UploadedFile::fake()->createWithContent('grande.csv', $csv),
            'account_id' => $account->id,
            'mapping' => ['date' => 'Data', 'amount' => 'Importo'],
        ], ['Accept' => 'application/json'])->assertJsonValidationErrors('file');

        $this->assertSame(0, Transaction::query()->count());
    }

    public function test_import_preview_rejects_too_many_columns(): void
    {
        $user = User::factory()->create();
        $csv = implode(',', array_map(fn ($i) => "c{$i}", range(1, 101)))."\nx\n";

        $this->actingAs($user)->post('/api/transactions/import/preview', [
            'file' => UploadedFile::fake()->createWithContent('largo.csv', $csv),
        ], ['Accept' => 'application/json'])->assertJsonValidationErrors('file');
    }

    public function test_import_applies_transaction_limits_and_account_currency(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create(['currency' => 'USD']);
        $long = str_repeat('a', 300);
        $csv = "Data,Importo,Descrizione\n2026-05-10,-12.50,{$long}\n2026-05-11,0,Zero\n";

        $response = $this->actingAs($user)->post('/api/transactions/import', [
            'file' => UploadedFile::fake()->createWithContent('estratto.csv', $csv),
            'account_id' => $account->id,
            'mapping' => ['date' => 'Data', 'amount' => 'Importo', 'description' => 'Descrizione'],
            'currency' => 'EUR',
        ])->assertOk();

        $response->assertJsonPath('data.imported', 1)->assertJsonPath('data.skipped', 1)
            ->assertJsonPath('data.errors.0.message', 'Importo nullo o fuori intervallo.');
        $tx = Transaction::query()->sole();
        $this->assertSame('USD', $tx->currency);
        $this->assertSame(255, mb_strlen((string) $tx->description));
    }
}

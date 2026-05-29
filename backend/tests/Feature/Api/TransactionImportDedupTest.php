<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class TransactionImportDedupTest extends TestCase
{
    use RefreshDatabase;

    private function ofx(string $fitid, string $amount = '-30.00', string $name = 'ESSELUNGA'): string
    {
        return '<?xml version="1.0"?><OFX><STMTTRN><TRNTYPE>DEBIT</TRNTYPE>'
            ."<DTPOSTED>20260510</DTPOSTED><TRNAMT>{$amount}</TRNAMT>"
            ."<NAME>{$name}</NAME><FITID>{$fitid}</FITID></STMTTRN></OFX>";
    }

    public function test_reimporting_same_ofx_skips_as_duplicates(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $content = $this->ofx('FIT-1');

        $this->actingAs($user)->post('/api/transactions/import', [
            'file' => UploadedFile::fake()->createWithContent('e.ofx', $content),
            'account_id' => $account->id,
            'mapping' => ['date' => 'date', 'amount' => 'amount'],
        ])->assertOk()->assertJsonPath('data.imported', 1)->assertJsonPath('data.duplicates', 0);

        $this->actingAs($user)->post('/api/transactions/import', [
            'file' => UploadedFile::fake()->createWithContent('e.ofx', $content),
            'account_id' => $account->id,
            'mapping' => ['date' => 'date', 'amount' => 'amount'],
        ])->assertOk()->assertJsonPath('data.imported', 0)->assertJsonPath('data.duplicates', 1);

        $this->assertSame(1, Transaction::query()->where('external_id', 'FIT-1')->count());
    }

    public function test_duplicate_within_same_file_is_imported_once(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $content = '<?xml version="1.0"?><OFX>'
            .'<STMTTRN><TRNTYPE>DEBIT</TRNTYPE><DTPOSTED>20260510</DTPOSTED><TRNAMT>-30.00</TRNAMT><NAME>A</NAME><FITID>DUP</FITID></STMTTRN>'
            .'<STMTTRN><TRNTYPE>DEBIT</TRNTYPE><DTPOSTED>20260511</DTPOSTED><TRNAMT>-31.00</TRNAMT><NAME>B</NAME><FITID>DUP</FITID></STMTTRN>'
            .'</OFX>';

        $this->actingAs($user)->post('/api/transactions/import', [
            'file' => UploadedFile::fake()->createWithContent('e.ofx', $content),
            'account_id' => $account->id,
            'mapping' => ['date' => 'date', 'amount' => 'amount'],
        ])->assertOk()
            ->assertJsonPath('data.imported', 1)
            ->assertJsonPath('data.duplicates', 1);

        $this->assertSame(1, Transaction::query()->where('external_id', 'DUP')->count());
    }

    public function test_csv_without_external_id_mapping_is_not_deduped(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $csv = "Data,Importo,Descrizione\n2026-05-10,-30,ESSELUNGA\n2026-05-10,-30,ESSELUNGA\n";

        $this->actingAs($user)->post('/api/transactions/import', [
            'file' => UploadedFile::fake()->createWithContent('m.csv', $csv),
            'account_id' => $account->id,
            'mapping' => ['date' => 'Data', 'amount' => 'Importo', 'description' => 'Descrizione'],
        ])->assertOk()
            ->assertJsonPath('data.imported', 2)
            ->assertJsonPath('data.duplicates', 0);
    }

    public function test_dedup_is_scoped_per_user(): void
    {
        $other = User::factory()->create();
        $otherAccount = Account::factory()->for($other)->create();
        Transaction::factory()->for($other)->for($otherAccount)->create(['external_id' => 'SHARED']);

        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();

        $this->actingAs($user)->post('/api/transactions/import', [
            'file' => UploadedFile::fake()->createWithContent('e.ofx', $this->ofx('SHARED')),
            'account_id' => $account->id,
            'mapping' => ['date' => 'date', 'amount' => 'amount'],
        ])->assertOk()
            ->assertJsonPath('data.imported', 1)
            ->assertJsonPath('data.duplicates', 0);
    }
}

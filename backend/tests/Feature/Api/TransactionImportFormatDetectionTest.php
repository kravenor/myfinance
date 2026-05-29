<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class TransactionImportFormatDetectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_detects_csv_format(): void
    {
        $user = User::factory()->create();
        $csv = "Data,Importo,Descrizione\n2026-05-10,-30,ESSELUNGA\n";
        $file = UploadedFile::fake()->createWithContent('m.csv', $csv);

        $this->actingAs($user)
            ->post('/api/transactions/import/preview', ['file' => $file])
            ->assertOk()
            ->assertJsonPath('data.format', 'csv')
            ->assertJsonPath('data.mapping_locked', false);
    }

    public function test_detects_ofx_format(): void
    {
        $user = User::factory()->create();
        $ofx = '<?xml version="1.0"?><OFX><STMTTRN><DTPOSTED>20260510</DTPOSTED><TRNAMT>-30.00</TRNAMT><NAME>ESSELUNGA</NAME><FITID>1</FITID></STMTTRN></OFX>';
        $file = UploadedFile::fake()->createWithContent('e.ofx', $ofx);

        $this->actingAs($user)
            ->post('/api/transactions/import/preview', ['file' => $file])
            ->assertOk()
            ->assertJsonPath('data.format', 'ofx')
            ->assertJsonPath('data.mapping_locked', true)
            ->assertJsonPath('data.sample.0.description', 'ESSELUNGA');
    }

    public function test_detects_qif_format(): void
    {
        $user = User::factory()->create();
        $qif = "!Type:Bank\nD2026-05-10\nT-30.00\nPESSELUNGA\n^\n";
        $file = UploadedFile::fake()->createWithContent('m.qif', $qif);

        $this->actingAs($user)
            ->post('/api/transactions/import/preview', ['file' => $file])
            ->assertOk()
            ->assertJsonPath('data.format', 'qif')
            ->assertJsonPath('data.mapping_locked', true);
    }

    public function test_imports_ofx_and_stores_external_id(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $ofx = '<?xml version="1.0"?><OFX><STMTTRN><TRNTYPE>DEBIT</TRNTYPE><DTPOSTED>20260510</DTPOSTED><TRNAMT>-30.00</TRNAMT><NAME>ESSELUNGA</NAME><FITID>FIT-9</FITID></STMTTRN></OFX>';
        $file = UploadedFile::fake()->createWithContent('e.ofx', $ofx);

        $this->actingAs($user)
            ->post('/api/transactions/import', [
                'file' => $file,
                'account_id' => $account->id,
                'mapping' => ['date' => 'date', 'amount' => 'amount'],
            ])
            ->assertOk()
            ->assertJsonPath('data.imported', 1);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'account_id' => $account->id,
            'type' => 'expense',
            'description' => 'ESSELUNGA',
            'external_id' => 'FIT-9',
            'occurred_at' => '2026-05-10',
        ]);
    }
}

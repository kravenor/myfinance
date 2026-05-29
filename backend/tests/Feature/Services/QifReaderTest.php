<?php

namespace Tests\Feature\Services;

use App\Services\Import\QifReader;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class QifReaderTest extends TestCase
{
    public function test_parses_qif_entries(): void
    {
        $qif = "!Type:Bank\nD10/05/2026\nT-30,50\nPESSELUNGA\nMSpesa settimanale\n^\nD12/05/2026\nT1500.00\nPSTIPENDIO\n^\nD2026-05-15\nT-9.99\nPNETFLIX\n^\n";

        $file = UploadedFile::fake()->createWithContent('movimenti.qif', $qif);
        $result = (new QifReader)->read($file, PHP_INT_MAX);

        $this->assertTrue($result['mapping_locked']);
        $this->assertSame(['date', 'amount', 'description', 'notes', 'type'], $result['headers']);
        $this->assertCount(3, $result['rows']);

        $this->assertSame('2026-05-10', $result['rows'][0]['date']);
        $this->assertSame('-30,50', $result['rows'][0]['amount']);
        $this->assertSame('ESSELUNGA', $result['rows'][0]['description']);
        $this->assertSame('Spesa settimanale', $result['rows'][0]['notes']);
        $this->assertSame('expense', $result['rows'][0]['type']);

        $this->assertSame('2026-05-12', $result['rows'][1]['date']);
        $this->assertSame('income', $result['rows'][1]['type']);

        $this->assertSame('2026-05-15', $result['rows'][2]['date']);
    }

    public function test_unparseable_date_yields_empty_string(): void
    {
        $qif = "!Type:Bank\nDgibberish\nT-1.00\nPTest\n^\n";

        $file = UploadedFile::fake()->createWithContent('m.qif', $qif);
        $result = (new QifReader)->read($file, PHP_INT_MAX);

        $this->assertSame('', $result['rows'][0]['date']);
    }
}

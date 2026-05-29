<?php

namespace Tests\Feature\Services;

use App\Services\Import\OfxReader;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class OfxReaderTest extends TestCase
{
    public function test_parses_ofx_transactions(): void
    {
        $ofx = <<<'OFX'
        <?xml version="1.0" encoding="UTF-8"?>
        <OFX>
          <BANKMSGSRSV1><STMTTRNRS><STMTRS><BANKTRANLIST>
            <STMTTRN>
              <TRNTYPE>DEBIT</TRNTYPE>
              <DTPOSTED>20260510120000</DTPOSTED>
              <TRNAMT>-30.50</TRNAMT>
              <FITID>ABC123</FITID>
              <NAME>ESSELUNGA SPA</NAME>
              <MEMO>Spesa</MEMO>
            </STMTTRN>
            <STMTTRN>
              <TRNTYPE>CREDIT</TRNTYPE>
              <DTPOSTED>20260512</DTPOSTED>
              <TRNAMT>1500.00</TRNAMT>
              <FITID>ABC124</FITID>
              <NAME>STIPENDIO</NAME>
            </STMTTRN>
          </BANKTRANLIST></STMTRS></STMTTRNRS></BANKMSGSRSV1>
        </OFX>
        OFX;

        $file = UploadedFile::fake()->createWithContent('estratto.ofx', $ofx);
        $result = (new OfxReader)->read($file, PHP_INT_MAX);

        $this->assertTrue($result['mapping_locked']);
        $this->assertSame(['date', 'amount', 'description', 'type', 'external_id'], $result['headers']);
        $this->assertCount(2, $result['rows']);

        $this->assertSame('2026-05-10', $result['rows'][0]['date']);
        $this->assertSame('-30.50', $result['rows'][0]['amount']);
        $this->assertSame('ESSELUNGA SPA', $result['rows'][0]['description']);
        $this->assertSame('expense', $result['rows'][0]['type']);
        $this->assertSame('ABC123', $result['rows'][0]['external_id']);

        $this->assertSame('2026-05-12', $result['rows'][1]['date']);
        $this->assertSame('income', $result['rows'][1]['type']);
    }

    public function test_parses_sgml_style_ofx_without_closing_leaf_tags(): void
    {
        $ofx = "OFXHEADER:100\nDATA:OFXSGML\n\n<OFX><BANKMSGSRSV1><STMTTRN>\n<TRNTYPE>POS\n<DTPOSTED>20260101\n<TRNAMT>-12,00\n<FITID>X1\n<NAME>BAR ROMA\n</STMTTRN></BANKMSGSRSV1></OFX>";

        $file = UploadedFile::fake()->createWithContent('e.ofx', $ofx);
        $result = (new OfxReader)->read($file, PHP_INT_MAX);

        $this->assertCount(1, $result['rows']);
        $this->assertSame('2026-01-01', $result['rows'][0]['date']);
        $this->assertSame('BAR ROMA', $result['rows'][0]['description']);
        $this->assertSame('expense', $result['rows'][0]['type']);
    }
}

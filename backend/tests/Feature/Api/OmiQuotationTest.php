<?php

namespace Tests\Feature\Api;

use App\Models\OmiQuotation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use ZipArchive;

class OmiQuotationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    /** CSV VALORI come lo scarica l'Agenzia: didascalia, `;` finale, decimali con la virgola, Windows-1252. */
    public static function values(string $semester = '2025/2', string $civili = '2200;3300'): string
    {
        $text = "Quotazioni Immobiliari : Valori di Mercato - Semestre {$semester} - elaborazione del 27-MAR-26\n"
            ."Area_territoriale;Regione;Prov;Comune_ISTAT;Comune_cat;Sez;Comune_amm;Comune_descrizione;Fascia;Zona;LinkZona;Cod_Tip;Descr_Tipologia;Stato;Stato_prev;Compr_min;Compr_max;Sup_NL_compr;Loc_min;Loc_max;Sup_NL_loc;\n"
            ."NORD-OVEST;PIEMONTE;TO;1001272;A1AA; ;L219;TORINO;B;B1;TO00000037;20;Abitazioni civili;NORMALE;P;{$civili};L;8,3;12,4;L;\n"
            ."NORD-OVEST;PIEMONTE;TO;1001272;A1AA; ;L219;TORINO;B;B1;TO00000037;19;Abitazioni signorili;OTTIMO;;3200;4800;L;8,1;12,3;L;\n"
            ."NORD-OVEST;PIEMONTE;TO;1001272;A1AA; ;L219;TORINO;B;B1;TO00000037;13;Box;NORMALE;P;;;L;;;L;\n" // senza valori: saltata
            ."NORD-EST;VENETO;VE;5027042;A1AA; ;L736;CANTÙ DI PROVA;C;C1;VE00000001;20;Abitazioni civili;NORMALE;P;1500,5;1800;L;5;6;L;\n";

        return (string) mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
    }

    private function zip(string $values, ?string $zones = null): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'omi').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('QI_1_1_20252_VALORI.csv', $values);
        if ($zones !== null) {
            $zip->addFromString('QI_1_1_20252_ZONE.csv', $zones);
        }
        $zip->close();

        return new UploadedFile($path, 'QI_1_1_20252.zip', 'application/zip', null, true);
    }

    public function test_imports_the_zip_with_zone_descriptions(): void
    {
        $zones = "Quotazioni Immobiliari : Informazioni di Zona OMI - Semestre 2025/2\n"
            ."Area_territoriale;Regione;Prov;Comune_ISTAT;Comune_cat;Sez;Comune_amm;Comune_descrizione;Fascia;Zona_Descr;Zona;LinkZona;Cod_tip_prev;Descr_tip_prev;Stato_prev;Microzona;\n"
            ."NORD-OVEST;PIEMONTE;TO;1001272;A1AA; ;L219;TORINO;B;'CENTRO STORICO';B1;TO00000037;20;Abitazioni civili;N;0;\n";

        $this->actingAs($this->user)
            ->post('/api/omi-quotations/import', ['file' => $this->zip(self::values(), $zones)])
            ->assertCreated()
            ->assertJsonPath('data.semester', '2025-07-01')
            ->assertJsonPath('data.rows', 3)
            ->assertJsonPath('data.comuni', 2);

        $civili = OmiQuotation::query()->where('cod_tip', 20)->where('link_zona', 'TO00000037')->firstOrFail();
        $this->assertSame('CENTRO STORICO', $civili->zona_descr);
        $this->assertSame(2750.0, $civili->mid());
        $this->assertSame('L', $civili->sup_nl);
        $this->assertSame('CANTÙ DI PROVA', OmiQuotation::query()->where('link_zona', 'VE00000001')->value('comune'));
        $this->assertSame('1500.50', OmiQuotation::query()->where('link_zona', 'VE00000001')->value('compr_min'));
    }

    public function test_plain_csv_works_and_reimport_overwrites_the_semester(): void
    {
        $csv = fn (string $civili) => UploadedFile::fake()->createWithContent('valori.csv', self::values('2025/1', $civili));

        $this->actingAs($this->user)->post('/api/omi-quotations/import', ['file' => $csv('2200;3300')])->assertCreated();
        $this->actingAs($this->user)->post('/api/omi-quotations/import', ['file' => $csv('2400;3400')])->assertCreated();

        $this->assertSame(3, OmiQuotation::query()->count());
        $this->assertSame('2400.00', OmiQuotation::query()->where('cod_tip', 20)->where('link_zona', 'TO00000037')->value('compr_min'));
        $this->getJson('/api/omi-quotations')->assertJsonPath('data.0', ['semester' => '2025-01-01', 'comuni' => 2]);
    }

    public function test_rejects_files_that_are_not_omi_values(): void
    {
        $this->actingAs($this->user)
            ->post('/api/omi-quotations/import', ['file' => UploadedFile::fake()->createWithContent('x.csv', "data;importo\n2025-01-01;10\n")])
            ->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->actingAs($this->user)
            ->post('/api/omi-quotations/import', ['file' => $this->zipWithout()])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    private function zipWithout(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'omi').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('leggimi.txt', 'niente');
        $zip->close();

        return new UploadedFile($path, 'altro.zip', 'application/zip', null, true);
    }

    public function test_zones_search_sees_only_own_quotations(): void
    {
        $this->actingAs($this->user)
            ->post('/api/omi-quotations/import', ['file' => UploadedFile::fake()->createWithContent('v.csv', self::values())])
            ->assertCreated();

        $this->getJson('/api/omi-quotations/zones?q=tor')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.link_zona', 'TO00000037')
            ->assertJsonCount(2, 'data.0.types');

        $this->actingAs(User::factory()->create())
            ->getJson('/api/omi-quotations/zones?q=tor')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_zone_lists_the_linked_semesters_per_type(): void
    {
        foreach (['2024/2', '2025/1'] as $semester) {
            $this->actingAs($this->user)
                ->post('/api/omi-quotations/import', ['file' => UploadedFile::fake()->createWithContent('v.csv', self::values($semester))])
                ->assertCreated();
        }

        $types = collect($this->getJson('/api/omi-quotations/zones?zone=TO00000037')->assertOk()->json('data.0.types'))
            ->keyBy('tipologia');

        $this->assertSame(['2024-07-01', '2025-01-01'], $types['Abitazioni civili']['semesters']);
    }
}

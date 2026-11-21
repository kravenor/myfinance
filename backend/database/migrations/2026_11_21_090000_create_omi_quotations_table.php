<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Quotazioni OMI dell'Agenzia delle Entrate (€/m² per zona, tipologia e stato), caricate
// dall'utente dal CSV semestrale: si scaricano solo con SPID/Fisconline, quindi niente fetch.
// Per utente e non globali come instrument_prices: sono dati che arrivano da un upload.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('omi_quotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('semester'); // primo giorno del semestre: 1/1 o 1/7
            $table->string('link_zona', 16);
            $table->string('comune', 80);
            $table->string('prov', 2);
            $table->string('zona', 8);
            $table->string('zona_descr', 255)->nullable();
            $table->unsignedSmallInteger('cod_tip');
            $table->string('tipologia', 60);
            $table->string('stato', 12);
            $table->decimal('compr_min', 10, 2);
            $table->decimal('compr_max', 10, 2);
            $table->char('sup_nl', 1)->nullable(); // superficie di riferimento: L lorda, N netta
            $table->timestamps();

            $table->unique(['user_id', 'link_zona', 'cod_tip', 'stato', 'semester'], 'omi_quotations_series_unique');
            $table->index(['user_id', 'comune']);
        });

        Schema::table('investment_holdings', function (Blueprint $table) {
            $table->string('omi_zone', 16)->nullable()->after('symbol');
            $table->unsignedSmallInteger('omi_type')->nullable()->after('omi_zone');
            $table->string('omi_state', 12)->nullable()->after('omi_type');
            // Facoltativa, solo per confrontare il valore con la media OMI di zona: non entra nel calcolo.
            $table->decimal('surface_sqm', 8, 2)->nullable()->after('omi_state');
        });
    }

    public function down(): void
    {
        Schema::table('investment_holdings', function (Blueprint $table) {
            $table->dropColumn(['omi_zone', 'omi_type', 'omi_state', 'surface_sqm']);
        });

        Schema::dropIfExists('omi_quotations');
    }
};

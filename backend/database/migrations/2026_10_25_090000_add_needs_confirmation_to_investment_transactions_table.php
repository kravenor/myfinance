<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Una rata PAC automatica usa la quotazione del giorno di addebito, ma la banca esegue
// l'ordine con un ritardo variabile: il movimento resta una stima finché l'utente non
// lo allinea all'eseguito.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investment_transactions', function (Blueprint $table) {
            $table->boolean('needs_confirmation')->default(false)->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('investment_transactions', function (Blueprint $table) {
            $table->dropColumn('needs_confirmation');
        });
    }
};

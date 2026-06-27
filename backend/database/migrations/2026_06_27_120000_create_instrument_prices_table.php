<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instrument_prices', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 40);
            $table->string('currency', 3);
            // Prezzo di chiusura (EOD) per 1 unità dello strumento, nella valuta `currency`.
            $table->decimal('price', 24, 8);
            $table->date('as_of');
            $table->timestamps();

            // Una quota per (symbol, giorno) — accumula storico come exchange_rates. Dato globale.
            $table->unique(['symbol', 'as_of']);
            $table->index('symbol');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instrument_prices');
    }
};

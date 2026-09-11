<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Terzo tipo di movimento: costo puro (bollo, custodia, gestione), che non compra né vende quote. */
    public function up(): void
    {
        Schema::table('investment_transactions', function (Blueprint $table) {
            $table->enum('side', ['buy', 'sell', 'fee'])->change();
        });
    }

    public function down(): void
    {
        Schema::table('investment_transactions', function (Blueprint $table) {
            $table->enum('side', ['buy', 'sell'])->change();
        });
    }
};

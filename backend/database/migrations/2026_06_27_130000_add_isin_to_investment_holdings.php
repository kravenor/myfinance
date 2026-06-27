<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investment_holdings', function (Blueprint $table) {
            // Identificatore stabile dello strumento (12 char). Il symbol Yahoo
            // usato per il fetch ne deriva (un ISIN può avere più quotazioni).
            $table->char('isin', 12)->nullable()->after('symbol');
        });
    }

    public function down(): void
    {
        Schema::table('investment_holdings', function (Blueprint $table) {
            $table->dropColumn('isin');
        });
    }
};

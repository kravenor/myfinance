<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investment_holdings', function (Blueprint $table) {
            // Per la raccolta dei symbol distinti da quotare (InvestmentPriceResolver / fetcher).
            $table->index('symbol');
        });
    }

    public function down(): void
    {
        Schema::table('investment_holdings', function (Blueprint $table) {
            $table->dropIndex(['symbol']);
        });
    }
};

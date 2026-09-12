<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investment_holdings', function (Blueprint $table) {
            $table->enum('asset_type', ['stock', 'etf', 'fund', 'bond', 'crypto', 'commodity', 'certificate', 'cash', 'other'])
                ->default('etf')->change();
        });
    }

    public function down(): void
    {
        Schema::table('investment_holdings', function (Blueprint $table) {
            $table->enum('asset_type', ['stock', 'etf', 'fund', 'bond', 'crypto', 'commodity', 'cash', 'other'])
                ->default('etf')->change();
        });
    }
};

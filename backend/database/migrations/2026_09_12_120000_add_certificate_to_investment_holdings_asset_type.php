<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE investment_holdings MODIFY asset_type ENUM('stock', 'etf', 'fund', 'bond', 'crypto', 'commodity', 'certificate', 'cash', 'other') NOT NULL DEFAULT 'etf'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE investment_holdings MODIFY asset_type ENUM('stock', 'etf', 'fund', 'bond', 'crypto', 'commodity', 'cash', 'other') NOT NULL DEFAULT 'etf'");
    }
};

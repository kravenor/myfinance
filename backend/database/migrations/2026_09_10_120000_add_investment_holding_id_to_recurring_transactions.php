<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_transactions', function (Blueprint $table) {
            $table->foreignId('investment_holding_id')->nullable()->after('transfer_account_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('recurring_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('investment_holding_id');
        });
    }
};

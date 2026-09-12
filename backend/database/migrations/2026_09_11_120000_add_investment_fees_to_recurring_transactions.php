<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_transactions', function (Blueprint $table) {
            // Costi della rata PAC: scalati dall'importo prima di derivare le quote.
            $table->decimal('investment_fees', 15, 2)->default(0)->after('investment_holding_id');
        });
    }

    public function down(): void
    {
        Schema::table('recurring_transactions', function (Blueprint $table) {
            $table->dropColumn('investment_fees');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Importo accreditato sul conto destinazione (nella valuta del
            // conto destinazione) per i transfer cross-valuta. Null quando
            // non è un transfer o quando coincide con `amount`.
            $table->decimal('transfer_amount', 15, 2)->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('transfer_amount');
        });
    }
};

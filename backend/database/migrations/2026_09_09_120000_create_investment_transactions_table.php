<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('investment_holding_id')->constrained()->cascadeOnDelete();
            $table->enum('side', ['buy', 'sell']);
            $table->date('occurred_at');
            // Stessa precisione delle colonne dell'holding: le quote di un PAC sono frazionarie.
            $table->decimal('quantity', 24, 8);
            $table->decimal('price', 24, 8);
            // Commissioni nella valuta dell'holding: alzano il costo sui buy, abbassano il ricavo sui sell.
            $table->decimal('fees', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            // Ordine con cui HoldingPositionRecalculator ripercorre il registro.
            $table->index(['investment_holding_id', 'occurred_at', 'id'], 'investment_transactions_holding_occurred_id_index');
        });

        Schema::table('investment_holdings', function (Blueprint $table) {
            // Cache derivate dal registro, ricalcolate a ogni scrittura di un movimento:
            // evitano di riattraversare i movimenti in index/overview (N+1).
            $table->decimal('realized_pl', 15, 2)->default(0)->after('avg_cost');
            $table->decimal('net_invested', 15, 2)->default(0)->after('realized_pl');
        });
    }

    public function down(): void
    {
        Schema::table('investment_holdings', function (Blueprint $table) {
            $table->dropColumn(['realized_pl', 'net_invested']);
        });

        Schema::dropIfExists('investment_transactions');
    }
};

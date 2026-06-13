<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investment_holdings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('symbol', 40)->nullable();
            $table->enum('asset_type', ['stock', 'etf', 'fund', 'bond', 'crypto', 'commodity', 'cash', 'other'])
                ->default('etf');
            $table->string('currency', 3)->default('EUR');
            // Quantità e prezzi per unità nella valuta `currency`. Precisione alta per crypto.
            $table->decimal('quantity', 24, 8)->default(0);
            $table->decimal('avg_cost', 24, 8)->default(0);
            $table->decimal('last_price', 24, 8)->nullable();
            $table->timestamp('last_price_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investment_holdings');
    }
};

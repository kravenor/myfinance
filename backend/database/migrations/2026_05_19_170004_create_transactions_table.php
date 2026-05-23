<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('transfer_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->enum('type', ['income', 'expense', 'transfer']);
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('EUR');
            $table->date('occurred_at');
            $table->string('description')->nullable();
            $table->text('notes')->nullable();
            $table->string('external_id')->nullable()->index();
            $table->foreignId('recurring_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'occurred_at']);
            $table->index(['account_id', 'occurred_at']);
            $table->index(['category_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};

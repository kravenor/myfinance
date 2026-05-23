<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('transfer_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->enum('type', ['income', 'expense', 'transfer']);
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('EUR');
            $table->string('description')->nullable();
            $table->enum('cadence', ['daily', 'weekly', 'biweekly', 'monthly', 'quarterly', 'yearly']);
            $table->unsignedTinyInteger('interval')->default(1);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->date('next_run_at');
            $table->date('last_run_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'is_active', 'next_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_transactions');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('type', ['cash', 'bank', 'card', 'investment', 'other'])->default('bank');
            $table->string('currency', 3)->default('EUR');
            $table->decimal('initial_balance', 15, 2)->default(0);
            $table->string('color', 7)->nullable();
            $table->string('icon', 64)->nullable();
            $table->boolean('is_archived')->default(false);
            $table->boolean('include_in_net_worth')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_archived']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};

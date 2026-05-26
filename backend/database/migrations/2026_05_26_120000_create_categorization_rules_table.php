<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categorization_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->enum('match_type', ['contains', 'starts_with', 'equals', 'regex'])->default('contains');
            $table->string('pattern', 255);
            $table->enum('applies_to_type', ['any', 'income', 'expense'])->default('any');
            $table->unsignedSmallInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('times_applied')->default(0);
            $table->timestamp('last_applied_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_active', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categorization_rules');
    }
};

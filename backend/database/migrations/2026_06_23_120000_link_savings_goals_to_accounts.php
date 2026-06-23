<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('savings_goals', function (Blueprint $table) {
            $table->foreignId('account_id')->nullable()->after('currency')->constrained()->nullOnDelete();
            $table->enum('recurrence', ['none', 'weekly', 'monthly', 'yearly'])->default('none')->after('target_date');
            $table->date('start_date')->nullable()->after('recurrence');
        });

        // Il progresso ora è calcolato live dalle transazioni del conto collegato:
        // il registro standalone dei movimenti non serve più.
        Schema::dropIfExists('savings_goal_movements');
    }

    public function down(): void
    {
        Schema::table('savings_goals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('account_id');
            $table->dropColumn(['recurrence', 'start_date']);
        });

        Schema::create('savings_goal_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('savings_goal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('direction', ['in', 'out']);
            $table->decimal('amount', 15, 2);
            $table->date('occurred_at');
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index(['savings_goal_id', 'occurred_at']);
        });
    }
};

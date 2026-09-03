<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scenario_items', function (Blueprint $table) {
            // Gli item esistenti sono tutti uscite: il default conserva il loro significato.
            $table->enum('type', ['expense', 'income'])->default('expense')->after('scenario_id');
        });
    }

    public function down(): void
    {
        Schema::table('scenario_items', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};

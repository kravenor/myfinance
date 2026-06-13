<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('currency', 3);
            // Unità di `currency` per 1 unità di valuta pivot (default EUR).
            $table->decimal('rate', 20, 10);
            $table->timestamps();

            $table->unique(['date', 'currency']);
            $table->index('currency');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};

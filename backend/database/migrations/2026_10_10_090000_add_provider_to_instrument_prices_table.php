<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le quote diventano per (provider, symbol, as_of): prima il provider lo sceglieva
 * l'asset_type di un holding qualsiasi con quel simbolo, anche di un altro utente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instrument_prices', function (Blueprint $table) {
            $table->string('provider', 20)->nullable()->after('id');
        });

        // Provider delle righe esistenti dagli holding con quel simbolo. Un simbolo con
        // più provider è ambiguo (non si sa chi l'ha scaricato) e uno senza holding è orfano:
        // in entrambi i casi le righe si cancellano, prices:fetch e prices:backfill le riscaricano.
        $map = (array) config('finance.prices.providers', []);
        $providersBySymbol = DB::table('investment_holdings')
            ->whereNotNull('symbol')
            ->distinct()
            ->get(['symbol', 'asset_type'])
            ->groupBy('symbol')
            ->map(fn ($rows) => $rows->map(fn ($r) => $map[$r->asset_type] ?? null)->filter()->unique()->values());

        foreach ($providersBySymbol as $symbol => $providers) {
            if ($providers->count() === 1) {
                DB::table('instrument_prices')->where('symbol', $symbol)->update(['provider' => $providers->first()]);
            }
        }
        DB::table('instrument_prices')->whereNull('provider')->delete();

        Schema::table('instrument_prices', function (Blueprint $table) {
            $table->string('provider', 20)->nullable(false)->change();
            $table->dropUnique(['symbol', 'as_of']);
            $table->unique(['provider', 'symbol', 'as_of']);
        });
    }

    public function down(): void
    {
        // Senza provider due quote dello stesso simbolo e giorno collidono: resta la prima.
        $duplicates = DB::table('instrument_prices')
            ->select('symbol', 'as_of', DB::raw('MIN(id) as keep_id'))
            ->groupBy('symbol', 'as_of')
            ->havingRaw('COUNT(*) > 1')
            ->get();
        foreach ($duplicates as $d) {
            DB::table('instrument_prices')
                ->where('symbol', $d->symbol)->where('as_of', $d->as_of)->where('id', '!=', $d->keep_id)
                ->delete();
        }

        Schema::table('instrument_prices', function (Blueprint $table) {
            $table->dropUnique(['provider', 'symbol', 'as_of']);
            $table->unique(['symbol', 'as_of']);
            $table->dropColumn('provider');
        });
    }
};

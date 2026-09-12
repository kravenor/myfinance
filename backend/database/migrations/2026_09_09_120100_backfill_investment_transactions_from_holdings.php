<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Le holding esistenti sono una fotografia (quantity + avg_cost) senza storico.
 * Con il registro attivo la posizione diventa derivata, quindi ognuna riceve un
 * movimento di apertura equivalente: senza, al primo ricalcolo andrebbero a zero.
 */
return new class extends Migration
{
    private const NOTE = 'Posizione iniziale (migrazione al registro movimenti)';

    public function up(): void
    {
        DB::table('investment_holdings')
            ->where('quantity', '>', 0)
            ->chunkById(200, function ($holdings) {
                $now = now();
                $rows = [];

                foreach ($holdings as $holding) {
                    $rows[] = [
                        'user_id' => $holding->user_id,
                        'investment_holding_id' => $holding->id,
                        'side' => 'buy',
                        'occurred_at' => Carbon::parse($holding->created_at)->toDateString(),
                        'quantity' => $holding->quantity,
                        'price' => $holding->avg_cost,
                        'fees' => 0,
                        'notes' => self::NOTE,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                DB::table('investment_transactions')->insert($rows);
            });

        // net_invested = costo della posizione di apertura; realized_pl resta 0.
        DB::table('investment_holdings')->update([
            'net_invested' => DB::raw('ROUND(quantity * avg_cost, 2)'),
        ]);
    }

    public function down(): void
    {
        DB::table('investment_transactions')->where('notes', self::NOTE)->delete();
    }
};

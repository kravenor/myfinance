<?php

namespace App\Services;

use App\Models\InvestmentHolding;
use App\Models\InvestmentTransaction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Ricalcola la posizione di un holding dal suo registro movimenti e la
 * persiste sulle colonne cache (quantity, avg_cost, realized_pl, net_invested),
 * che restano quindi la sola fonte letta da index/overview/report.
 *
 * È l'**unico scrittore** di quelle quattro colonne: se qualcos'altro le tocca
 * divergono in silenzio (cfr. docs/adr/0002-registro-movimenti-investimenti.md, D3).
 *
 * Metodo di costo: media ponderata. Una vendita non tocca il costo medio, ma
 * scarica il costo delle quote vendute e realizza la differenza.
 */
class HoldingPositionRecalculator
{
    /**
     * @throws ValidationException se il registro porta la quantità sotto zero
     */
    public function recalculate(InvestmentHolding $holding): void
    {
        $quantity = 0.0;
        $costBasis = 0.0;
        $realized = 0.0;
        $netInvested = 0.0;

        // ponytail: l'intero registro in memoria. Un PAC decennale fa ~120 righe;
        // se un holding arrivasse a decine di migliaia di movimenti, passare a chunk.
        /** @var Collection<int, InvestmentTransaction> $movements */
        $movements = $holding->transactions()
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        foreach ($movements as $movement) {
            $moved = (float) $movement->quantity;
            $netInvested += $movement->side === 'buy' ? $movement->cashFlow() : -$movement->cashFlow();

            if ($movement->side === 'buy') {
                $quantity += $moved;
                $costBasis += $movement->cashFlow();

                continue;
            }

            if ($moved - $quantity > 1e-8) {
                throw ValidationException::withMessages([
                    'quantity' => "Il registro venderebbe più quote di quante ne risultino possedute al {$movement->occurred_at->format('d/m/Y')}.",
                ]);
            }

            $avgCost = $quantity > 0 ? $costBasis / $quantity : 0.0;
            $realized += $movement->cashFlow() - ($moved * $avgCost);
            $quantity -= $moved;
            $costBasis -= $moved * $avgCost;
        }

        // Azzera i residui di arrotondamento quando la posizione è chiusa.
        if ($quantity <= 1e-8) {
            $quantity = 0.0;
            $costBasis = 0.0;
        }

        $holding->forceFill([
            'quantity' => $quantity,
            'avg_cost' => $quantity > 0 ? $costBasis / $quantity : 0.0,
            'realized_pl' => round($realized, 2),
            'net_invested' => round($netInvested, 2),
        ])->save();
    }

    /** Registra il movimento di apertura di un holding creato con una posizione già in essere. */
    public function openingMovement(InvestmentHolding $holding, float $quantity, float $avgCost): void
    {
        $holding->transactions()->create([
            'side' => 'buy',
            'occurred_at' => $holding->created_at?->toDateString() ?? now()->toDateString(),
            'quantity' => $quantity,
            'price' => $avgCost,
            'fees' => 0,
            'notes' => 'Posizione iniziale',
        ]);

        $this->recalculate($holding);
    }
}

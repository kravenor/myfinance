<?php

namespace App\Services;

use App\Models\InvestmentHolding;
use App\Models\InvestmentTransaction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
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
 * scarica il costo delle quote vendute e realizza la differenza. Un movimento
 * `fee` (bollo, custodia) non muove quote: alza il versato e abbassa il realizzato.
 */
class HoldingPositionRecalculator
{
    /**
     * @throws ValidationException se il registro porta la quantità sotto zero
     */
    public function recalculate(InvestmentHolding $holding): void
    {
        // ponytail: l'intero registro in memoria. Un PAC decennale fa ~120 righe;
        // se un holding arrivasse a decine di migliaia di movimenti, passare a chunk.
        /** @var Collection<int, InvestmentTransaction> $movements */
        $movements = $holding->transactions()
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $position = $this->positionAt($movements, null, strict: true);

        $holding->forceFill([
            'quantity' => $position['quantity'],
            'avg_cost' => $position['quantity'] > 0 ? $position['cost_basis'] / $position['quantity'] : 0.0,
            'realized_pl' => round($position['realized'], 2),
            'net_invested' => round($position['net_invested'], 2),
        ])->save();
    }

    /**
     * Posizione ricostruita dai movimenti (già ordinati per data) fino a $upTo incluso, o tutti se null.
     * Unica implementazione della regola del costo medio: la usano il ricalcolo e i report storici.
     *
     * @param  iterable<InvestmentTransaction>  $movements
     * @return array{quantity: float, cost_basis: float, realized: float, net_invested: float}
     *
     * @throws ValidationException con $strict, se una vendita supera le quote possedute
     */
    public function positionAt(iterable $movements, ?Carbon $upTo = null, bool $strict = false): array
    {
        $quantity = 0.0;
        $costBasis = 0.0;
        $realized = 0.0;
        $netInvested = 0.0;

        foreach ($movements as $movement) {
            if ($upTo !== null && $movement->occurred_at->gt($upTo)) {
                break;
            }

            $moved = (float) $movement->quantity;
            $cash = $movement->cashFlow();

            // Costo puro: non tocca quote né costo medio, è cassa immessa che
            // non compra nulla e come tale erode il realizzato.
            if ($movement->side === 'fee') {
                $netInvested += $cash;
                $realized -= $cash;

                continue;
            }

            $netInvested += $movement->side === 'buy' ? $cash : -$cash;

            if ($movement->side === 'buy') {
                $quantity += $moved;
                $costBasis += $cash;

                continue;
            }

            if ($strict && $moved - $quantity > 1e-8) {
                throw ValidationException::withMessages([
                    'quantity' => "Il registro venderebbe più quote di quante ne risultino possedute al {$movement->occurred_at->format('d/m/Y')}.",
                ]);
            }

            $avgCost = $quantity > 0 ? $costBasis / $quantity : 0.0;
            $realized += $cash - ($moved * $avgCost);
            $quantity -= $moved;
            $costBasis -= $moved * $avgCost;
        }

        // Azzera i residui di arrotondamento quando la posizione è chiusa.
        if ($quantity <= 1e-8) {
            $quantity = 0.0;
            $costBasis = 0.0;
        }

        return ['quantity' => $quantity, 'cost_basis' => $costBasis, 'realized' => $realized, 'net_invested' => $netInvested];
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

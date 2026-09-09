<?php

namespace App\Http\Controllers;

use App\Http\Requests\InvestmentTransaction\StoreInvestmentTransactionRequest;
use App\Http\Requests\InvestmentTransaction\UpdateInvestmentTransactionRequest;
use App\Http\Resources\InvestmentTransactionResource;
use App\Models\InvestmentHolding;
use App\Models\InvestmentTransaction;
use App\Services\HoldingPositionRecalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Registro movimenti di un holding. Ogni scrittura ricalcola la posizione
 * dentro la stessa transazione DB: se il registro risultasse incoerente
 * (più vendite che quote) il ricalcolo solleva e la scrittura viene annullata.
 */
class InvestmentTransactionController extends Controller
{
    public function __construct(private readonly HoldingPositionRecalculator $recalculator) {}

    public function index(Request $request, InvestmentHolding $investmentHolding): AnonymousResourceCollection
    {
        $this->authorize('view', $investmentHolding);

        $movements = $investmentHolding->transactions()
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 100));

        return InvestmentTransactionResource::collection($movements);
    }

    public function store(
        StoreInvestmentTransactionRequest $request,
        InvestmentHolding $investmentHolding
    ): JsonResponse {
        $this->authorize('update', $investmentHolding);

        $movement = DB::transaction(function () use ($request, $investmentHolding) {
            $movement = $investmentHolding->transactions()->create($request->validated());
            $this->recalculator->recalculate($investmentHolding);

            return $movement;
        });

        return (new InvestmentTransactionResource($movement))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(
        UpdateInvestmentTransactionRequest $request,
        InvestmentHolding $investmentHolding,
        InvestmentTransaction $transaction
    ): InvestmentTransactionResource {
        $this->authorize('update', $investmentHolding);

        DB::transaction(function () use ($request, $investmentHolding, $transaction) {
            $transaction->update($request->validated());
            $this->recalculator->recalculate($investmentHolding);
        });

        return new InvestmentTransactionResource($transaction);
    }

    public function destroy(
        InvestmentHolding $investmentHolding,
        InvestmentTransaction $transaction
    ): Response {
        $this->authorize('update', $investmentHolding);

        DB::transaction(function () use ($investmentHolding, $transaction) {
            $transaction->delete();
            $this->recalculator->recalculate($investmentHolding);
        });

        return response()->noContent();
    }
}

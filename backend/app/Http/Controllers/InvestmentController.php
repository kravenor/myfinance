<?php

namespace App\Http\Controllers;

use App\Models\InvestmentHolding;
use App\Services\InvestmentHistoryService;
use App\Services\InvestmentPriceFetcher;
use App\Services\InvestmentService;
use App\Services\Prices\YahooSymbolLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class InvestmentController extends Controller
{
    public function __construct(private readonly InvestmentService $service) {}

    public function overview(): JsonResponse
    {
        $this->authorize('viewAny', InvestmentHolding::class);

        return response()->json(['data' => $this->service->overview()]);
    }

    public function refreshPrices(InvestmentPriceFetcher $fetcher): JsonResponse
    {
        $this->authorize('viewAny', InvestmentHolding::class);

        return response()->json(['data' => ['updated' => $fetcher->fetchLatest()]]);
    }

    /**
     * Risolve ISIN/ticker/nome nei symbol Yahoo quotabili (per compilare il
     * campo `symbol` di una holding partendo dall'ISIN).
     */
    public function lookup(Request $request, YahooSymbolLookup $lookup): JsonResponse
    {
        $this->authorize('viewAny', InvestmentHolding::class);

        $validated = $request->validate([
            'q' => ['required', 'string', 'max:60'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        return response()->json([
            'data' => $lookup->search($validated['q'], $validated['currency'] ?? null),
        ]);
    }

    /**
     * Serie storica mensile versato vs valore, dal primo movimento del registro.
     * `?holding=` la restringe a un solo holding.
     */
    public function history(Request $request, InvestmentHistoryService $history): JsonResponse
    {
        $this->authorize('viewAny', InvestmentHolding::class);

        $validated = $request->validate([
            'holding' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        return response()->json($history->monthly(
            $validated['holding'] ?? null,
            isset($validated['from']) ? Carbon::parse($validated['from'])->startOfDay() : null,
            isset($validated['to']) ? Carbon::parse($validated['to'])->endOfDay() : null,
        ));
    }
}

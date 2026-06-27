<?php

namespace App\Http\Controllers;

use App\Models\InvestmentHolding;
use App\Services\InvestmentService;
use App\Services\Prices\YahooSymbolLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvestmentController extends Controller
{
    public function __construct(private readonly InvestmentService $service) {}

    public function overview(): JsonResponse
    {
        $this->authorize('viewAny', InvestmentHolding::class);

        return response()->json(['data' => $this->service->overview()]);
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
}

<?php

namespace App\Http\Controllers;

use App\Models\InvestmentHolding;
use App\Services\InvestmentService;
use Illuminate\Http\JsonResponse;

class InvestmentController extends Controller
{
    public function __construct(private readonly InvestmentService $service) {}

    public function overview(): JsonResponse
    {
        $this->authorize('viewAny', InvestmentHolding::class);

        return response()->json(['data' => $this->service->overview()]);
    }
}

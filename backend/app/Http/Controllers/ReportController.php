<?php

namespace App\Http\Controllers;

use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    public function summary(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return response()->json(['data' => $this->reports->summary($from, $to)]);
    }

    public function byCategory(Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['nullable', 'in:income,expense'],
        ]);
        [$from, $to] = $this->range($request);
        $type = $request->string('type', 'expense')->value();

        return response()->json([
            'data' => $this->reports->byCategory($from, $to, $type),
        ]);
    }

    public function timeline(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request, defaultMonths: 12);

        return response()->json([
            'data' => $this->reports->timeline($from, $to),
        ]);
    }

    public function netWorth(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request, defaultMonths: 12);

        return response()->json([
            'data' => $this->reports->netWorth($from, $to),
        ]);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(Request $request, int $defaultMonths = 1): array
    {
        $to = $request->filled('to')
            ? Carbon::parse($request->string('to'))->endOfDay()
            : Carbon::now()->endOfMonth();

        $from = $request->filled('from')
            ? Carbon::parse($request->string('from'))->startOfDay()
            : $to->copy()->startOfMonth()->subMonthsNoOverflow($defaultMonths - 1);

        return [$from, $to];
    }
}

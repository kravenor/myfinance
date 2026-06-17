<?php

namespace App\Http\Controllers;

use App\Services\ExpenseForecastService;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ReportController extends Controller
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly ExpenseForecastService $expenseForecast,
    ) {}

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

    public function byTag(Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['nullable', 'in:income,expense'],
        ]);
        [$from, $to] = $this->range($request);
        $type = $request->string('type', 'expense')->value();

        return response()->json([
            'data' => $this->reports->byTag($from, $to, $type),
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

    public function periodComparison(Request $request): JsonResponse
    {
        $request->validate([
            'unit' => ['nullable', 'in:month,year'],
            'reference' => ['nullable', 'date'],
        ]);

        $unit = $request->string('unit')->value() ?: 'month';
        $reference = $request->filled('reference')
            ? Carbon::parse($request->string('reference'))
            : Carbon::now();

        return response()->json([
            'data' => $this->reports->periodComparison($reference, $unit),
        ]);
    }

    public function categoryTrend(Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['nullable', 'in:income,expense'],
            'top' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        [$from, $to] = $this->range($request, defaultMonths: 12);
        $type = $request->string('type', 'expense')->value();
        $top = $request->integer('top') ?: 5;

        return response()->json([
            'data' => $this->reports->categoryTrend($from, $to, $type, $top),
        ]);
    }

    public function topTransactions(Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['nullable', 'in:income,expense,transfer'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        [$from, $to] = $this->range($request);
        $type = $request->string('type')->value();
        $limit = $request->integer('limit') ?: 10;

        return response()->json([
            'data' => $this->reports->topTransactions($from, $to, $type, $limit),
        ]);
    }

    public function cashFlowForecast(Request $request): JsonResponse
    {
        $request->validate([
            'months' => ['nullable', 'integer', 'min:1', 'max:24'],
        ]);

        $months = $request->integer('months') ?: 6;

        return response()->json([
            'data' => $this->reports->cashFlowForecast($months),
        ]);
    }

    public function expenseForecast(Request $request): JsonResponse
    {
        $request->validate([
            'months' => ['nullable', 'integer', 'min:1', 'max:24'],
            'scenario_id' => ['nullable', 'integer'],
        ]);

        $months = $request->integer('months') ?: 6;
        $scenarioId = $request->filled('scenario_id') ? $request->integer('scenario_id') : null;

        return response()->json([
            'data' => $this->expenseForecast->forecast($months, $scenarioId),
        ]);
    }

    public function expenseForecastCompare(Request $request): JsonResponse
    {
        $request->validate([
            'months' => ['nullable', 'integer', 'min:1', 'max:24'],
            'scenario_ids' => ['nullable', 'array'],
            'scenario_ids.*' => ['integer'],
        ]);

        $months = $request->integer('months') ?: 6;
        /** @var array<int>|null $ids */
        $ids = $request->has('scenario_ids') ? array_map('intval', (array) $request->input('scenario_ids')) : null;

        return response()->json([
            'data' => $this->expenseForecast->compare($months, $ids),
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

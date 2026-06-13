<?php

namespace App\Http\Controllers;

use App\Models\ExchangeRate;
use App\Services\CurrencyConverter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ExchangeRateController extends Controller
{
    public function __construct(private readonly CurrencyConverter $converter) {}

    /**
     * Tassi più recenti disponibili + elenco valute supportate.
     */
    public function index(): JsonResponse
    {
        $latestDate = ExchangeRate::query()->max('date');

        $rates = $latestDate
            ? ExchangeRate::query()
                ->whereDate('date', $latestDate)
                ->pluck('rate', 'currency')
                ->map(fn ($rate) => (float) $rate)
            : collect();

        return response()->json([
            'data' => [
                'pivot' => $this->converter->pivot(),
                'date' => $latestDate ? Carbon::parse($latestDate)->toDateString() : null,
                'currencies' => config('finance.currencies', []),
                'rates' => $rates,
            ],
        ]);
    }

    /**
     * Converte un importo tra due valute (al tasso della data indicata).
     */
    public function convert(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric'],
            'from' => ['required', 'string', 'size:3'],
            'to' => ['required', 'string', 'size:3'],
            'date' => ['nullable', 'date'],
        ]);

        $date = isset($validated['date']) ? Carbon::parse($validated['date']) : Carbon::now();
        $from = strtoupper($validated['from']);
        $to = strtoupper($validated['to']);

        $converted = $this->converter->convert((float) $validated['amount'], $from, $to, $date);

        return response()->json([
            'data' => [
                'amount' => (float) $validated['amount'],
                'from' => $from,
                'to' => $to,
                'date' => $date->toDateString(),
                'converted' => number_format($converted, 2, '.', ''),
                'rate' => number_format($this->converter->convert(1.0, $from, $to, $date), 10, '.', ''),
            ],
        ]);
    }
}

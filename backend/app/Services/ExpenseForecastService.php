<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\Category;
use App\Models\RecurringTransaction;
use App\Models\Scenario;
use App\Models\ScenarioItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Previsione spese future mese per mese + simulazione scenari.
 *
 * Per ogni categoria expense:
 * - se esiste un budget per quel mese → usa il budget come forecast base;
 * - altrimenti → somma delle ricorrenti expense che cadono nel mese.
 *
 * Le entrate previste vengono dalle ricorrenti income. Il "residuo a fine mese"
 * è `entrate − uscite_totali` (forecast base + extra scenario).
 */
class ExpenseForecastService
{
    public function __construct(private readonly CurrencyConverter $converter) {}

    /**
     * Forecast di un singolo scenario (o baseline se $scenarioId è null).
     *
     * @return array<string, mixed>
     */
    public function forecast(int $months, ?int $scenarioId = null): array
    {
        [$start, $end, $periods, $base] = $this->bootstrap($months);
        $incomePerMonth = $this->incomePerMonth($periods, $start, $end, $base);
        $scenario = $scenarioId !== null ? Scenario::query()->find($scenarioId) : null;

        return $this->build($periods, $base, $incomePerMonth, $scenario);
    }

    /**
     * Forecast comparativo: baseline + un forecast per ciascuno scenario attivo
     * (o per gli scenari richiesti). Restituisce un payload con la stessa forma
     * del singolo forecast per ognuno, più una "compact" matrice per il confronto.
     *
     * @param  array<int>|null  $scenarioIds  null = tutti gli scenari attivi
     * @return array<string, mixed>
     */
    public function compare(int $months, ?array $scenarioIds = null): array
    {
        [$start, $end, $periods, $base] = $this->bootstrap($months);
        $incomePerMonth = $this->incomePerMonth($periods, $start, $end, $base);

        $query = Scenario::query()->orderBy('name');
        if ($scenarioIds !== null && $scenarioIds !== []) {
            $query->whereIn('id', $scenarioIds);
        } else {
            $query->where('is_active', true);
        }
        $scenarios = $query->get();

        $baseline = $this->build($periods, $base, $incomePerMonth, null);

        $scenariosForecast = $scenarios
            ->map(fn (Scenario $s) => $this->build($periods, $base, $incomePerMonth, $s))
            ->all();

        return [
            'base_currency' => $base,
            'months' => $periods,
            'baseline' => $baseline,
            'scenarios' => $scenariosForecast,
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: array<int, string>, 3: string}
     */
    private function bootstrap(int $months): array
    {
        $months = max(1, min(24, $months));
        $start = Carbon::now()->startOfMonth();
        $end = $start->copy()->addMonthsNoOverflow($months - 1)->endOfMonth();
        $base = strtoupper(Auth::user()->currency);

        return [$start, $end, $this->periods($start, $end), $base];
    }

    /**
     * @param  array<int, string>  $periods
     * @param  array<string, float>  $incomePerMonth
     * @return array<string, mixed>
     */
    private function build(array $periods, string $base, array $incomePerMonth, ?Scenario $scenario): array
    {
        // Categorie expense (per dare nome/colore alle righe)
        $categories = Category::query()
            ->where('type', 'expense')
            ->orderBy('name')
            ->get(['id', 'name', 'color']);
        $catNames = $categories->pluck('name', 'id')->all();
        $catColors = $categories->pluck('color', 'id')->all();

        $byCategory = $this->initCategoryGrid($categories->pluck('id')->all(), $periods);

        // Ricorrenti expense per categoria
        $start = Carbon::parse($periods[0].'-01')->startOfMonth();
        $end = Carbon::parse(end($periods).'-01')->endOfMonth();
        $recurringTotals = $this->aggregateRecurring($periods, $start, $end, $base);
        foreach ($recurringTotals as $catId => $perPeriod) {
            foreach ($perPeriod as $period => $amount) {
                $byCategory[$catId][$period]['recurring'] = $amount;
            }
        }

        // Budget per categoria/mese
        $budgetMap = $this->aggregateBudgets($periods);
        foreach ($budgetMap as $catId => $perPeriod) {
            foreach ($perPeriod as $period => $amount) {
                if (! isset($byCategory[$catId])) {
                    $byCategory[$catId] = $this->emptyCells($periods);
                }
                $byCategory[$catId][$period]['budget'] = $amount;
            }
        }

        // Item dello scenario
        if ($scenario) {
            $scenarioTotals = $this->aggregateScenario($scenario, $periods, $start, $end, $base);
            foreach ($scenarioTotals as $catKey => $perPeriod) {
                if (! isset($byCategory[$catKey])) {
                    $byCategory[$catKey] = $this->emptyCells($periods);
                }
                foreach ($perPeriod as $period => $amount) {
                    $byCategory[$catKey][$period]['scenario'] = $amount;
                }
            }
        }

        $categoriesOut = [];
        $totalsByMonth = [];
        foreach ($periods as $period) {
            $totalsByMonth[$period] = [
                'recurring' => 0.0,
                'budget' => 0.0,
                'scenario' => 0.0,
                'forecast_base' => 0.0,
                'expense_total' => 0.0,
            ];
        }

        foreach ($byCategory as $catKey => $cells) {
            $rowTotal = 0.0;
            $monthly = [];
            foreach ($periods as $period) {
                $cell = $cells[$period];
                $forecastBase = $cell['budget'] > 0 ? $cell['budget'] : $cell['recurring'];
                $total = $forecastBase + $cell['scenario'];
                $budgetBreach = $cell['budget'] > 0 && $total > $cell['budget'] + 0.005;

                $monthly[] = [
                    'period' => $period,
                    'recurring' => $this->fmt($cell['recurring']),
                    'budget' => $cell['budget'] > 0 ? $this->fmt($cell['budget']) : null,
                    'scenario' => $this->fmt($cell['scenario']),
                    'forecast_base' => $this->fmt($forecastBase),
                    'total' => $this->fmt($total),
                    'budget_breach' => $budgetBreach,
                ];

                $rowTotal += $total;
                $totalsByMonth[$period]['recurring'] += $cell['recurring'];
                $totalsByMonth[$period]['budget'] += $cell['budget'];
                $totalsByMonth[$period]['scenario'] += $cell['scenario'];
                $totalsByMonth[$period]['forecast_base'] += $forecastBase;
                $totalsByMonth[$period]['expense_total'] += $total;
            }

            if ($rowTotal <= 0.005) {
                continue;
            }

            $categoriesOut[] = [
                'category_id' => $catKey === 'uncategorized' ? null : (int) $catKey,
                'category_name' => $catKey === 'uncategorized'
                    ? 'Senza categoria'
                    : ($catNames[$catKey] ?? '—'),
                'color' => $catKey === 'uncategorized' ? null : ($catColors[$catKey] ?? null),
                'total' => $this->fmt($rowTotal),
                'monthly' => $monthly,
            ];
        }

        usort($categoriesOut, fn ($a, $b) => (float) $b['total'] <=> (float) $a['total']);

        // Totali per mese con income e net residuo
        $totalsOut = [];
        $sumIncome = 0.0;
        $sumExpense = 0.0;
        $sumNet = 0.0;
        $minNet = null;
        $minNetPeriod = null;

        foreach ($periods as $period) {
            $t = $totalsByMonth[$period];
            $income = $incomePerMonth[$period] ?? 0.0;
            $net = $income - $t['expense_total'];

            $sumIncome += $income;
            $sumExpense += $t['expense_total'];
            $sumNet += $net;
            if ($minNet === null || $net < $minNet) {
                $minNet = $net;
                $minNetPeriod = $period;
            }

            $totalsOut[] = [
                'period' => $period,
                'income' => $this->fmt($income),
                'recurring' => $this->fmt($t['recurring']),
                'budget' => $this->fmt($t['budget']),
                'scenario' => $this->fmt($t['scenario']),
                'forecast_base' => $this->fmt($t['forecast_base']),
                'expense_total' => $this->fmt($t['expense_total']),
                'net' => $this->fmt($net),
            ];
        }

        return [
            'base_currency' => $base,
            'months' => $periods,
            'categories' => $categoriesOut,
            'totals_by_month' => $totalsOut,
            'summary' => [
                'total_income' => $this->fmt($sumIncome),
                'total_expense' => $this->fmt($sumExpense),
                'total_net' => $this->fmt($sumNet),
                'min_monthly_net' => $minNet !== null ? $this->fmt($minNet) : '0.00',
                'min_monthly_net_period' => $minNetPeriod,
                'months_count' => count($periods),
            ],
            'scenario' => $scenario ? [
                'id' => $scenario->id,
                'name' => $scenario->name,
                'color' => $scenario->color,
                'is_active' => $scenario->is_active,
            ] : null,
        ];
    }

    /**
     * Entrate previste per mese: somma delle ricorrenti income attive.
     *
     * @param  array<int, string>  $periods
     * @return array<string, float>
     */
    private function incomePerMonth(array $periods, Carbon $start, Carbon $end, string $base): array
    {
        $valid = array_flip($periods);
        $totals = array_fill_keys($periods, 0.0);

        $recurrings = RecurringTransaction::query()
            ->where('is_active', true)
            ->where('type', 'income')
            ->get();

        foreach ($recurrings as $r) {
            $cursor = $r->next_run_at->copy();
            $interval = max(1, (int) $r->interval);
            while ($cursor->lte($end)) {
                if ($r->ends_on && $cursor->gt($r->ends_on)) {
                    break;
                }
                if ($cursor->gte($start)) {
                    $key = $cursor->format('Y-m');
                    if (isset($valid[$key])) {
                        $totals[$key] += $this->converter->convert((float) $r->amount, $r->currency, $base, $cursor);
                    }
                }
                $cursor = $this->advance($cursor, $r->cadence, $interval);
            }
        }

        return $totals;
    }

    /**
     * @return array<int, string>
     */
    private function periods(Carbon $start, Carbon $end): array
    {
        $cursor = $start->copy();
        $out = [];
        while ($cursor->lte($end)) {
            $out[] = $cursor->format('Y-m');
            $cursor->addMonthNoOverflow();
        }

        return $out;
    }

    /**
     * @param  array<int>  $categoryIds
     * @param  array<int, string>  $periods
     * @return array<int|string, array<string, array{recurring: float, budget: float, scenario: float}>>
     */
    private function initCategoryGrid(array $categoryIds, array $periods): array
    {
        $grid = [];
        foreach ($categoryIds as $cid) {
            $grid[$cid] = $this->emptyCells($periods);
        }

        return $grid;
    }

    /**
     * @param  array<int, string>  $periods
     * @return array<string, array{recurring: float, budget: float, scenario: float}>
     */
    private function emptyCells(array $periods): array
    {
        $cells = [];
        foreach ($periods as $period) {
            $cells[$period] = ['recurring' => 0.0, 'budget' => 0.0, 'scenario' => 0.0];
        }

        return $cells;
    }

    /**
     * @param  array<int, string>  $periods
     * @return array<int, array<string, float>>
     */
    private function aggregateRecurring(array $periods, Carbon $start, Carbon $end, string $base): array
    {
        $totals = [];
        $valid = array_flip($periods);

        $recurrings = RecurringTransaction::query()
            ->where('is_active', true)
            ->where('type', 'expense')
            ->whereNotNull('category_id')
            ->get();

        foreach ($recurrings as $r) {
            $cursor = $r->next_run_at->copy();
            $interval = max(1, (int) $r->interval);
            while ($cursor->lte($end)) {
                if ($r->ends_on && $cursor->gt($r->ends_on)) {
                    break;
                }
                if ($cursor->gte($start)) {
                    $key = $cursor->format('Y-m');
                    if (isset($valid[$key])) {
                        $amount = $this->converter->convert((float) $r->amount, $r->currency, $base, $cursor);
                        $cid = (int) $r->category_id;
                        $totals[$cid][$key] = ($totals[$cid][$key] ?? 0.0) + $amount;
                    }
                }
                $cursor = $this->advance($cursor, $r->cadence, $interval);
            }
        }

        return $totals;
    }

    /**
     * @param  array<int, string>  $periods
     * @return array<int, array<string, float>>
     */
    private function aggregateBudgets(array $periods): array
    {
        $pairs = collect($periods)->map(function (string $period): array {
            [$year, $month] = explode('-', $period);

            return ['year' => (int) $year, 'month' => (int) $month];
        });

        $years = $pairs->pluck('year')->unique()->all();
        $months = $pairs->pluck('month')->unique()->all();

        $budgets = Budget::query()
            ->whereIn('year', $years)
            ->whereIn('month', $months)
            ->get(['category_id', 'year', 'month', 'amount']);

        $out = [];
        $validPeriods = array_flip($periods);
        foreach ($budgets as $b) {
            $key = sprintf('%04d-%02d', $b->year, $b->month);
            if (! isset($validPeriods[$key])) {
                continue;
            }
            $out[$b->category_id][$key] = (float) $b->amount;
        }

        return $out;
    }

    /**
     * @param  array<int, string>  $periods
     * @return array<int|string, array<string, float>>
     */
    private function aggregateScenario(Scenario $scenario, array $periods, Carbon $start, Carbon $end, string $base): array
    {
        $totals = [];
        $valid = array_flip($periods);

        $items = ScenarioItem::query()
            ->where('scenario_id', $scenario->id)
            ->get();

        foreach ($items as $item) {
            $cursor = $item->starts_on->copy();
            $interval = max(1, (int) $item->interval);
            $cap = $item->ends_on?->copy();

            $occurrences = $this->scenarioOccurrences($cursor, $cap, $end, $item->cadence, $interval);
            foreach ($occurrences as $occ) {
                if ($occ->lt($start)) {
                    continue;
                }
                $key = $occ->format('Y-m');
                if (! isset($valid[$key])) {
                    continue;
                }
                $amount = $this->converter->convert((float) $item->amount, $item->currency, $base, $occ);
                $catKey = $item->category_id ?? 'uncategorized';
                $totals[$catKey][$key] = ($totals[$catKey][$key] ?? 0.0) + $amount;
            }
        }

        return $totals;
    }

    /**
     * @return array<int, Carbon>
     */
    private function scenarioOccurrences(Carbon $from, ?Carbon $cap, Carbon $horizon, string $cadence, int $interval): array
    {
        if ($cadence === 'one_time') {
            return $from->lte($horizon) ? [$from->copy()] : [];
        }

        $out = [];
        $cursor = $from->copy();
        $end = $cap ? $cap->copy()->min($horizon) : $horizon->copy();

        while ($cursor->lte($end)) {
            $out[] = $cursor->copy();
            $cursor = $this->advance($cursor, $cadence, $interval);
        }

        return $out;
    }

    private function advance(Carbon $from, string $cadence, int $interval): Carbon
    {
        return match ($cadence) {
            'daily' => $from->copy()->addDays($interval),
            'weekly' => $from->copy()->addWeeks($interval),
            'biweekly' => $from->copy()->addWeeks(2 * $interval),
            'monthly' => $from->copy()->addMonthsNoOverflow($interval),
            'quarterly' => $from->copy()->addMonthsNoOverflow(3 * $interval),
            'yearly' => $from->copy()->addYearsNoOverflow($interval),
            'one_time' => $from->copy()->addYears(1000),
            default => throw new \UnexpectedValueException("Cadenza non supportata: {$cadence}"),
        };
    }

    private function fmt(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Requests\Budget\StoreBudgetRequest;
use App\Http\Requests\Budget\UpdateBudgetRequest;
use App\Http\Resources\BudgetResource;
use App\Models\Budget;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BudgetAlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

class BudgetController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Budget::class);

        $query = Budget::query()->orderBy('year')->orderBy('month');

        if ($request->filled('year')) {
            $query->where('year', $request->integer('year'));
        }

        if ($request->filled('month')) {
            $query->where('month', $request->integer('month'));
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->integer('category_id'));
        }

        $paginator = $query->paginate($request->integer('per_page', 50));

        $this->attachSpent($paginator->getCollection()->all());

        return BudgetResource::collection($paginator);
    }

    /**
     * Alert dei budget del periodo (warning >= 80%, exceeded >= 100%).
     */
    public function alerts(Request $request, BudgetAlertService $service): JsonResponse
    {
        $this->authorize('viewAny', Budget::class);

        $now = Carbon::now();
        $year = $request->filled('year') ? $request->integer('year') : $now->year;
        $month = $request->filled('month') ? $request->integer('month') : $now->month;

        /** @var User $user */
        $user = $request->user();
        $threshold = (float) $user->notificationPreference('budget_threshold');

        return response()->json(['data' => $service->alerts($year, $month, $threshold)]);
    }

    public function store(StoreBudgetRequest $request): JsonResponse
    {
        $this->authorize('create', Budget::class);

        $budget = Budget::create($request->validated());
        $this->attachSpent([$budget]);

        return (new BudgetResource($budget))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Budget $budget): BudgetResource
    {
        $this->authorize('view', $budget);

        $this->attachSpent([$budget]);

        return new BudgetResource($budget);
    }

    public function update(UpdateBudgetRequest $request, Budget $budget): BudgetResource
    {
        $this->authorize('update', $budget);

        $budget->update($request->validated());
        $this->attachSpent([$budget]);

        return new BudgetResource($budget);
    }

    public function destroy(Budget $budget): Response
    {
        $this->authorize('delete', $budget);

        $budget->delete();

        return response()->noContent();
    }

    /**
     * @param  array<int, Budget>  $budgets
     */
    private function attachSpent(array $budgets): void
    {
        foreach ($budgets as $budget) {
            $start = Carbon::createFromDate($budget->year, $budget->month, 1)->startOfDay();
            $end = $start->copy()->endOfMonth();

            $spent = Transaction::query()
                ->where('type', 'expense')
                ->where('category_id', $budget->category_id)
                ->whereBetween('occurred_at', [$start->toDateString(), $end->toDateString()])
                ->sum('amount');

            $budget->setAttribute('spent', $spent);
        }
    }
}

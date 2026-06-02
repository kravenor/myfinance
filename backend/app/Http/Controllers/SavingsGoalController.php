<?php

namespace App\Http\Controllers;

use App\Http\Requests\SavingsGoal\StoreSavingsGoalRequest;
use App\Http\Requests\SavingsGoal\UpdateSavingsGoalRequest;
use App\Http\Resources\SavingsGoalResource;
use App\Models\SavingsGoal;
use App\Services\SavingsGoalProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class SavingsGoalController extends Controller
{
    public function __construct(private readonly SavingsGoalProgressService $progress) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SavingsGoal::class);

        $query = SavingsGoal::query()
            ->withCount('movements')
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'completed' THEN 1 ELSE 2 END")
            ->orderBy('target_date')
            ->orderBy('name');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $paginator = $query->paginate($request->integer('per_page', 50));

        $this->progress->attachProgress($paginator->getCollection()->all());

        return SavingsGoalResource::collection($paginator);
    }

    public function store(StoreSavingsGoalRequest $request): JsonResponse
    {
        $this->authorize('create', SavingsGoal::class);

        $goal = SavingsGoal::create($request->validated());
        $this->progress->attachProgress([$goal]);

        return (new SavingsGoalResource($goal))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(SavingsGoal $savingsGoal): SavingsGoalResource
    {
        $this->authorize('view', $savingsGoal);

        $savingsGoal->loadCount('movements');
        $this->progress->attachProgress([$savingsGoal]);

        return new SavingsGoalResource($savingsGoal);
    }

    public function update(UpdateSavingsGoalRequest $request, SavingsGoal $savingsGoal): SavingsGoalResource
    {
        $this->authorize('update', $savingsGoal);

        $savingsGoal->update($request->validated());
        $savingsGoal->loadCount('movements');
        $this->progress->attachProgress([$savingsGoal]);

        return new SavingsGoalResource($savingsGoal);
    }

    public function destroy(SavingsGoal $savingsGoal): Response
    {
        $this->authorize('delete', $savingsGoal);

        $savingsGoal->delete();

        return response()->noContent();
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Requests\SavingsGoal\StoreSavingsGoalMovementRequest;
use App\Http\Requests\SavingsGoal\UpdateSavingsGoalMovementRequest;
use App\Http\Resources\SavingsGoalMovementResource;
use App\Models\SavingsGoal;
use App\Models\SavingsGoalMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class SavingsGoalMovementController extends Controller
{
    public function index(Request $request, SavingsGoal $savingsGoal): AnonymousResourceCollection
    {
        $this->authorize('view', $savingsGoal);

        $movements = $savingsGoal->movements()
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 50));

        return SavingsGoalMovementResource::collection($movements);
    }

    public function store(StoreSavingsGoalMovementRequest $request, SavingsGoal $savingsGoal): JsonResponse
    {
        $this->authorize('update', $savingsGoal);

        $movement = $savingsGoal->movements()->create($request->validated());

        return (new SavingsGoalMovementResource($movement))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(SavingsGoal $savingsGoal, SavingsGoalMovement $movement): SavingsGoalMovementResource
    {
        $this->authorize('view', $savingsGoal);

        return new SavingsGoalMovementResource($movement);
    }

    public function update(
        UpdateSavingsGoalMovementRequest $request,
        SavingsGoal $savingsGoal,
        SavingsGoalMovement $movement
    ): SavingsGoalMovementResource {
        $this->authorize('update', $savingsGoal);

        $movement->update($request->validated());

        return new SavingsGoalMovementResource($movement);
    }

    public function destroy(SavingsGoal $savingsGoal, SavingsGoalMovement $movement): Response
    {
        $this->authorize('update', $savingsGoal);

        $movement->delete();

        return response()->noContent();
    }
}

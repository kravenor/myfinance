<?php

namespace App\Http\Controllers;

use App\Http\Requests\Scenario\StoreScenarioItemRequest;
use App\Http\Requests\Scenario\UpdateScenarioItemRequest;
use App\Http\Resources\ScenarioItemResource;
use App\Models\Scenario;
use App\Models\ScenarioItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ScenarioItemController extends Controller
{
    public function index(Request $request, Scenario $scenario): AnonymousResourceCollection
    {
        $this->authorize('view', $scenario);

        $items = $scenario->items()
            ->orderBy('starts_on')
            ->orderBy('id')
            ->paginate($request->integer('per_page', 100));

        return ScenarioItemResource::collection($items);
    }

    public function store(StoreScenarioItemRequest $request, Scenario $scenario): JsonResponse
    {
        $this->authorize('update', $scenario);

        $item = $scenario->items()->create($request->validated());

        return (new ScenarioItemResource($item))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Scenario $scenario, ScenarioItem $item): ScenarioItemResource
    {
        $this->authorize('view', $scenario);

        return new ScenarioItemResource($item);
    }

    public function update(
        UpdateScenarioItemRequest $request,
        Scenario $scenario,
        ScenarioItem $item
    ): ScenarioItemResource {
        $this->authorize('update', $scenario);

        $item->update($request->validated());

        return new ScenarioItemResource($item);
    }

    public function destroy(Scenario $scenario, ScenarioItem $item): Response
    {
        $this->authorize('update', $scenario);

        $item->delete();

        return response()->noContent();
    }
}

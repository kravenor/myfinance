<?php

namespace App\Http\Controllers;

use App\Http\Requests\Scenario\StoreScenarioRequest;
use App\Http\Requests\Scenario\UpdateScenarioRequest;
use App\Http\Resources\ScenarioResource;
use App\Models\Scenario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ScenarioController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Scenario::class);

        $query = Scenario::query()
            ->withCount('items')
            ->orderByDesc('is_active')
            ->orderBy('name');

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return ScenarioResource::collection(
            $query->paginate($request->integer('per_page', 50))
        );
    }

    public function store(StoreScenarioRequest $request): JsonResponse
    {
        $this->authorize('create', Scenario::class);

        $scenario = Scenario::create($request->validated());
        $scenario->refresh()->loadCount('items');

        return (new ScenarioResource($scenario))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Scenario $scenario): ScenarioResource
    {
        $this->authorize('view', $scenario);

        return new ScenarioResource(
            $scenario->load('items')->loadCount('items')
        );
    }

    public function update(UpdateScenarioRequest $request, Scenario $scenario): ScenarioResource
    {
        $this->authorize('update', $scenario);

        $scenario->update($request->validated());

        return new ScenarioResource($scenario->loadCount('items'));
    }

    public function destroy(Scenario $scenario): Response
    {
        $this->authorize('delete', $scenario);

        $scenario->delete();

        return response()->noContent();
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Requests\CategorizationRule\StoreCategorizationRuleRequest;
use App\Http\Requests\CategorizationRule\UpdateCategorizationRuleRequest;
use App\Http\Resources\CategorizationRuleResource;
use App\Models\CategorizationRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class CategorizationRuleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CategorizationRule::class);

        $query = CategorizationRule::query()->with('category');

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->integer('category_id'));
        }

        $query->orderBy('priority')->orderBy('id');

        return CategorizationRuleResource::collection(
            $query->paginate($request->integer('per_page', 25))
        );
    }

    public function store(StoreCategorizationRuleRequest $request): JsonResponse
    {
        $this->authorize('create', CategorizationRule::class);

        $rule = CategorizationRule::create($request->validated());
        $rule->load('category');

        return (new CategorizationRuleResource($rule))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(CategorizationRule $categorizationRule): CategorizationRuleResource
    {
        $this->authorize('view', $categorizationRule);

        $categorizationRule->load('category');

        return new CategorizationRuleResource($categorizationRule);
    }

    public function update(
        UpdateCategorizationRuleRequest $request,
        CategorizationRule $categorizationRule,
    ): CategorizationRuleResource {
        $this->authorize('update', $categorizationRule);

        $categorizationRule->update($request->validated());
        $categorizationRule->load('category');

        return new CategorizationRuleResource($categorizationRule);
    }

    public function destroy(CategorizationRule $categorizationRule): Response
    {
        $this->authorize('delete', $categorizationRule);

        $categorizationRule->delete();

        return response()->noContent();
    }
}

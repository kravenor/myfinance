<?php

namespace App\Http\Controllers;

use App\Http\Requests\InvestmentHolding\StoreInvestmentHoldingRequest;
use App\Http\Requests\InvestmentHolding\UpdateInvestmentHoldingRequest;
use App\Http\Resources\InvestmentHoldingResource;
use App\Models\InvestmentHolding;
use App\Services\InvestmentPriceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class InvestmentHoldingController extends Controller
{
    public function index(Request $request, InvestmentPriceResolver $priceResolver): AnonymousResourceCollection
    {
        $this->authorize('viewAny', InvestmentHolding::class);

        $query = InvestmentHolding::query()->orderBy('name');

        if ($request->filled('account_id')) {
            $query->where('account_id', $request->integer('account_id'));
        }

        if ($request->filled('asset_type')) {
            $query->where('asset_type', $request->string('asset_type'));
        }

        $holdings = $query->paginate($request->integer('per_page', 100));
        $priceResolver->hydrate(collect($holdings->items()));

        return InvestmentHoldingResource::collection($holdings);
    }

    public function store(StoreInvestmentHoldingRequest $request): JsonResponse
    {
        $this->authorize('create', InvestmentHolding::class);

        $holding = InvestmentHolding::create($request->validated());

        return (new InvestmentHoldingResource($holding))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(InvestmentHolding $investmentHolding, InvestmentPriceResolver $priceResolver): InvestmentHoldingResource
    {
        $this->authorize('view', $investmentHolding);

        $priceResolver->hydrate(collect([$investmentHolding]));

        return new InvestmentHoldingResource($investmentHolding);
    }

    public function update(UpdateInvestmentHoldingRequest $request, InvestmentHolding $investmentHolding): InvestmentHoldingResource
    {
        $this->authorize('update', $investmentHolding);

        $investmentHolding->update($request->validated());

        return new InvestmentHoldingResource($investmentHolding);
    }

    public function destroy(InvestmentHolding $investmentHolding): Response
    {
        $this->authorize('delete', $investmentHolding);

        $investmentHolding->delete();

        return response()->noContent();
    }
}

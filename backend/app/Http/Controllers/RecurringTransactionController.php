<?php

namespace App\Http\Controllers;

use App\Http\Requests\RecurringTransaction\StoreRecurringTransactionRequest;
use App\Http\Requests\RecurringTransaction\UpdateRecurringTransactionRequest;
use App\Http\Resources\RecurringTransactionResource;
use App\Models\Account;
use App\Models\RecurringTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class RecurringTransactionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', RecurringTransaction::class);

        $query = RecurringTransaction::query()->orderBy('next_run_at');

        if ($request->filled('account_id')) {
            $query->where('account_id', $request->integer('account_id'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        return RecurringTransactionResource::collection(
            $query->paginate($request->integer('per_page', 25))
        );
    }

    public function store(StoreRecurringTransactionRequest $request): JsonResponse
    {
        $this->authorize('create', RecurringTransaction::class);

        $data = $request->validated();
        $data['interval'] = $data['interval'] ?? 1;
        $data['next_run_at'] = $data['next_run_at'] ?? $data['starts_on'];
        $data['currency'] = Account::findOrFail($data['account_id'])->currency;

        $recurring = RecurringTransaction::create($data)->refresh();

        return (new RecurringTransactionResource($recurring))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(RecurringTransaction $recurringTransaction): RecurringTransactionResource
    {
        $this->authorize('view', $recurringTransaction);

        return new RecurringTransactionResource($recurringTransaction);
    }

    public function update(UpdateRecurringTransactionRequest $request, RecurringTransaction $recurringTransaction): RecurringTransactionResource
    {
        $this->authorize('update', $recurringTransaction);

        $data = $request->validated();
        $accountId = $data['account_id'] ?? $recurringTransaction->account_id;
        $data['currency'] = Account::findOrFail($accountId)->currency;

        $recurringTransaction->update($data);

        return new RecurringTransactionResource($recurringTransaction);
    }

    public function destroy(RecurringTransaction $recurringTransaction): Response
    {
        $this->authorize('delete', $recurringTransaction);

        $recurringTransaction->delete();

        return response()->noContent();
    }
}

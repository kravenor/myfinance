<?php

namespace App\Http\Controllers;

use App\Http\Requests\Account\StoreAccountRequest;
use App\Http\Requests\Account\UpdateAccountRequest;
use App\Http\Resources\AccountResource;
use App\Models\Account;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class AccountController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Account::class);

        $query = Account::query()->orderBy('name');

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        if ($request->has('archived')) {
            $query->where('is_archived', $request->boolean('archived'));
        }

        return AccountResource::collection(
            $query->paginate($request->integer('per_page', 25))
        );
    }

    public function store(StoreAccountRequest $request): JsonResponse
    {
        $this->authorize('create', Account::class);

        $account = Account::create($request->validated());

        return (new AccountResource($account))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Account $account): AccountResource
    {
        $this->authorize('view', $account);

        return new AccountResource($account);
    }

    public function update(UpdateAccountRequest $request, Account $account): AccountResource
    {
        $this->authorize('update', $account);

        $account->update($request->validated());

        return new AccountResource($account);
    }

    public function destroy(Account $account): Response
    {
        $this->authorize('delete', $account);

        $account->delete();

        return response()->noContent();
    }
}

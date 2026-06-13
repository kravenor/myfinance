<?php

namespace App\Http\Controllers;

use App\Http\Requests\Transaction\StoreTransactionRequest;
use App\Http\Requests\Transaction\UpdateTransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Account;
use App\Models\Transaction;
use App\Services\CurrencyConverter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TransactionController extends Controller
{
    public function __construct(private readonly CurrencyConverter $converter) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Transaction::class);

        $query = Transaction::query()
            ->with('tags')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        if ($request->filled('account_id')) {
            $query->where(function ($q) use ($request) {
                $accountId = $request->integer('account_id');
                $q->where('account_id', $accountId)
                    ->orWhere('transfer_account_id', $accountId);
            });
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->integer('category_id'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        if ($request->filled('search')) {
            $terms = preg_split('/\s+/', trim((string) $request->string('search')));

            $query->where(function ($q) use ($terms) {
                foreach ($terms as $term) {
                    if ($term === '') {
                        continue;
                    }

                    $like = '%'.addcslashes($term, '%_\\').'%';
                    $q->where('description', 'like', $like);
                }
            });
        }

        if ($request->filled('from')) {
            $query->whereDate('occurred_at', '>=', $request->date('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('occurred_at', '<=', $request->date('to'));
        }

        if ($request->filled('tag_id')) {
            $tagId = $request->integer('tag_id');
            $query->whereHas('tags', fn ($q) => $q->where('tags.id', $tagId));
        }

        return TransactionResource::collection(
            $query->paginate($request->integer('per_page', 25))
        );
    }

    public function store(StoreTransactionRequest $request): JsonResponse
    {
        $this->authorize('create', Transaction::class);

        $data = $request->validated();
        $tagIds = $data['tag_ids'] ?? null;
        unset($data['tag_ids']);
        $data = $this->resolveCurrency($data, null);

        $transaction = DB::transaction(function () use ($data, $tagIds) {
            $transaction = Transaction::create($data);

            if ($tagIds !== null) {
                $transaction->tags()->sync($tagIds);
            }

            return $transaction->load('tags');
        });

        return (new TransactionResource($transaction))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Transaction $transaction): TransactionResource
    {
        $this->authorize('view', $transaction);

        return new TransactionResource($transaction->load('tags'));
    }

    public function update(UpdateTransactionRequest $request, Transaction $transaction): TransactionResource
    {
        $this->authorize('update', $transaction);

        $data = $request->validated();
        $tagIds = array_key_exists('tag_ids', $data) ? $data['tag_ids'] : null;
        unset($data['tag_ids']);
        $data = $this->resolveCurrency($data, $transaction);

        DB::transaction(function () use ($transaction, $data, $tagIds, $request) {
            $transaction->update($data);

            if ($request->has('tag_ids')) {
                $transaction->tags()->sync($tagIds ?? []);
            }
        });

        return new TransactionResource($transaction->load('tags'));
    }

    public function destroy(Transaction $transaction): Response
    {
        $this->authorize('delete', $transaction);

        $transaction->delete();

        return response()->noContent();
    }

    /**
     * Allinea la valuta della transazione a quella del conto sorgente e
     * calcola `transfer_amount` per i transfer (conversione al tasso della
     * data di registrazione se le valute differiscono, salvo override).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolveCurrency(array $data, ?Transaction $existing): array
    {
        $accountId = $data['account_id'] ?? $existing?->account_id;
        $account = Account::findOrFail($accountId);
        $data['currency'] = $account->currency;

        $type = $data['type'] ?? $existing?->type;
        $transferAccountId = array_key_exists('transfer_account_id', $data)
            ? $data['transfer_account_id']
            : $existing?->transfer_account_id;

        if ($type !== 'transfer' || ! $transferAccountId) {
            $data['transfer_amount'] = null;

            return $data;
        }

        // Override manuale del tasso: rispettiamo l'importo indicato.
        if (array_key_exists('transfer_amount', $data) && $data['transfer_amount'] !== null) {
            return $data;
        }

        $dest = Account::findOrFail($transferAccountId);
        $amount = (float) ($data['amount'] ?? $existing?->amount);

        if (isset($data['occurred_at'])) {
            $date = Carbon::parse($data['occurred_at']);
        } elseif ($existing !== null) {
            $date = $existing->occurred_at;
        } else {
            $date = Carbon::now();
        }

        $converted = $dest->currency === $account->currency
            ? $amount
            : $this->converter->convert($amount, $account->currency, $dest->currency, $date);

        $data['transfer_amount'] = number_format($converted, 2, '.', '');

        return $data;
    }
}

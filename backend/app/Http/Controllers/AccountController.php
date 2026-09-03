<?php

namespace App\Http\Controllers;

use App\Http\Requests\Account\ReconcileAccountRequest;
use App\Http\Requests\Account\StoreAccountRequest;
use App\Http\Requests\Account\UpdateAccountRequest;
use App\Http\Resources\AccountResource;
use App\Http\Resources\TransactionResource;
use App\Models\Account;
use App\Models\Transaction;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

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

    /** Saldo che l'app calcola per il conto a una data: il termine di confronto. */
    public function reconciliation(Request $request, Account $account): JsonResponse
    {
        $this->authorize('view', $account);

        $date = $this->reconciliationDate($request->string('date')->value());

        return response()->json(['data' => [
            'account_id' => $account->id,
            'date' => $date->toDateString(),
            'currency' => $account->currency,
            'computed_balance' => $this->fmt($this->reports->balanceFor($account, $date)),
        ]]);
    }

    /**
     * Allinea il conto al saldo reale creando una transazione di rettifica per
     * la differenza. Idempotente per costruzione: dopo la rettifica il saldo
     * calcolato coincide col reale, quindi rieseguirla non crea nulla.
     */
    public function reconcile(ReconcileAccountRequest $request, Account $account): JsonResponse
    {
        $this->authorize('update', $account);

        if ($account->type === 'investment') {
            throw ValidationException::withMessages([
                'balance' => 'Il saldo di un conto investimenti è il valore di mercato delle holding: '
                    .'si allinea correggendo quantità e prezzi, non con una rettifica.',
            ]);
        }

        $date = $this->reconciliationDate($request->string('date')->value());
        $computed = $this->reports->balanceFor($account, $date);
        $real = (float) $request->validated('balance');
        $difference = round($real - $computed, 2);

        $payload = [
            'account_id' => $account->id,
            'date' => $date->toDateString(),
            'currency' => $account->currency,
            'computed_balance' => $this->fmt($computed),
            'real_balance' => $this->fmt($real),
            'difference' => $this->fmt($difference),
        ];

        // Sotto il centesimo non c'è niente da rettificare: meglio nessuna
        // transazione che una da 0,00.
        if (abs($difference) < 0.005) {
            return response()->json(['data' => $payload + ['adjusted' => false, 'transaction' => null]]);
        }

        $transaction = Transaction::create([
            'account_id' => $account->id,
            'category_id' => $request->validated('category_id'),
            'type' => $difference > 0 ? 'income' : 'expense',
            'amount' => abs($difference),
            'currency' => $account->currency,
            'occurred_at' => $date->toDateString(),
            'description' => $request->validated('description') ?: 'Rettifica saldo',
        ]);

        return response()->json(['data' => $payload + [
            'adjusted' => true,
            'transaction' => new TransactionResource($transaction),
        ]]);
    }

    /** La riconciliazione guarda il saldo a fine giornata, oggi per default. */
    private function reconciliationDate(?string $date): Carbon
    {
        return ($date ? Carbon::parse($date) : Carbon::now())->endOfDay();
    }

    private function fmt(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}

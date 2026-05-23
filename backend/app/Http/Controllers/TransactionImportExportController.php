<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Transaction;
use App\Services\TransactionExportService;
use App\Services\TransactionImportService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TransactionImportExportController extends Controller
{
    public function __construct(
        private readonly TransactionExportService $exporter,
        private readonly TransactionImportService $importer,
    ) {}

    public function export(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', Transaction::class);

        return $this->exporter->stream($request);
    }

    public function importPreview(Request $request): JsonResponse
    {
        $this->authorize('create', Transaction::class);

        $request->validate([
            'file' => ['required', 'file', 'mimetypes:text/csv,text/plain,application/csv', 'max:5120'],
        ]);

        return response()->json(['data' => $this->importer->preview($request->file('file'))]);
    }

    public function importCommit(Request $request): JsonResponse
    {
        $this->authorize('create', Transaction::class);

        $request->validate([
            'file' => ['required', 'file', 'mimetypes:text/csv,text/plain,application/csv', 'max:5120'],
            'account_id' => [
                'required',
                'integer',
                Rule::exists('accounts', 'id')->where(fn (Builder $q) => $q->where('user_id', Auth::id())),
            ],
            'mapping.date' => ['required', 'string'],
            'mapping.amount' => ['required', 'string'],
            'mapping.description' => ['nullable', 'string'],
            'mapping.type' => ['nullable', 'string'],
            'mapping.category' => ['nullable', 'string'],
            'date_format' => ['nullable', 'string', 'max:32'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        $accountId = (int) $request->integer('account_id');
        $currency = $request->string('currency')->value() ?: Account::query()->where('id', $accountId)->value('currency') ?: 'EUR';

        $result = $this->importer->import(
            file: $request->file('file'),
            accountId: $accountId,
            mapping: $request->input('mapping', []),
            dateFormat: $request->string('date_format')->value() ?: 'Y-m-d',
            currency: $currency,
        );

        return response()->json(['data' => $result]);
    }
}

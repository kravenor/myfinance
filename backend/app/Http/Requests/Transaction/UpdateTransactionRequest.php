<?php

namespace App\Http\Requests\Transaction;

use App\Models\Transaction;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $ownedBy = fn (string $table) => Rule::exists($table, 'id')
            ->where(fn (Builder $q) => $q->where('user_id', Auth::id()));

        return [
            'account_id' => ['sometimes', 'required', 'integer', $ownedBy('accounts')],
            'category_id' => ['sometimes', 'nullable', 'integer', $ownedBy('categories')],
            'transfer_account_id' => [
                'sometimes',
                'nullable',
                'integer',
                'different:account_id',
                $ownedBy('accounts'),
            ],
            'type' => ['sometimes', 'required', 'in:income,expense,transfer'],
            'amount' => ['sometimes', 'required', 'numeric', 'gt:0', 'between:0,999999999999.99'],
            'transfer_amount' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'between:0,999999999999.99'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'occurred_at' => ['sometimes', 'required', 'date'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'external_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['integer', $ownedBy('tags')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            /** @var Transaction $transaction */
            $transaction = $this->route('transaction');
            $type = $this->input('type', $transaction->type);

            $hasTransferKey = $this->has('transfer_account_id');
            $transferAccount = $hasTransferKey
                ? $this->input('transfer_account_id')
                : $transaction->transfer_account_id;

            if ($type === 'transfer' && empty($transferAccount)) {
                $validator->errors()->add('transfer_account_id', 'Obbligatorio per transazioni di tipo transfer.');
            }

            if ($type !== 'transfer' && ! empty($transferAccount) && $hasTransferKey) {
                $validator->errors()->add('transfer_account_id', 'Consentito solo per transazioni di tipo transfer.');
            }
        });
    }
}

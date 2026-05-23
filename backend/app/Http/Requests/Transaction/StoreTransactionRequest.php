<?php

namespace App\Http\Requests\Transaction;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreTransactionRequest extends FormRequest
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
            'account_id' => ['required', 'integer', $ownedBy('accounts')],
            'category_id' => ['nullable', 'integer', $ownedBy('categories')],
            'transfer_account_id' => [
                'nullable',
                'integer',
                'different:account_id',
                Rule::requiredIf(fn () => $this->input('type') === 'transfer'),
                $ownedBy('accounts'),
            ],
            'type' => ['required', 'in:income,expense,transfer'],
            'amount' => ['required', 'numeric', 'gt:0', 'between:0,999999999999.99'],
            'currency' => ['nullable', 'string', 'size:3'],
            'occurred_at' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'external_id' => ['nullable', 'string', 'max:255'],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['integer', $ownedBy('tags')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            if ($this->input('type') !== 'transfer' && $this->filled('transfer_account_id')) {
                $validator->errors()->add('transfer_account_id', 'Consentito solo per transazioni di tipo transfer.');
            }
        });
    }
}

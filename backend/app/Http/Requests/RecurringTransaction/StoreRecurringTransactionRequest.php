<?php

namespace App\Http\Requests\RecurringTransaction;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreRecurringTransactionRequest extends FormRequest
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
            'description' => ['nullable', 'string', 'max:255'],
            'cadence' => ['required', 'in:daily,weekly,biweekly,monthly,quarterly,yearly'],
            'interval' => ['nullable', 'integer', 'min:1', 'max:255'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'next_run_at' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'is_active' => ['sometimes', 'boolean'],
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

<?php

namespace App\Http\Requests\InvestmentTransaction;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvestmentTransactionRequest extends FormRequest
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
        return [
            'side' => ['required', Rule::in(['buy', 'sell'])],
            'occurred_at' => ['required', 'date'],
            'quantity' => ['required', 'numeric', 'gt:0', 'between:0,9999999999999.99999999'],
            'price' => ['required', 'numeric', 'min:0', 'between:0,9999999999999.99999999'],
            'fees' => ['sometimes', 'numeric', 'min:0', 'max:9999999999999.99'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

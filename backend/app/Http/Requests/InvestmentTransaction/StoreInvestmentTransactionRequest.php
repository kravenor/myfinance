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

    /** Un costo puro non muove quote: quantità e prezzo non si chiedono al client. */
    protected function prepareForValidation(): void
    {
        if ($this->input('side') === 'fee') {
            $this->merge(['quantity' => 0, 'price' => 0]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isFee = $this->input('side') === 'fee';

        return [
            'side' => ['required', Rule::in(['buy', 'sell', 'fee'])],
            'occurred_at' => ['required', 'date'],
            'quantity' => ['required', 'numeric', $isFee ? 'in:0' : 'gt:0', 'between:0,9999999999999.99999999'],
            'price' => ['required', 'numeric', 'min:0', 'between:0,9999999999999.99999999'],
            'fees' => [$isFee ? 'required' : 'sometimes', 'numeric', $isFee ? 'gt:0' : 'min:0', 'max:9999999999999.99'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

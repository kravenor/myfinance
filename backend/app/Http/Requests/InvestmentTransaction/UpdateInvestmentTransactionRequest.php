<?php

namespace App\Http\Requests\InvestmentTransaction;

use App\Models\InvestmentTransaction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInvestmentTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Un costo puro non muove quote: quantità e prezzo non si chiedono al client. */
    protected function prepareForValidation(): void
    {
        if ($this->isFee()) {
            $this->merge(['quantity' => 0, 'price' => 0]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isFee = $this->isFee();

        return [
            'side' => ['sometimes', 'required', Rule::in(['buy', 'sell', 'fee'])],
            'occurred_at' => ['sometimes', 'required', 'date'],
            'quantity' => ['sometimes', 'required', 'numeric', $isFee ? 'in:0' : 'gt:0', 'between:0,9999999999999.99999999'],
            'price' => ['sometimes', 'required', 'numeric', 'min:0', 'between:0,9999999999999.99999999'],
            'fees' => ['sometimes', 'numeric', $isFee ? 'gt:0' : 'min:0', 'max:9999999999999.99'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /** Il side può non essere nel payload: vale quello già salvato. */
    private function isFee(): bool
    {
        /** @var InvestmentTransaction|null $movement */
        $movement = $this->route('transaction');

        return $this->input('side', $movement?->side) === 'fee';
    }
}

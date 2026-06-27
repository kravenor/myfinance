<?php

namespace App\Http\Requests\InvestmentHolding;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class UpdateInvestmentHoldingRequest extends FormRequest
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
            'account_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('accounts', 'id')->where(
                    fn (Builder $q) => $q->where('user_id', Auth::id())->where('type', 'investment')
                ),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'symbol' => ['sometimes', 'nullable', 'string', 'max:40'],
            'isin' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z]{2}[A-Z0-9]{9}[0-9]$/'],
            'asset_type' => ['sometimes', 'required', Rule::in(['stock', 'etf', 'fund', 'bond', 'crypto', 'commodity', 'cash', 'other'])],
            'currency' => ['sometimes', 'string', 'size:3'],
            'quantity' => ['sometimes', 'required', 'numeric', 'min:0', 'between:0,9999999999999.99999999'],
            'avg_cost' => ['sometimes', 'required', 'numeric', 'min:0', 'between:0,9999999999999.99999999'],
            'last_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'between:0,9999999999999.99999999'],
            'last_price_at' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('isin')) {
            $this->merge(['isin' => strtoupper(trim((string) $this->input('isin')))]);
        }
    }
}

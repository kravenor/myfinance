<?php

namespace App\Http\Requests\InvestmentHolding;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class StoreInvestmentHoldingRequest extends FormRequest
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
                'required',
                'integer',
                // Deve essere un conto dell'utente di tipo investment.
                Rule::exists('accounts', 'id')->where(
                    fn (Builder $q) => $q->where('user_id', Auth::id())->where('type', 'investment')
                ),
            ],
            'name' => ['required', 'string', 'max:120'],
            'symbol' => ['nullable', 'string', 'max:40'],
            'asset_type' => ['required', Rule::in(['stock', 'etf', 'fund', 'bond', 'crypto', 'commodity', 'cash', 'other'])],
            'currency' => ['sometimes', 'string', 'size:3'],
            'quantity' => ['required', 'numeric', 'min:0', 'between:0,9999999999999.99999999'],
            'avg_cost' => ['required', 'numeric', 'min:0', 'between:0,9999999999999.99999999'],
            'last_price' => ['nullable', 'numeric', 'min:0', 'between:0,9999999999999.99999999'],
            'last_price_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

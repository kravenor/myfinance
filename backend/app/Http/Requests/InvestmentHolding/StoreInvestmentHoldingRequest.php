<?php

namespace App\Http\Requests\InvestmentHolding;

use App\Models\InvestmentHolding;
use App\Models\OmiQuotation;
use Illuminate\Contracts\Validation\Validator;
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
            // Immobili: il symbol è il territorio della serie ISTAT, vuoto = Italia.
            'symbol' => ['nullable', 'string', 'max:40', Rule::when(
                $this->input('asset_type') === 'real_estate',
                [Rule::in(config('finance.prices.istat.areas'))],
            )],
            // Zona OMI caricata dall'utente (solo immobili): tipologia e stato vanno con la zona.
            'omi_zone' => ['nullable', 'string', 'max:16', Rule::exists('omi_quotations', 'link_zona')->where('user_id', $this->user()->id)],
            'omi_type' => ['nullable', 'required_with:omi_zone', 'integer', 'min:1', 'max:65535'],
            'omi_state' => ['nullable', 'required_with:omi_zone', Rule::in(OmiQuotation::STATES)],
            'surface_sqm' => ['nullable', 'numeric', 'min:1', 'max:999999'],
            'isin' => ['nullable', 'string', 'regex:/^[A-Z]{2}[A-Z0-9]{9}[0-9]$/'],
            'asset_type' => ['required', Rule::in(['stock', 'etf', 'fund', 'bond', 'crypto', 'commodity', 'certificate', 'real_estate', 'cash', 'other'])],
            'currency' => ['sometimes', 'string', 'size:3'],
            'quantity' => ['required', 'numeric', 'min:0', 'between:0,9999999999999.99999999'],
            'avg_cost' => ['required', 'numeric', 'min:0', 'between:0,9999999999999.99999999'],
            // Data del movimento di apertura (default oggi): per un immobile è l'ancora dell'indicizzazione.
            'opened_at' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'last_price' => ['nullable', 'numeric', 'min:0', 'between:0,9999999999999.99999999'],
            'last_price_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if (InvestmentHolding::query()->count() >= InvestmentHolding::MAX_PER_USER) {
                $v->errors()->add('holdings', 'Puoi avere al massimo '.InvestmentHolding::MAX_PER_USER.' strumenti.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('isin')) {
            $this->merge(['isin' => strtoupper(trim((string) $this->input('isin')))]);
        }
    }
}

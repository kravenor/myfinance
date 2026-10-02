<?php

namespace App\Http\Requests\Scenario;

use App\Support\CategoryTypeCheck;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreScenarioItemRequest extends FormRequest
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
            'type' => ['sometimes', Rule::in(['expense', 'income'])],
            'account_id' => [
                'nullable',
                'integer',
                Rule::exists('accounts', 'id')->where(fn (Builder $q) => $q->where('user_id', Auth::id())),
            ],
            'category_id' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id')->where(fn (Builder $q) => $q->where('user_id', Auth::id())),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0', 'between:0,999999999999.99'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'cadence' => ['required', Rule::in(['one_time', 'monthly', 'quarterly', 'yearly'])],
            'interval' => ['sometimes', 'integer', 'min:1', 'max:24'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            if (! $validator->errors()->hasAny(['type', 'category_id'])) {
                // Senza type la voce nasce come uscita (default del modello).
                $error = CategoryTypeCheck::error($this->input('category_id'), $this->input('type', 'expense'));
                if ($error !== null) {
                    $validator->errors()->add('category_id', $error);
                }
            }
        });
    }
}

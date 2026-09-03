<?php

namespace App\Http\Requests\Account;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ReconcileAccountRequest extends FormRequest
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
            // Il saldo reale può essere negativo (carta di credito, scoperto).
            'balance' => ['required', 'numeric', 'between:-999999999999.99,999999999999.99'],
            'date' => ['sometimes', 'date'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'category_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('categories', 'id')->where(fn (Builder $q) => $q->where('user_id', Auth::id())),
            ],
        ];
    }
}

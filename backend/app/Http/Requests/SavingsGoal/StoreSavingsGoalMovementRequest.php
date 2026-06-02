<?php

namespace App\Http\Requests\SavingsGoal;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class StoreSavingsGoalMovementRequest extends FormRequest
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
                'nullable',
                'integer',
                Rule::exists('accounts', 'id')->where(fn (Builder $q) => $q->where('user_id', Auth::id())),
            ],
            'direction' => ['required', Rule::in(['in', 'out'])],
            'amount' => ['required', 'numeric', 'gt:0', 'between:0,999999999999.99'],
            'occurred_at' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}

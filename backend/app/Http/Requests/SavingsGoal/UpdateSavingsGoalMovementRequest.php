<?php

namespace App\Http\Requests\SavingsGoal;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class UpdateSavingsGoalMovementRequest extends FormRequest
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
                'nullable',
                'integer',
                Rule::exists('accounts', 'id')->where(fn (Builder $q) => $q->where('user_id', Auth::id())),
            ],
            'direction' => ['sometimes', 'required', Rule::in(['in', 'out'])],
            'amount' => ['sometimes', 'required', 'numeric', 'gt:0', 'between:0,999999999999.99'],
            'occurred_at' => ['sometimes', 'required', 'date'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}

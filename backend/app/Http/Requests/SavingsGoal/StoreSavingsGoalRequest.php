<?php

namespace App\Http\Requests\SavingsGoal;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSavingsGoalRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'target_amount' => ['required', 'numeric', 'gt:0', 'between:0,999999999999.99'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'target_date' => ['nullable', 'date'],
            'color' => ['nullable', 'string', 'max:20'],
            'icon' => ['nullable', 'string', 'max:60'],
            'status' => ['sometimes', Rule::in(['active', 'completed', 'archived'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

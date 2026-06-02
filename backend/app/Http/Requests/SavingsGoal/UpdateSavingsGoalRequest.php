<?php

namespace App\Http\Requests\SavingsGoal;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSavingsGoalRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'target_amount' => ['sometimes', 'required', 'numeric', 'gt:0', 'between:0,999999999999.99'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'target_date' => ['sometimes', 'nullable', 'date'],
            'color' => ['sometimes', 'nullable', 'string', 'max:20'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:60'],
            'status' => ['sometimes', Rule::in(['active', 'completed', 'archived'])],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}

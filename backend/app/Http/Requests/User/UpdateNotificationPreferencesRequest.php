<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationPreferencesRequest extends FormRequest
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
            'email' => ['sometimes', 'boolean'],
            'email_address' => ['sometimes', 'nullable', 'email', 'max:255'],
            'budget' => ['sometimes', 'boolean'],
            'savings_goals' => ['sometimes', 'boolean'],
            'budget_threshold' => ['sometimes', 'numeric', 'min:1', 'max:100'],
        ];
    }
}

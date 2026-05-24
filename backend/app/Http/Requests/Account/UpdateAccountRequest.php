<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAccountRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['sometimes', 'required', 'in:cash,bank,card,investment,other'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'initial_balance' => ['sometimes', 'nullable', 'numeric', 'between:-999999999999.99,999999999999.99'],
            'color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:64'],
            'is_archived' => ['sometimes', 'boolean'],
            'include_in_net_worth' => ['sometimes', 'boolean'],
            'is_primary' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}

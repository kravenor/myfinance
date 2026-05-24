<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;

class StoreAccountRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:cash,bank,card,investment,other'],
            'currency' => ['nullable', 'string', 'size:3'],
            'initial_balance' => ['nullable', 'numeric', 'between:-999999999999.99,999999999999.99'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'icon' => ['nullable', 'string', 'max:64'],
            'is_archived' => ['sometimes', 'boolean'],
            'include_in_net_worth' => ['sometimes', 'boolean'],
            'is_primary' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

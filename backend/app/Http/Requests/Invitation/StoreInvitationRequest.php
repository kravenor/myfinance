<?php

namespace App\Http\Requests\Invitation;

use Illuminate\Foundation\Http\FormRequest;

class StoreInvitationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Assente = link generico. Rivelare che l'email ha già un account va bene: lo vede solo un admin.
            'email' => ['nullable', 'string', 'email', 'max:255', 'unique:users,email'],
            'send_email' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'Esiste già un account con questa email.',
        ];
    }
}

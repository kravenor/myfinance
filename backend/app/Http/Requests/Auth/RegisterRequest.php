<?php

namespace App\Http\Requests\Auth;

use App\Models\Invitation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class RegisterRequest extends FormRequest
{
    private ?Invitation $invitation = null;

    // Prima della validazione: a registrazione spenta, unique:users non rivela quali email esistono.
    // Un invito valido apre la registrazione anche quando è chiusa.
    public function authorize(): bool
    {
        if ($this->filled('invitation_token')) {
            $this->invitation = Invitation::findUsable($this->string('invitation_token')->toString());

            return $this->invitation !== null;
        }

        return (bool) config('finance.registration');
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException($this->filled('invitation_token')
            ? 'Invito non valido o scaduto.'
            : 'Registrazione disattivata.');
    }

    public function invitation(): ?Invitation
    {
        return $this->invitation;
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $email = $this->input('email');
            if ($this->invitation !== null && is_string($email) && ! $this->invitation->allowsEmail($email)) {
                $validator->errors()->add('email', 'Questo invito vale solo per l\'indirizzo a cui è stato inviato.');
            }
        }];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'currency' => ['nullable', 'string', 'size:3'],
            'locale' => ['nullable', 'string', 'max:5'],
            'invitation_token' => ['nullable', 'string', 'max:100'],
        ];
    }
}

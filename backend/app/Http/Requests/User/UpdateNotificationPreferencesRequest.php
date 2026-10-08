<?php

namespace App\Http\Requests\User;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // Chi ha solo la sessione non deve poter dirottare i riepiloghi con gli importi.
            'current_password' => [Rule::requiredIf(fn () => $this->changesEmailAddress()), 'string', 'current_password'],
            'budget' => ['sometimes', 'boolean'],
            'savings_goals' => ['sometimes', 'boolean'],
            'budget_threshold' => ['sometimes', 'numeric', 'min:1', 'max:100'],
            'pac' => ['sometimes', 'boolean'],
            'stale_prices' => ['sometimes', 'boolean'],
            'monthly_summary' => ['sometimes', 'boolean'],
            'large_expense' => ['sometimes', 'boolean'],
            'large_expense_threshold' => ['sometimes', 'numeric', 'min:1', 'max:1000000'],
        ];
    }

    public function changesEmailAddress(): bool
    {
        if (! $this->has('email_address')) {
            return false;
        }

        /** @var User $user */
        $user = $this->user();
        $current = (string) $user->notificationPreference('email_address');

        return mb_strtolower(trim((string) $this->input('email_address'))) !== mb_strtolower(trim($current));
    }
}

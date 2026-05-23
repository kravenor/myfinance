<?php

namespace App\Http\Requests\RecurringTransaction;

use App\Models\RecurringTransaction;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateRecurringTransactionRequest extends FormRequest
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
        $ownedBy = fn (string $table) => Rule::exists($table, 'id')
            ->where(fn (Builder $q) => $q->where('user_id', Auth::id()));

        return [
            'account_id' => ['sometimes', 'required', 'integer', $ownedBy('accounts')],
            'category_id' => ['sometimes', 'nullable', 'integer', $ownedBy('categories')],
            'transfer_account_id' => [
                'sometimes',
                'nullable',
                'integer',
                'different:account_id',
                $ownedBy('accounts'),
            ],
            'type' => ['sometimes', 'required', 'in:income,expense,transfer'],
            'amount' => ['sometimes', 'required', 'numeric', 'gt:0', 'between:0,999999999999.99'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'cadence' => ['sometimes', 'required', 'in:daily,weekly,biweekly,monthly,quarterly,yearly'],
            'interval' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:255'],
            'starts_on' => ['sometimes', 'required', 'date'],
            'ends_on' => ['sometimes', 'nullable', 'date'],
            'next_run_at' => ['sometimes', 'nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            /** @var RecurringTransaction $recurring */
            $recurring = $this->route('recurring_transaction');

            $type = $this->input('type', $recurring->type);
            $hasTransferKey = $this->has('transfer_account_id');
            $transferAccount = $hasTransferKey
                ? $this->input('transfer_account_id')
                : $recurring->transfer_account_id;

            if ($type === 'transfer' && empty($transferAccount)) {
                $validator->errors()->add('transfer_account_id', 'Obbligatorio per type=transfer.');
            }

            if ($type !== 'transfer' && ! empty($transferAccount) && $hasTransferKey) {
                $validator->errors()->add('transfer_account_id', 'Consentito solo per type=transfer.');
            }

            $startsOn = $this->input('starts_on', $recurring->starts_on?->toDateString());
            $endsOn = $this->has('ends_on') ? $this->input('ends_on') : $recurring->ends_on?->toDateString();
            if ($startsOn && $endsOn && $endsOn < $startsOn) {
                $validator->errors()->add('ends_on', 'ends_on deve essere uguale o successivo a starts_on.');
            }
        });
    }
}

<?php

namespace App\Http\Requests\Scenario;

use App\Models\ScenarioItem;
use App\Support\CategoryTypeCheck;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateScenarioItemRequest extends FormRequest
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
            'type' => ['sometimes', Rule::in(['expense', 'income'])],
            'account_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('accounts', 'id')->where(fn (Builder $q) => $q->where('user_id', Auth::id())),
            ],
            'category_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('categories', 'id')->where(fn (Builder $q) => $q->where('user_id', Auth::id())),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'amount' => ['sometimes', 'required', 'numeric', 'gt:0', 'between:0,999999999999.99'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'cadence' => ['sometimes', 'required', Rule::in(['one_time', 'monthly', 'quarterly', 'yearly'])],
            'interval' => ['sometimes', 'integer', 'min:1', 'max:24'],
            'starts_on' => ['sometimes', 'required', 'date'],
            'ends_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_on'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->hasAny(['type', 'category_id'])) {
                return;
            }
            /** @var ScenarioItem $item */
            $item = $this->route('item');
            $categoryId = $this->has('category_id') ? $this->input('category_id') : $item->category_id;
            $error = CategoryTypeCheck::error($categoryId, $this->input('type', $item->type));
            if ($error !== null) {
                $validator->errors()->add('category_id', $error);
            }
        });
    }
}

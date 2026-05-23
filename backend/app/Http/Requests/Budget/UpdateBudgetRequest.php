<?php

namespace App\Http\Requests\Budget;

use App\Models\Budget;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateBudgetRequest extends FormRequest
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
            'category_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('categories', 'id')->where(fn (Builder $q) => $q->where('user_id', Auth::id())),
            ],
            'year' => ['sometimes', 'required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['sometimes', 'required', 'integer', 'between:1,12'],
            'amount' => ['sometimes', 'required', 'numeric', 'gte:0', 'between:0,999999999999.99'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            /** @var Budget $budget */
            $budget = $this->route('budget');

            $categoryId = $this->input('category_id', $budget->category_id);
            $year = $this->input('year', $budget->year);
            $month = $this->input('month', $budget->month);

            $duplicate = Budget::where('category_id', $categoryId)
                ->where('year', $year)
                ->where('month', $month)
                ->where('id', '!=', $budget->id)
                ->exists();

            if ($duplicate) {
                $validator->errors()->add('category_id', 'Budget già definito per questa categoria/mese.');
            }
        });
    }
}

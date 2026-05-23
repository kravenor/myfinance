<?php

namespace App\Http\Requests\Budget;

use App\Models\Budget;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreBudgetRequest extends FormRequest
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
                'required',
                'integer',
                Rule::exists('categories', 'id')->where(fn (Builder $q) => $q->where('user_id', Auth::id())),
            ],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'between:1,12'],
            'amount' => ['required', 'numeric', 'gte:0', 'between:0,999999999999.99'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            $exists = Budget::where('category_id', $this->input('category_id'))
                ->where('year', $this->input('year'))
                ->where('month', $this->input('month'))
                ->exists();

            if ($exists) {
                $validator->errors()->add('category_id', 'Budget già definito per questa categoria/mese.');
            }
        });
    }
}

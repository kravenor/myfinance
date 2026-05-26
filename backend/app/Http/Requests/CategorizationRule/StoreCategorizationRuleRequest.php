<?php

namespace App\Http\Requests\CategorizationRule;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class StoreCategorizationRuleRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'match_type' => ['required', 'in:contains,starts_with,equals,regex'],
            'pattern' => ['required', 'string', 'max:255'],
            'applies_to_type' => ['nullable', 'in:any,income,expense'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($this->input('match_type') !== 'regex') {
                return;
            }
            $pattern = (string) $this->input('pattern', '');
            if ($pattern === '') {
                return;
            }
            $delim = '/'.str_replace('/', '\/', $pattern).'/iu';
            if (@preg_match($delim, '') === false) {
                $v->errors()->add('pattern', 'Espressione regolare non valida.');
            }
        });
    }
}

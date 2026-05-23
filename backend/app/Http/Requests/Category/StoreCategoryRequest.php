<?php

namespace App\Http\Requests\Category;

use App\Models\Category;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCategoryRequest extends FormRequest
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
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id')->where(fn (Builder $q) => $q->where('user_id', Auth::id())),
            ],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:income,expense'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'icon' => ['nullable', 'string', 'max:64'],
            'is_archived' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            $parentId = $this->input('parent_id');
            if ($parentId) {
                $parent = Category::find($parentId);
                if ($parent && $parent->type !== $this->input('type')) {
                    $validator->errors()->add('parent_id', 'Il parent deve avere lo stesso tipo della categoria.');
                }
            }
        });
    }
}

<?php

namespace App\Http\Requests\Category;

use App\Models\Category;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateCategoryRequest extends FormRequest
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
        /** @var Category $category */
        $category = $this->route('category');

        return [
            'parent_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::notIn([$category->id]),
                Rule::exists('categories', 'id')->where(fn (Builder $q) => $q->where('user_id', Auth::id())),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['sometimes', 'required', 'in:income,expense'],
            'color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:64'],
            'is_archived' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            /** @var Category $category */
            $category = $this->route('category');
            $type = $this->input('type', $category->type);
            $parentId = $this->has('parent_id') ? $this->input('parent_id') : $category->parent_id;

            if ($parentId) {
                $parent = Category::find($parentId);
                if ($parent && $parent->type !== $type) {
                    $validator->errors()->add('parent_id', 'Il parent deve avere lo stesso tipo della categoria.');
                }

                $cursor = $parent;
                while ($cursor) {
                    if ((int) $cursor->id === (int) $category->id) {
                        $validator->errors()->add('parent_id', 'Ciclo gerarchico non consentito.');
                        break;
                    }
                    $cursor = $cursor->parent_id ? Category::find($cursor->parent_id) : null;
                }
            }
        });
    }
}

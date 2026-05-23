<?php

namespace App\Http\Requests\Tag;

use App\Models\Tag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class UpdateTagRequest extends FormRequest
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
        /** @var Tag $tag */
        $tag = $this->route('tag');

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:64',
                Rule::unique('tags', 'name')
                    ->ignore($tag->id)
                    ->where(fn ($q) => $q->where('user_id', Auth::id())),
            ],
            'color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }
}

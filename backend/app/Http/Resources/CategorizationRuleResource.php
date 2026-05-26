<?php

namespace App\Http\Resources;

use App\Models\CategorizationRule;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CategorizationRule
 */
class CategorizationRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'category' => $this->whenLoaded('category', function () {
                /** @var Category $category */
                $category = $this->category;

                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'color' => $category->color,
                    'type' => $category->type,
                ];
            }),
            'name' => $this->name,
            'match_type' => $this->match_type,
            'pattern' => $this->pattern,
            'applies_to_type' => $this->applies_to_type,
            'priority' => $this->priority,
            'is_active' => $this->is_active,
            'times_applied' => $this->times_applied,
            'last_applied_at' => $this->last_applied_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

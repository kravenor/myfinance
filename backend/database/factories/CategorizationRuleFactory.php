<?php

namespace Database\Factories;

use App\Models\CategorizationRule;
use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CategorizationRule>
 */
class CategorizationRuleFactory extends Factory
{
    protected $model = CategorizationRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'category_id' => Category::factory(),
            'name' => fake()->unique()->words(2, true),
            'match_type' => 'contains',
            'pattern' => fake()->word(),
            'applies_to_type' => 'any',
            'priority' => 100,
            'is_active' => true,
        ];
    }
}

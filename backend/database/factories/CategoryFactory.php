<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'parent_id' => null,
            'name' => fake()->unique()->words(2, true),
            'type' => fake()->randomElement(['income', 'expense']),
            'color' => null,
            'icon' => null,
            'is_archived' => false,
            'sort_order' => 0,
        ];
    }
}

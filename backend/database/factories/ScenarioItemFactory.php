<?php

namespace Database\Factories;

use App\Models\Scenario;
use App\Models\ScenarioItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScenarioItem>
 */
class ScenarioItemFactory extends Factory
{
    protected $model = ScenarioItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'scenario_id' => Scenario::factory(),
            'account_id' => null,
            'category_id' => null,
            'description' => fake()->sentence(3),
            'amount' => fake()->randomFloat(2, 50, 2000),
            'currency' => 'EUR',
            'cadence' => fake()->randomElement(['one_time', 'monthly', 'quarterly', 'yearly']),
            'interval' => 1,
            'starts_on' => fake()->dateTimeBetween('now', '+6 months')->format('Y-m-d'),
            'ends_on' => null,
        ];
    }
}

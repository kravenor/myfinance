<?php

namespace Database\Factories;

use App\Models\SavingsGoal;
use App\Models\SavingsGoalMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavingsGoalMovement>
 */
class SavingsGoalMovementFactory extends Factory
{
    protected $model = SavingsGoalMovement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'savings_goal_id' => SavingsGoal::factory(),
            'account_id' => null,
            'direction' => fake()->randomElement(['in', 'out']),
            'amount' => fake()->randomFloat(2, 10, 1000),
            'occurred_at' => fake()->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            'note' => null,
        ];
    }
}

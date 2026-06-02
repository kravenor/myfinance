<?php

namespace Database\Factories;

use App\Models\SavingsGoal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavingsGoal>
 */
class SavingsGoalFactory extends Factory
{
    protected $model = SavingsGoal::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->randomElement(['Vacanza', 'Fondo emergenza', 'Auto nuova', 'Casa']),
            'target_amount' => fake()->randomFloat(2, 1000, 20000),
            'currency' => 'EUR',
            'target_date' => fake()->dateTimeBetween('+2 months', '+2 years')->format('Y-m-d'),
            'color' => fake()->safeHexColor(),
            'icon' => null,
            'status' => 'active',
            'notes' => null,
        ];
    }
}

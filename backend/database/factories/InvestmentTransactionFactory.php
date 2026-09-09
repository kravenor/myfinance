<?php

namespace Database\Factories;

use App\Models\InvestmentHolding;
use App\Models\InvestmentTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvestmentTransaction>
 */
class InvestmentTransactionFactory extends Factory
{
    protected $model = InvestmentTransaction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'investment_holding_id' => InvestmentHolding::factory(),
            'side' => 'buy',
            'occurred_at' => fake()->dateTimeBetween('-2 years', 'now')->format('Y-m-d'),
            'quantity' => fake()->randomFloat(6, 0.1, 20),
            'price' => fake()->randomFloat(2, 10, 500),
            'fees' => fake()->randomElement([0, 1.5, 2.95]),
            'notes' => null,
        ];
    }

    public function sell(): static
    {
        return $this->state(['side' => 'sell']);
    }
}

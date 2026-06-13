<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\InvestmentHolding;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvestmentHolding>
 */
class InvestmentHoldingFactory extends Factory
{
    protected $model = InvestmentHolding::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $avgCost = fake()->randomFloat(2, 10, 500);

        return [
            'user_id' => User::factory(),
            'account_id' => Account::factory()->state(['type' => 'investment']),
            'name' => fake()->randomElement(['Vanguard FTSE All-World', 'iShares Core MSCI', 'Bitcoin', 'Apple']),
            'symbol' => fake()->randomElement(['VWCE', 'IWDA', 'BTC', 'AAPL']),
            'asset_type' => fake()->randomElement(['etf', 'stock', 'crypto']),
            'currency' => 'EUR',
            'quantity' => fake()->randomFloat(4, 1, 100),
            'avg_cost' => $avgCost,
            'last_price' => $avgCost * fake()->randomFloat(2, 0.8, 1.4),
            'last_price_at' => now(),
            'notes' => null,
        ];
    }
}

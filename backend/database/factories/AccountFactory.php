<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->company().' Account',
            'type' => fake()->randomElement(['cash', 'bank', 'card', 'investment', 'other']),
            'currency' => 'EUR',
            'initial_balance' => fake()->randomFloat(2, 0, 10000),
            'color' => null,
            'icon' => null,
            'is_archived' => false,
            'include_in_net_worth' => true,
            'notes' => null,
        ];
    }
}

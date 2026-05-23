<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'account_id' => Account::factory(),
            'category_id' => null,
            'transfer_account_id' => null,
            'recurring_transaction_id' => null,
            'type' => 'expense',
            'amount' => fake()->randomFloat(2, 1, 500),
            'currency' => 'EUR',
            'occurred_at' => fake()->dateTimeBetween('-30 days', 'now')->format('Y-m-d'),
            'description' => fake()->sentence(3),
            'notes' => null,
            'external_id' => null,
        ];
    }
}

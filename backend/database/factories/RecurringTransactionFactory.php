<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\RecurringTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringTransaction>
 */
class RecurringTransactionFactory extends Factory
{
    protected $model = RecurringTransaction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsOn = fake()->dateTimeBetween('-2 months', '-1 month')->format('Y-m-d');

        return [
            'user_id' => User::factory(),
            'account_id' => Account::factory(),
            'category_id' => null,
            'transfer_account_id' => null,
            'type' => 'expense',
            'amount' => fake()->randomFloat(2, 10, 500),
            'currency' => 'EUR',
            'description' => fake()->sentence(3),
            'cadence' => 'monthly',
            'interval' => 1,
            'starts_on' => $startsOn,
            'ends_on' => null,
            'next_run_at' => $startsOn,
            'last_run_at' => null,
            'is_active' => true,
        ];
    }
}

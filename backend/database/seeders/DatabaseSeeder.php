<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'demo@finance.local'],
            [
                'name' => 'Demo User',
                'password' => Hash::make('password'),
                'currency' => 'EUR',
                'locale' => 'it',
            ],
        );

        $this->call([
            CategorySeeder::class,
        ]);

        if ($user->accounts()->doesntExist()) {
            Account::create([
                'user_id' => $user->id,
                'name' => 'Conto Corrente',
                'type' => 'bank',
                'currency' => 'EUR',
                'initial_balance' => 0,
                'color' => '#2563eb',
            ]);
            Account::create([
                'user_id' => $user->id,
                'name' => 'Contanti',
                'type' => 'cash',
                'currency' => 'EUR',
                'initial_balance' => 0,
                'color' => '#16a34a',
            ]);
        }
    }
}

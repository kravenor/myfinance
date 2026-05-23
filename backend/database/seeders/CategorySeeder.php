<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $users = User::all();

        foreach ($users as $user) {
            $this->seedFor($user);
        }
    }

    public function seedFor(User $user): void
    {
        $expense = [
            ['Casa',         '#ef4444', 'home'],
            ['Bollette',     '#f97316', 'bolt'],
            ['Spesa',        '#84cc16', 'shopping-cart'],
            ['Ristoranti',   '#eab308', 'utensils'],
            ['Trasporti',    '#06b6d4', 'car'],
            ['Salute',       '#ec4899', 'heart-pulse'],
            ['Tempo libero', '#a855f7', 'gamepad'],
            ['Abbigliamento', '#d946ef', 'shirt'],
            ['Istruzione',   '#0ea5e9', 'graduation-cap'],
            ['Tasse',        '#64748b', 'landmark'],
            ['Altro',        '#94a3b8', 'ellipsis'],
        ];

        $income = [
            ['Stipendio',    '#22c55e', 'briefcase'],
            ['Bonus',        '#10b981', 'gift'],
            ['Investimenti', '#0ea5e9', 'trending-up'],
            ['Rimborsi',     '#14b8a6', 'arrow-uturn-left'],
            ['Altri ricavi', '#94a3b8', 'plus-circle'],
        ];

        foreach ($expense as $i => [$name, $color, $icon]) {
            Category::firstOrCreate(
                ['user_id' => $user->id, 'name' => $name, 'type' => 'expense'],
                ['color' => $color, 'icon' => $icon, 'sort_order' => $i],
            );
        }

        foreach ($income as $i => [$name, $color, $icon]) {
            Category::firstOrCreate(
                ['user_id' => $user->id, 'name' => $name, 'type' => 'income'],
                ['color' => $color, 'icon' => $icon, 'sort_order' => $i],
            );
        }
    }
}

<?php

namespace Database\Factories;

use App\Models\Scenario;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Scenario>
 */
class ScenarioFactory extends Factory
{
    protected $model = Scenario::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->randomElement(['Vacanza 2026', 'Auto nuova', 'Trasloco', 'Anno sabbatico']),
            'description' => null,
            'color' => fake()->safeHexColor(),
            'is_active' => true,
        ];
    }
}

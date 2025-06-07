<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Game>
 */
class GameFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->words(2, true),
            'slug' => fake()->slug(),
            'description' => fake()->sentence(),
            'config' => [
                'icon' => '🎮',
                'cost_per_round' => 10,
                'max_rounds_per_day' => 10,
                'difficulty_levels' => ['easy', 'medium', 'hard']
            ],
            'is_active' => true,
        ];
    }
} 
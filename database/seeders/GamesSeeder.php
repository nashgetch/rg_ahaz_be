<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class GamesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        echo "🎮 Seeding AHAZ Games...\n";

        $games = [
            [
                'name' => 'Memory Master',
                'description' => 'Test your memory with increasingly complex patterns',
                'category' => 'puzzle',
                'icon' => '🧠',
                'cost_tokens' => 5,
                'max_score_possible' => 10000,
                'difficulty_level' => 'easy',
                'estimated_duration_minutes' => 3,
                'instructions' => json_encode([
                    'en' => 'Watch the pattern and repeat it. Each round adds one more step to remember.',
                    'am' => 'ሥርዓቱን ተመልከት እና ድገመው። እያንዳንዱ ዙር አንድ ተጨማሪ እርምጃ ያስታውሳል።'
                ]),
                'is_active' => true,
                'config' => json_encode([
                    'max_rounds' => 20,
                    'pattern_speed' => 1000,
                    'scoring' => [
                        'base_points' => 100,
                        'round_multiplier' => 1.5,
                        'time_bonus' => true
                    ]
                ]),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'Code Breaker',
                'description' => 'Crack the secret code using logic and deduction',
                'category' => 'logic',
                'icon' => '🔐',
                'cost_tokens' => 8,
                'max_score_possible' => 15000,
                'difficulty_level' => 'medium',
                'estimated_duration_minutes' => 5,
                'instructions' => 'Guess the 4-digit code. Black dots mean correct number in correct position, white dots mean correct number in wrong position.',
                'is_active' => true,
                'config' => json_encode([
                    'code_length' => 4,
                    'max_attempts' => 10,
                    'number_range' => [1, 6],
                    'scoring' => [
                        'base_points' => 200,
                        'attempt_penalty' => 50,
                        'time_bonus' => true
                    ]
                ]),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'Number Rush',
                'description' => 'Quick math challenges against the clock',
                'category' => 'math',
                'icon' => '🔢',
                'cost_tokens' => 3,
                'max_score_possible' => 8000,
                'difficulty_level' => 'easy',
                'estimated_duration_minutes' => 2,
                'instructions' => 'Solve math problems as fast as you can. Speed and accuracy both matter!',
                'is_active' => true,
                'config' => json_encode([
                    'time_limit' => 120,
                    'question_count' => 20,
                    'operations' => ['add', 'subtract', 'multiply'],
                    'scoring' => [
                        'correct_points' => 50,
                        'speed_bonus' => 25,
                        'streak_multiplier' => 1.2
                    ]
                ]),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'Word Chain',
                'description' => 'Build the longest word chain possible',
                'category' => 'word',
                'icon' => '📝',
                'cost_tokens' => 6,
                'max_score_possible' => 12000,
                'difficulty_level' => 'medium',
                'estimated_duration_minutes' => 4,
                'instructions' => 'Create words where each new word starts with the last letter of the previous word.',
                'is_active' => true,
                'config' => json_encode([
                    'time_limit' => 240,
                    'min_word_length' => 3,
                    'languages' => ['en', 'am'],
                    'scoring' => [
                        'base_points' => 10,
                        'length_multiplier' => 2,
                        'rare_word_bonus' => 50
                    ]
                ]),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'Pattern Quest',
                'description' => 'Identify and continue visual patterns',
                'category' => 'puzzle',
                'icon' => '🎨',
                'cost_tokens' => 7,
                'max_score_possible' => 14000,
                'difficulty_level' => 'hard',
                'estimated_duration_minutes' => 6,
                'instructions' => 'Study the pattern and select what comes next. Patterns get more complex as you progress.',
                'is_active' => true,
                'config' => json_encode([
                    'question_count' => 15,
                    'pattern_types' => ['sequence', 'rotation', 'color', 'shape'],
                    'difficulty_progression' => true,
                    'scoring' => [
                        'base_points' => 150,
                        'difficulty_multiplier' => 1.8,
                        'streak_bonus' => 100
                    ]
                ]),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'Quick Draw',
                'description' => 'Fast-paced reaction and precision game',
                'category' => 'reflex',
                'icon' => '⚡',
                'cost_tokens' => 4,
                'max_score_possible' => 9000,
                'difficulty_level' => 'easy',
                'estimated_duration_minutes' => 2,
                'instructions' => 'Click the targets as fast as possible. Speed and accuracy determine your score.',
                'is_active' => true,
                'config' => json_encode([
                    'target_count' => 25,
                    'target_size_range' => [30, 80],
                    'speed_increase' => true,
                    'scoring' => [
                        'hit_points' => 100,
                        'accuracy_bonus' => 50,
                        'speed_multiplier' => 1.5
                    ]
                ]),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'Color Match',
                'description' => 'Match colors while fighting interference',
                'category' => 'reflex',
                'icon' => '🌈',
                'cost_tokens' => 5,
                'max_score_possible' => 11000,
                'difficulty_level' => 'medium',
                'estimated_duration_minutes' => 3,
                'instructions' => 'Select the color that matches the word, not the color the word is written in.',
                'is_active' => true,
                'config' => json_encode([
                    'round_count' => 30,
                    'time_pressure' => true,
                    'interference_level' => 'high',
                    'scoring' => [
                        'correct_points' => 75,
                        'time_bonus' => 25,
                        'streak_multiplier' => 1.3
                    ]
                ]),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'Space Navigator',
                'description' => 'Navigate through space obstacles with precision',
                'category' => 'skill',
                'icon' => '🚀',
                'cost_tokens' => 10,
                'max_score_possible' => 20000,
                'difficulty_level' => 'hard',
                'estimated_duration_minutes' => 8,
                'instructions' => 'Guide your ship through asteroid fields. Collect power-ups and avoid collisions.',
                'is_active' => true,
                'config' => json_encode([
                    'levels' => 10,
                    'lives' => 3,
                    'power_ups' => ['shield', 'speed', 'points'],
                    'scoring' => [
                        'survival_points' => 500,
                        'collection_bonus' => 100,
                        'level_completion' => 1000
                    ]
                ]),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'Logic Grid',
                'description' => 'Solve complex logic puzzles step by step',
                'category' => 'logic',
                'icon' => '🔍',
                'cost_tokens' => 12,
                'max_score_possible' => 18000,
                'difficulty_level' => 'hard',
                'estimated_duration_minutes' => 10,
                'instructions' => 'Use clues to fill the grid. Each clue eliminates possibilities until only one solution remains.',
                'is_active' => true,
                'config' => json_encode([
                    'grid_sizes' => ['4x4', '5x5', '6x6'],
                    'clue_count_range' => [8, 15],
                    'progressive_difficulty' => true,
                    'scoring' => [
                        'base_points' => 300,
                        'speed_bonus' => true,
                        'hint_penalty' => 100
                    ]
                ]),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'name' => 'Trivia Master',
                'description' => 'Test your knowledge across multiple categories',
                'category' => 'trivia',
                'icon' => '🧠',
                'cost_tokens' => 6,
                'max_score_possible' => 13000,
                'difficulty_level' => 'medium',
                'estimated_duration_minutes' => 5,
                'instructions' => 'Answer questions from various categories. Faster answers earn more points.',
                'is_active' => true,
                'config' => json_encode([
                    'question_count' => 20,
                    'categories' => ['science', 'history', 'sports', 'entertainment', 'geography'],
                    'time_per_question' => 30,
                    'scoring' => [
                        'correct_points' => 100,
                        'time_bonus_max' => 50,
                        'difficulty_multiplier' => [1.0, 1.5, 2.0]
                    ]
                ]),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
        ];

        echo "Inserting " . count($games) . " games...\n";

        foreach ($games as $game) {
            DB::table('games')->insert($game);
            echo "✅ Added: {$game['name']} ({$game['category']})\n";
        }

        echo "🎉 Games seeding completed successfully!\n";
        echo "Total games: " . count($games) . "\n";
        echo "Categories: " . implode(', ', array_unique(array_column($games, 'category'))) . "\n";
    }
}

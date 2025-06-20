<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Game;

class GameSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $games = [
            [
                'slug' => 'word-grid-blitz',
                'title' => 'Word Grid Blitz',
                'description' => 'Find words in a 4×4 grid before time runs out. Challenge your vocabulary and quick thinking skills.',
                'mechanic' => 'word',
                'config' => [
                    'grid_size' => 4,
                    'min_word_length' => 3,
                    'max_word_length' => 8,
                    'time_limit' => 180,
                    'points_per_letter' => 10,
                    'bonus_long_word' => 50,
                    'difficulty_progression' => true
                ],
                'token_cost' => 10,
                'max_score_reward' => 100,
                'enabled' => true
            ],
            [
                'slug' => 'number-merge-2048',
                'title' => 'Number Merge 2048+',
                'description' => 'Enhanced 2048 with power-ups and multipliers. Merge numbers to reach the highest score possible.',
                'mechanic' => 'puzzle',
                'config' => [
                    'board_size' => 4,
                    'target_tile' => 2048,
                    'power_ups' => ['bomb', 'shuffle', 'undo'],
                    'combo_multiplier' => true,
                    'time_limit' => 300,
                    'special_tiles' => true
                ],
                'token_cost' => 15,
                'max_score_reward' => 150,
                'enabled' => true
            ],
            [
                'slug' => 'codebreaker',
                'title' => 'Codebreaker',
                'description' => 'Break the daily 4-digit code with logic and deduction. Each day brings a new challenge.',
                'mechanic' => 'logic',
                'config' => [
                    'code_length' => 4,
                    'max_attempts' => 15,
                    'max_attempts_per_day' => 15,
                    'daily_code' => true,
                    'hint_system' => true,
                    'difficulty_levels' => ['easy', 'medium', 'hard'],
                    'time_bonus' => true
                ],
                'token_cost' => 5,
                'max_score_reward' => 75,
                'enabled' => true
            ],
            [
                'slug' => 'rapid-recall',
                'title' => 'Rapid Recall',
                'description' => 'Memory sequence challenge with increasing difficulty. Test your ability to remember and repeat patterns.',
                'mechanic' => 'memory',
                'config' => [
                    'initial_sequence_length' => 3,
                    'max_sequence_length' => 15,
                    'time_per_item' => 1.5,
                    'pattern_types' => ['color', 'sound', 'number'],
                    'difficulty_progression' => true,
                    'lives' => 3
                ],
                'token_cost' => 8,
                'max_score_reward' => 80,
                'enabled' => true
            ],
            [
                'slug' => 'letter-leap',
                'title' => 'Letter Leap',
                'description' => 'Fast-paced letter matching and word formation. Create words as letters cascade down the screen.',
                'mechanic' => 'word',
                'config' => [
                    'min_word_length' => 3,
                    'cascade_speed' => 'normal',
                    'cascade_multiplier' => 1.5,
                    'time_limit' => 120,
                    'special_letters' => true,
                    'combo_system' => true
                ],
                'token_cost' => 12,
                'max_score_reward' => 120,
                'enabled' => true
            ],
            [
                'slug' => 'math-sprint-duel',
                'title' => 'Math Sprint Duel',
                'description' => 'Race against time solving math problems. From basic arithmetic to advanced calculations.',
                'mechanic' => 'math',
                'config' => [
                    'time_limit' => 60,
                    'difficulty_levels' => ['easy', 'medium', 'hard'],
                    'problems_per_level' => 10,
                    'operations' => ['addition', 'subtraction', 'multiplication', 'division'],
                    'streak_bonus' => true,
                    'time_bonus' => true
                ],
                'token_cost' => 10,
                'max_score_reward' => 100,
                'enabled' => true
            ],
            [
                'slug' => 'pixel-reveal',
                'title' => 'Pixel Reveal',
                'description' => 'Guess the image as pixels are revealed. The faster you guess, the higher your score.',
                'mechanic' => 'visual',
                'config' => [
                    'time_limit' => 120,
                    'max_guesses' => 5,
                    'reveal_rate' => 3,
                    'bonus_multiplier' => 2.0,
                    'categories' => ['animals', 'objects', 'landmarks', 'famous_people'],
                    'hint_system' => true,
                    'time_bonus' => true
                ],
                'token_cost' => 7,
                'max_score_reward' => 70,
                'enabled' => true
            ],
            [
                'slug' => 'geo-sprint',
                'title' => 'Geo Sprint',
                'description' => 'Geography trivia challenge focused on Ethiopia and Africa. Test your knowledge of the continent.',
                'mechanic' => 'trivia',
                'config' => [
                    'questions_per_round' => 10,
                    'time_per_question' => 15,
                    'categories' => ['ethiopia', 'africa', 'world'],
                    'difficulty_levels' => ['easy', 'medium', 'hard'],
                    'lifelines' => ['50_50', 'hint'],
                    'streak_bonus' => true
                ],
                'token_cost' => 8,
                'max_score_reward' => 90,
                'enabled' => true
            ],
            [
                'slug' => 'crazy',
                'title' => 'Crazy (Ethiopian Card Game)',
                'description' => 'Traditional Ethiopian card game using two 52-card decks. Play strategically with special cards and penalties.',
                'mechanic' => 'card',
                'config' => [
                    'decks' => 2,
                    'cards_per_player' => 5,
                    'max_players' => 8,
                    'min_players' => 2,
                    'special_cards' => ['2', '5', '7', '8', 'J', 'A♠'],
                    'penalties' => [
                        'wrong_card' => 2,
                        'false_joker' => 5,
                        'forgot_qeregn' => 2
                    ],
                    'direction' => 'counter_clockwise',
                    'ethiopian_terms' => ['qeregn', 'yelegnm']
                ],
                'token_cost' => 15,
                'max_score_reward' => 200,
                'enabled' => true
            ],
            [
                'slug' => 'hangman',
                'title' => 'Hangman',
                'description' => 'Classic word guessing game. Guess the word letter by letter before the drawing is complete.',
                'mechanic' => 'word',
                'config' => [
                    'min_word_length' => 4,
                    'max_word_length' => 12,
                    'max_wrong_guesses' => 7,
                    'time_limit' => 120,
                    'dictionary_size' => 400,
                    'scoring' => [
                        'base_points' => 100,
                        'letter_bonus' => 10,
                        'wrong_guess_penalty' => 20,
                        'time_bonus_max' => 50,
                        'perfect_game_bonus' => 200
                    ],
                    'difficulty_levels' => ['easy', 'medium', 'hard'],
                    'languages' => ['en'],
                    'lives' => 7,
                    'max_attempts_per_day' => 20
                ],
                'token_cost' => 9,
                'max_score_reward' => 100,
                'enabled' => true,
                'instructions' => [
                    'en' => 'Guess the hidden word by selecting letters. You have 7 wrong guesses before the hangman is complete. Correct guesses reveal all instances of that letter.',
                    'am' => 'የተደበቀውን ቃል ፊደላትን በመምረጥ ይገምቱ። የሰቅላው ስዕል ከመጠናቀቁ በፊት 7 የተሳሳቱ ግምቶች አሉዎት። ትክክለኛ ግምቶች የዚያን ፊደል ሁሉንም ምሳሌዎች ያሳያሉ።'
                ]
            ]
        ];

        foreach ($games as $gameData) {
            Game::updateOrCreate(
                ['slug' => $gameData['slug']],
                $gameData
            );
        }

        $this->command->info('Games seeded successfully!');
    }
} 
<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Game Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for all GameHub-ET games including costs, rewards,
    | and game-specific settings.
    |
    */

    'default_token_cost' => env('GAME_DEFAULT_TOKEN_COST', 10),
    'winner_token_reward' => env('GAME_WINNER_TOKEN_REWARD', 50),
    'daily_bonus_tokens' => env('GAME_DAILY_BONUS_TOKENS', 20),

    'games' => [
        'word-grid-blitz' => [
            'title' => 'Word Grid Blitz',
            'description' => 'Find words in a 4×4 grid before time runs out',
            'token_cost' => 10,
            'max_score_reward' => 100,
            'time_limit' => 180, // 3 minutes
            'grid_size' => 4,
            'min_word_length' => 3,
            'enabled' => true,
        ],
        'number-merge-2048' => [
            'title' => 'Number Merge 2048+',
            'description' => 'Enhanced 2048 with power-ups and multipliers',
            'token_cost' => 15,
            'max_score_reward' => 150,
            'time_limit' => 300, // 5 minutes
            'board_size' => 4,
            'target_tile' => 2048,
            'enabled' => true,
        ],
        'codebreaker' => [
            'title' => 'Codebreaker',
            'description' => 'Break the daily 4-digit code with logic',
            'token_cost' => 5,
            'max_score_reward' => 75,
            'max_attempts' => 15,
            'max_attempts_per_day' => 15,
            'code_length' => 4,
            'daily_code' => true,
            'enabled' => true,
        ],
        'rapid-recall' => [
            'title' => 'Rapid Recall',
            'description' => 'Memory sequence challenge with increasing difficulty',
            'token_cost' => 8,
            'max_score_reward' => 80,
            'initial_sequence_length' => 3,
            'max_sequence_length' => 15,
            'time_per_item' => 1.5,
            'enabled' => true,
        ],
        'letter-leap' => [
            'title' => 'Letter Leap',
            'description' => 'Fast-paced letter matching and word formation',
            'token_cost' => 12,
            'max_score_reward' => 120,
            'time_limit' => 120, // 2 minutes
            'min_word_length' => 3,
            'cascade_multiplier' => 1.5,
            'enabled' => true,
        ],
        'math-sprint-duel' => [
            'title' => 'Math Sprint Duel',
            'description' => 'Race against time solving math problems',
            'token_cost' => 10,
            'max_score_reward' => 100,
            'time_limit' => 60, // 1 minute
            'difficulty_levels' => ['easy', 'medium', 'hard'],
            'problems_per_level' => 10,
            'enabled' => true,
        ],
        'pixel-reveal' => [
            'title' => 'Pixel Reveal',
            'description' => 'Guess the image as pixels are revealed',
            'token_cost' => 7,
            'max_score_reward' => 70,
            'time_limit' => 120, // 2 minutes
            'max_guesses' => 5,
            'reveal_rate' => 3, // pixels per second - faster for 2 minute gameplay
            'bonus_multiplier' => 2.0,
            'enabled' => true,
        ],
        'geo-sprint' => [
            'title' => 'Geo Sprint',
            'description' => 'Geography trivia challenge focused on Ethiopia and Africa',
            'token_cost' => 8,
            'max_score_reward' => 90,
            'questions_per_round' => 10,
            'time_per_question' => 15,
            'categories' => ['ethiopia', 'africa', 'world'],
            'enabled' => true,
        ],
        'mines' => [
            'title' => 'Mines',
            'description' => 'Risk-reward game: reveal safe tiles to increase multiplier, avoid bombs',
            'token_cost' => 1,
            'max_score_reward' => 2200, // 22x multiplier * 100
            'grid_size' => 5,
            'total_tiles' => 25,
            'bomb_count' => 3,
            'flag_cost' => 0.5,
            'base_multiplier' => 1.2,
            'multiplier_growth' => 1.2,
            'max_safe_tiles' => 22, // 25 - 3 bombs
            'enabled' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Leaderboard Settings
    |--------------------------------------------------------------------------
    */
    'leaderboard' => [
        'daily_reset_time' => '00:00',
        'weekly_reset_day' => 'monday',
        'top_players_count' => 100,
        'cache_ttl' => 300, // 5 minutes
    ],

    /*
    |--------------------------------------------------------------------------
    | Anti-Cheat Settings
    |--------------------------------------------------------------------------
    */
    'anti_cheat' => [
        'score_validation' => true,
        'time_validation' => true,
        'sequence_validation' => true,
        'max_score_multiplier' => 1.5, // Max realistic score vs perfect play
        'min_time_threshold' => 5, // Minimum seconds per game
    ],
]; 
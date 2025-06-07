<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Game;
use App\Models\Round;
use Illuminate\Database\Seeder;

class TestDataSeeder extends Seeder
{
    public function run(): void
    {
        $users = User::all();
        $games = Game::all();

        // Create test rounds for leaderboard data
        foreach ($games as $game) {
            foreach ($users as $user) {
                if (rand(1, 3) == 1) { // 33% chance per user per game
                    $duration = rand(30, 300); // 30 seconds to 5 minutes
                    $score = rand(100, 10000);
                    Round::create([
                        'user_id' => $user->id,
                        'game_id' => $game->id,
                        'status' => 'completed',
                        'score' => $score,
                        'moves' => [],
                        'started_at' => now()->subDays(rand(1, 30)),
                        'completed_at' => now()->subDays(rand(1, 30))->addMinutes(rand(1, 60)),
                        'reward_tokens' => rand(10, 100),
                        'cost_tokens' => rand(5, 15),
                        'experience_gained' => rand(10, 50),
                        'duration_ms' => $duration * 1000,
                        'completion_time' => $duration,
                        'score_hash' => hash('sha256', $score . $user->id . $game->id),
                    ]);
                }
            }
        }

        echo "Test rounds created: " . Round::count() . "\n";
    }
} 
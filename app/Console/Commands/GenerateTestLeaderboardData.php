<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\Game;
use App\Models\Leaderboard;
use App\Services\HistoricalLeaderboardService;
use Carbon\Carbon;

class GenerateTestLeaderboardData extends Command
{
    protected $signature = 'test:generate-leaderboard-data';
    protected $description = 'Generate test leaderboard data for development';

    public function handle()
    {
        $this->info('Generating test leaderboard data...');

        $users = User::all();
        $games = Game::all();
        $service = app(HistoricalLeaderboardService::class);

        // Clear existing current leaderboard data
        Leaderboard::where('period_type', 'current')->delete();

        foreach ($users as $user) {
            foreach ($games as $game) {
                // Generate random scores for this user/game combination
                $scores = [];
                $numGames = rand(3, 8);
                
                for ($i = 0; $i < $numGames; $i++) {
                    $scores[] = rand(100, 2500);
                }

                $this->info("Generating {$numGames} games for {$user->name} in {$game->title}");

                // Apply each score to build up the leaderboard
                foreach ($scores as $score) {
                    $service->updateLeaderboards($user, $game, $score);
                }
            }
        }

        $this->info('Test leaderboard data generated successfully!');
        
        // Show some stats
        $totalEntries = Leaderboard::current()->count();
        $totalPlayers = Leaderboard::current()->distinct('user_id')->count();
        
        $this->info("Generated {$totalEntries} leaderboard entries for {$totalPlayers} players");
    }
} 
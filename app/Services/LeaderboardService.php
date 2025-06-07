<?php

namespace App\Services;

use App\Models\Game;
use App\Models\Leaderboard;
use App\Models\Round;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class LeaderboardService
{
    /**
     * Update leaderboard entry for a user after completing a game
     */
    public function updateLeaderboard(User $user, Game $game, int $score): void
    {
        $leaderboard = $user->leaderboards()->where('game_id', $game->id)->first();

        if (!$leaderboard || $score > $leaderboard->best_score) {
            $user->leaderboards()->updateOrCreate(
                ['game_id' => $game->id],
                [
                    'best_score' => $score,
                    'total_plays' => DB::raw('total_plays + 1'),
                    'last_played_at' => now()
                ]
            );
        } else {
            $leaderboard->increment('total_plays');
            $leaderboard->update(['last_played_at' => now()]);
        }

        // Clear relevant caches
        $this->clearRelatedCaches($user, $game);
    }

    /**
     * Calculate and update ranks for all leaderboards
     * This should be run as a scheduled task
     */
    public function updateAllRanks(): void
    {
        $games = Game::enabled()->get();

        foreach ($games as $game) {
            $this->updateGameRanks($game);
        }

        $this->updateGlobalRanks();
    }

    /**
     * Update ranks for a specific game
     */
    public function updateGameRanks(Game $game): void
    {
        // Update all-time ranks
        $this->updateRanksForPeriod($game, 'all-time');
        
        // Update daily ranks
        $this->updateRanksForPeriod($game, 'daily');
        
        // Update weekly ranks  
        $this->updateRanksForPeriod($game, 'weekly');
        
        // Update monthly ranks
        $this->updateRanksForPeriod($game, 'monthly');
    }

    /**
     * Update ranks for a specific game and period
     */
    private function updateRanksForPeriod(Game $game, string $period): void
    {
        $query = Leaderboard::where('game_id', $game->id);

        // Apply period filter
        switch ($period) {
            case 'daily':
                $query->whereDate('last_played_at', today());
                break;
            case 'weekly':
                $query->where('last_played_at', '>=', now()->startOfWeek());
                break;
            case 'monthly':
                $query->where('last_played_at', '>=', now()->startOfMonth());
                break;
            // 'all-time' - no filter
        }

        $leaderboards = $query->orderBy('best_score', 'desc')->get();

        foreach ($leaderboards as $index => $leaderboard) {
            $rank = $index + 1;
            
            // Store rank in cache with period-specific key
            $cacheKey = "rank_{$game->id}_{$leaderboard->user_id}_{$period}";
            Cache::put($cacheKey, $rank, now()->addHours(6));
        }
    }

    /**
     * Update global ranks across all games
     */
    public function updateGlobalRanks(): void
    {
        $userScores = Leaderboard::select('user_id')
            ->selectRaw('SUM(best_score) as total_score')
            ->groupBy('user_id')
            ->orderBy('total_score', 'desc')
            ->get();

        foreach ($userScores as $index => $userScore) {
            $rank = $index + 1;
            $cacheKey = "global_rank_{$userScore->user_id}";
            Cache::put($cacheKey, $rank, now()->addHours(6));
        }
    }

    /**
     * Get player's rank from cache or calculate it
     */
    public function getPlayerRank(User $user, Game $game, string $period = 'all-time'): int
    {
        $cacheKey = "rank_{$game->id}_{$user->id}_{$period}";
        
        return Cache::remember($cacheKey, 3600, function () use ($user, $game, $period) {
            return $this->calculatePlayerRank($user, $game, $period);
        });
    }

    /**
     * Calculate player's rank for a specific game and period
     */
    private function calculatePlayerRank(User $user, Game $game, string $period): int
    {
        $playerEntry = $this->getPlayerEntry($user, $game, $period);
        
        if (!$playerEntry) {
            return 0;
        }

        $query = Leaderboard::where('game_id', $game->id);

        // Apply period filter
        switch ($period) {
            case 'daily':
                $query->whereDate('last_played_at', today());
                break;
            case 'weekly':
                $query->where('last_played_at', '>=', now()->startOfWeek());
                break;
            case 'monthly':
                $query->where('last_played_at', '>=', now()->startOfMonth());
                break;
            case 'yearly':
                $query->where('last_played_at', '>=', now()->startOfYear());
                break;
        }

        return $query->where('best_score', '>', $playerEntry->best_score)->count() + 1;
    }

    /**
     * Get player's leaderboard entry for a specific game and period
     */
    private function getPlayerEntry(User $user, Game $game, string $period): ?Leaderboard
    {
        $query = Leaderboard::where('user_id', $user->id)
            ->where('game_id', $game->id);

        // Apply period filter
        switch ($period) {
            case 'daily':
                $query->whereDate('last_played_at', today());
                break;
            case 'weekly':
                $query->where('last_played_at', '>=', now()->startOfWeek());
                break;
            case 'monthly':
                $query->where('last_played_at', '>=', now()->startOfMonth());
                break;
            case 'yearly':
                $query->where('last_played_at', '>=', now()->startOfYear());
                break;
        }

        return $query->first();
    }

    /**
     * Get leaderboard statistics for a game
     */
    public function getGameStats(Game $game, string $period = 'all-time'): array
    {
        $cacheKey = "game_stats_{$game->id}_{$period}";

        return Cache::remember($cacheKey, 1800, function () use ($game, $period) {
            $query = Leaderboard::where('game_id', $game->id);

            // Apply period filter
            switch ($period) {
                case 'daily':
                    $query->whereDate('last_played_at', today());
                    break;
                case 'weekly':
                    $query->where('last_played_at', '>=', now()->startOfWeek());
                    break;
                case 'monthly':
                    $query->where('last_played_at', '>=', now()->startOfMonth());
                    break;
                case 'yearly':
                    $query->where('last_played_at', '>=', now()->startOfYear());
                    break;
            }

            $stats = $query->selectRaw('
                COUNT(*) as total_players,
                SUM(total_plays) as total_plays,
                AVG(best_score) as average_score,
                MAX(best_score) as highest_score,
                MIN(best_score) as lowest_score
            ')->first();

            return [
                'total_players' => $stats->total_players ?? 0,
                'total_plays' => $stats->total_plays ?? 0,
                'average_score' => round($stats->average_score ?? 0, 1),
                'highest_score' => $stats->highest_score ?? 0,
                'lowest_score' => $stats->lowest_score ?? 0
            ];
        });
    }

    /**
     * Clear caches related to a user and game
     */
    private function clearRelatedCaches(User $user, Game $game): void
    {
        $periods = ['all-time', 'daily', 'weekly', 'monthly', 'yearly'];
        
        foreach ($periods as $period) {
            // Clear specific player rank caches
            Cache::forget("rank_{$game->id}_{$user->id}_{$period}");
            Cache::forget("player_rank_{$user->id}_{$game->id}_{$period}");
            
            // Clear leaderboard caches
            $limits = [50, 100, 500];
            foreach ($limits as $limit) {
                Cache::forget("game_leaderboard_{$game->id}_{$period}_{$limit}");
                Cache::forget("global_leaderboard_{$period}_{$limit}");
                Cache::forget("top_players_{$period}_{$limit}");
            }
        }

        // Clear player stats
        Cache::forget("player_stats_{$user->id}");
        Cache::forget("global_rank_{$user->id}");
        
        // Clear game stats
        foreach ($periods as $period) {
            Cache::forget("game_stats_{$game->id}_{$period}");
        }
    }

    /**
     * Get trending games based on recent activity
     */
    public function getTrendingGames(int $limit = 10): array
    {
        $cacheKey = "trending_games_{$limit}";

        return Cache::remember($cacheKey, 1800, function () use ($limit) {
            return Leaderboard::select('game_id')
                ->selectRaw('COUNT(*) as recent_players')
                ->selectRaw('SUM(total_plays) as recent_plays')
                ->where('last_played_at', '>=', now()->subDays(7))
                ->with('game')
                ->groupBy('game_id')
                ->orderBy('recent_plays', 'desc')
                ->limit($limit)
                ->get()
                ->map(function ($entry) {
                    return [
                        'game' => [
                            'id' => $entry->game->id,
                            'name' => $entry->game->title,
                            'slug' => $entry->game->slug,
                            'icon' => $entry->game->getConfigValue('icon', '🎮')
                        ],
                        'recent_players' => $entry->recent_players,
                        'recent_plays' => $entry->recent_plays
                    ];
                })
                ->toArray();
        });
    }

    /**
     * Reset daily leaderboards (should be run daily at midnight)
     */
    public function resetDailyLeaderboards(): void
    {
        // Clear daily-related caches
        $games = Game::enabled()->get();
        
        foreach ($games as $game) {
            Cache::forget("game_stats_{$game->id}_daily");
            
            $limits = [50, 100, 500];
            foreach ($limits as $limit) {
                Cache::forget("game_leaderboard_{$game->id}_daily_{$limit}");
            }
        }

        $limits = [50, 100];
        foreach ($limits as $limit) {
            Cache::forget("global_leaderboard_daily_{$limit}");
            Cache::forget("top_players_daily_{$limit}");
        }
    }

    /**
     * Reset weekly leaderboards (should be run weekly)
     */
    public function resetWeeklyLeaderboards(): void
    {
        // Similar to daily reset but for weekly caches
        $games = Game::enabled()->get();
        
        foreach ($games as $game) {
            Cache::forget("game_stats_{$game->id}_weekly");
            
            $limits = [50, 100, 500];
            foreach ($limits as $limit) {
                Cache::forget("game_leaderboard_{$game->id}_weekly_{$limit}");
            }
        }

        $limits = [50, 100];
        foreach ($limits as $limit) {
            Cache::forget("global_leaderboard_weekly_{$limit}");
            Cache::forget("top_players_weekly_{$limit}");
        }
    }
} 
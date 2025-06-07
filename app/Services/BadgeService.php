<?php

namespace App\Services;

use App\Models\User;
use App\Models\Game;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Cache;

class BadgeService
{
    /**
     * Get the highest precedence badge for a user
     */
    public function getUserBadge(User $user, ?string $gameSlug = null): ?array
    {
        // Create cache key
        $cacheKey = $gameSlug ? "user_badge_{$user->id}_{$gameSlug}" : "user_badge_{$user->id}";
        
        return Cache::remember($cacheKey, 600, function () use ($user, $gameSlug) {
            return $this->calculateUserBadge($user, $gameSlug);
        });
    }
    
    /**
     * Calculate the highest precedence badge for a user (uncached)
     */
    private function calculateUserBadge(User $user, ?string $gameSlug = null): ?array
    {
        $badges = Config::get('badges.tiers');
        $precedence = Config::get('badges.precedence');
        
        $userBadges = [];
        
        // Check for DemiGod (only the single person with highest total score across all games)
        if ($this->isDemiGod($user)) {
            $userBadges[] = 'DEMIGOD';
        }
        
        // Check game-specific badges (only if not DemiGod, or if checking specific game)
        if ($gameSlug) {
            $gameRank = $this->getGameRank($user, $gameSlug);
            $gameBadge = $this->getBadgeForRank($gameRank, 'game');
            if ($gameBadge) {
                $userBadges[] = $gameBadge;
            }
        } else {
            // Only check for game badges if user is not already DemiGod
            if (!in_array('DEMIGOD', $userBadges)) {
                // Check all games for any badges
                $games = Game::all();
                foreach ($games as $game) {
                    $gameRank = $this->getGameRank($user, $game->slug);
                    $gameBadge = $this->getBadgeForRank($gameRank, 'game');
                    if ($gameBadge) {
                        $userBadges[] = $gameBadge;
                        // For Nigus (game leader), we only need to find one
                        if ($gameBadge === 'NIGUS') {
                            break;
                        }
                    }
                }
            }
        }
        
        // Return highest precedence badge
        if (empty($userBadges)) {
            return null;
        }
        
        foreach ($precedence as $tier) {
            if (in_array($tier, $userBadges)) {
                return [
                    'code' => $tier,
                    'label' => $badges[$tier]['label'],
                    'icon' => $badges[$tier]['icon'],
                    'scope' => $badges[$tier]['scope']
                ];
            }
        }
        
        return null;
    }
    
    /**
     * Check if user is the DemiGod (single person with highest total score)
     */
    private function isDemiGod(User $user): bool
    {
        // Get the user with the highest total score across all games
        $topUser = \App\Models\Leaderboard::current()
            ->selectRaw('user_id, SUM(best_score) as total_score')
            ->groupBy('user_id')
            ->orderByRaw('SUM(best_score) DESC')
            ->first();
            
        return $topUser && $topUser->user_id === $user->id;
    }

    /**
     * Get user's global ranking across all games
     * Global rank is based on the sum of best scores across all games
     */
    private function getGlobalRank(User $user): ?int
    {
        // Calculate user's total score across all games (sum of best scores)
        $userTotalScore = \App\Models\Leaderboard::current()
            ->where('user_id', $user->id)
            ->sum('best_score');
            
        if (!$userTotalScore) {
            return null;
        }
        
        // Count how many users have a higher total score
        $rank = \App\Models\Leaderboard::current()
            ->selectRaw('user_id, SUM(best_score) as total_score')
            ->groupBy('user_id')
            ->havingRaw('SUM(best_score) > ?', [$userTotalScore])
            ->count();
            
        return $rank + 1;
    }
    
    /**
     * Get user's rank in a specific game
     */
    private function getGameRank(User $user, string $gameSlug): ?int
    {
        $game = Game::where('slug', $gameSlug)->first();
        if (!$game) {
            return null;
        }
        
        $userEntry = \App\Models\Leaderboard::current()
            ->where('user_id', $user->id)
            ->where('game_id', $game->id)
            ->first();
            
        if (!$userEntry) {
            return null;
        }
        
        $rank = \App\Models\Leaderboard::current()
            ->where('game_id', $game->id)
            ->where('best_score', '>', $userEntry->best_score)
            ->count();
            
        return $rank + 1;
    }
    
    /**
     * Get badge tier for a given rank and scope
     */
    private function getBadgeForRank(?int $rank, string $scope): ?string
    {
        if (!$rank) {
            return null;
        }
        
        $badges = Config::get('badges.tiers');
        
        foreach ($badges as $tier => $config) {
            if ($config['scope'] === $scope && 
                $rank >= $config['rank_from'] && 
                $rank <= $config['rank_to']) {
                return $tier;
            }
        }
        
        return null;
    }
    
    /**
     * Get all badges for multiple users (batch operation)
     */
    public function getUsersBadges(array $users, ?string $gameSlug = null): array
    {
        $result = [];
        
        foreach ($users as $user) {
            $badge = $this->getUserBadge($user, $gameSlug);
            $result[$user->id] = $badge;
        }
        
        return $result;
    }
} 
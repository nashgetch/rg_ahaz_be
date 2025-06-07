<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Leaderboard;
use App\Services\BadgeService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class PlayerStatsController extends Controller
{
    protected $badgeService;

    public function __construct(BadgeService $badgeService)
    {
        $this->badgeService = $badgeService;
    }

    /**
     * Get player stats by player ID (using same logic as LeaderboardController::shareStats)
     */
    public function getPlayerStats(Request $request, $playerId): JsonResponse
    {
        try {
            // Find the player
            $player = User::find($playerId);
            
            if (!$player) {
                return response()->json([
                    'success' => false,
                    'message' => 'Player not found'
                ], 404);
            }

            $cacheKey = "leaderboards:player_stats:user:{$playerId}";

            $playerData = Cache::remember($cacheKey, 1800, function () use ($player) {
                // Current stats from leaderboard
                $currentStats = Leaderboard::current()
                    ->where('user_id', $player->id)
                    ->with('game')
                    ->get()
                    ->map(function ($entry) use ($player) {
                        // Get badge for this specific game
                        $gameBadge = $this->badgeService->getUserBadge($player, $entry->game->slug);
                        
                        return [
                            'game' => [
                                'id' => $entry->game->id,
                                'name' => $entry->game->title,
                                'slug' => $entry->game->slug,
                                'icon' => $entry->game->getConfigValue('icon', '🎮')
                            ],
                            'badge' => $gameBadge,
                            'best_score' => $entry->best_score,
                            'average_score' => (float)$entry->average_score,
                            'total_plays' => $entry->total_plays,
                            'last_played_at' => $entry->last_played_at
                        ];
                    });

                // Get user's global badge
                $globalBadge = $this->badgeService->getUserBadge($player);

                // Calculate overall average score properly
                $totalScoreSum = 0;
                $totalPlaysSum = 0;
                foreach ($currentStats as $stats) {
                    // For overall average, we need to weight by number of plays
                    $totalScoreSum += $stats['average_score'] * $stats['total_plays'];
                    $totalPlaysSum += $stats['total_plays'];
                }
                $overallAverage = $totalPlaysSum > 0 ? $totalScoreSum / $totalPlaysSum : 0;

                // Overall statistics
                $overallStats = [
                    'games_played' => $currentStats->count(),
                    'total_plays' => $currentStats->sum('total_plays'),
                    'total_score' => $currentStats->sum('best_score'),
                    'average_best_score' => $currentStats->avg('best_score'),
                    'overall_average' => round($overallAverage, 2),
                    'highest_score' => $currentStats->max('best_score'),
                ];

                // User data
                $userData = [
                    'id' => $player->id,
                    'name' => $player->name,
                    'level' => $player->level ?? 1,
                    'avatar' => $player->avatar,
                    'created_at' => $player->created_at
                ];

                return [
                    'user' => $userData,
                    'stats' => [
                        'overall' => $overallStats,
                        'by_game' => $currentStats->toArray()
                    ],
                    'global_badge' => $globalBadge
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $playerData
            ]);

        } catch (\Exception $e) {
            \Log::error('Error fetching player stats for player ' . $playerId . ': ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch player stats'
            ], 500);
        }
    }
} 
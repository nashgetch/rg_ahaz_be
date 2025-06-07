<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\Leaderboard;
use App\Models\YearlyLeaderboard;
use App\Models\GamePeriodStats;
use App\Services\HistoricalLeaderboardService;
use App\Services\BadgeService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class LeaderboardController extends Controller
{
    protected HistoricalLeaderboardService $historicalService;
    protected BadgeService $badgeService;

    public function __construct(HistoricalLeaderboardService $historicalService, BadgeService $badgeService)
    {
        $this->historicalService = $historicalService;
        $this->badgeService = $badgeService;
    }

    /**
     * Get current/live leaderboards for all games
     */
    public function index(Request $request): JsonResponse
    {
        $limit = min((int)$request->get('limit', 50), 100);
        
        $cacheKey = "leaderboards:all:limit:{$limit}";
        
        $leaderboards = Cache::remember($cacheKey, 300, function () use ($limit) {
            // Get top players by total score across all games (sum of best scores)
            $entries = Leaderboard::current()
                ->selectRaw('
                    user_id,
                    COUNT(*) as games_played,
                    SUM(total_plays) as total_plays,
                    SUM(best_score) as total_score,
                    AVG(best_score) as average_best_score,
                    MAX(best_score) as highest_score
                ')
                ->with('user')
                ->groupBy('user_id')
                ->orderBy('total_score', 'desc')
                ->limit($limit)
                ->get();
                
            // Get badges for all users
            $users = $entries->map(fn($entry) => $entry->user)->unique('id')->values();
            $badges = $this->badgeService->getUsersBadges($users->all());
            
            return $entries->map(function ($entry, $index) use ($badges) {
                return [
                    'rank' => $index + 1,
                    'user' => [
                        'id' => $entry->user->id,
                        'name' => $entry->user->name,
                        'level' => $entry->user->level
                    ],
                    'badge' => $badges[$entry->user->id] ?? null,
                    'games_played' => $entry->games_played,
                    'total_plays' => $entry->total_plays,
                    'total_score' => $entry->total_score,
                    'average_best_score' => round($entry->average_best_score, 2),
                    'highest_score' => $entry->highest_score,
                    'last_played_at' => $entry->user->leaderboards()
                        ->current()
                        ->orderBy('last_played_at', 'desc')
                        ->first()
                        ->last_played_at ?? null
                ];
            })->toArray();
        });

        $totalPlayers = Cache::remember('leaderboards:total_players', 300, function () {
            return Leaderboard::current()->distinct('user_id')->count();
        });

        return response()->json([
            'success' => true,
            'data' => [
                'period' => 'current',
                'period_label' => 'Live Rankings',
                'total_players' => $totalPlayers,
                'leaderboards' => $leaderboards
            ]
        ]);
    }

    /**
     * Get leaderboard for a specific game
     */
    public function gameLeaderboard(Request $request, Game $game): JsonResponse
    {
        $period = $request->get('period', 'current');
        $periodKey = $request->get('period_key');
        $limit = min((int)$request->get('limit', 100), 200);

        $cacheKey = "leaderboards:game:{$game->id}:{$period}:{$periodKey}:limit:{$limit}";

        $leaderboard = Cache::remember($cacheKey, 300, function () use ($game, $period, $periodKey, $limit) {
            if ($period === 'monthly' && $periodKey) {
                return $this->historicalService->getMonthlyLeaderboard($game, $periodKey, $limit);
            } elseif ($period === 'yearly' && $periodKey) {
                $year = (int)$periodKey;
                return $this->historicalService->getYearlyLeaderboard($game, $year, $limit);
            } else {
                // Current leaderboard
                $entries = Leaderboard::current()
                    ->where('game_id', $game->id)
                    ->with('user')
                    ->orderBy('best_score', 'desc')
                    ->limit($limit)
                    ->get();
                    
                // Get badges for all users in this game context
                $users = $entries->map(fn($entry) => $entry->user)->unique('id')->values();
                $badges = $this->badgeService->getUsersBadges($users->all(), $game->slug);
                
                return $entries->map(function ($entry, $index) use ($badges) {
                    return [
                        'rank' => $index + 1,
                        'user' => [
                            'id' => $entry->user->id,
                            'name' => $entry->user->name,
                            'level' => $entry->user->level
                        ],
                        'badge' => $badges[$entry->user->id] ?? null,
                        'best_score' => $entry->best_score,
                        'average_score' => (float)$entry->average_score,
                        'total_score' => $entry->total_score,
                        'total_plays' => $entry->total_plays,
                        'last_played_at' => $entry->last_played_at
                    ];
                })->toArray();
            }
        });

        $totalPlayers = count($leaderboard);
        
        $periodLabel = match($period) {
            'monthly' => $periodKey ? Carbon::createFromFormat('Ym', $periodKey)->format('F Y') : 'This Month',
            'yearly' => $periodKey ? $periodKey : 'This Year',
            default => 'Live Rankings'
        };

        return response()->json([
            'success' => true,
            'data' => [
                'game' => [
                    'id' => $game->id,
                    'name' => $game->title,
                    'icon' => $game->getConfigValue('icon', '🎮')
                ],
                'period' => $period,
                'period_key' => $periodKey,
                'period_label' => $periodLabel,
                'total_players' => $totalPlayers,
                'leaderboard' => $leaderboard
            ]
        ]);
    }

    /**
     * Get available periods for a game
     */
    public function gamePeriods(Request $request, Game $game): JsonResponse
    {
        $cacheKey = "leaderboards:periods:game:{$game->id}";

        $periods = Cache::remember($cacheKey, 1800, function () use ($game) {
            return [
                'monthly' => $this->historicalService->getAvailableMonths($game),
                'yearly' => $this->historicalService->getAvailableYears($game)
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'game' => [
                    'id' => $game->id,
                    'name' => $game->title
                ],
                'periods' => $periods
            ]
        ]);
    }

    /**
     * Get top players across all games
     */
    public function topPlayers(Request $request): JsonResponse
    {
        $period = $request->get('period', 'current');
        $limit = min((int)$request->get('limit', 50), 100);

        $cacheKey = "leaderboards:top_players:{$period}:limit:{$limit}";

        $players = Cache::remember($cacheKey, 600, function () use ($period, $limit) {
            if ($period === 'current') {
                // Get top players by total score across all games (sum of best scores)
                $players = Leaderboard::current()
                    ->selectRaw('
                        user_id,
                        COUNT(*) as games_played,
                        SUM(total_plays) as total_plays,
                        SUM(best_score) as total_score,
                        AVG(best_score) as average_best_score,
                        MAX(best_score) as highest_score
                    ')
                    ->with('user')
                    ->groupBy('user_id')
                    ->orderBy('total_score', 'desc')
                    ->limit($limit)
                    ->get();
                    
                // Get badges for all users
                $users = $players->map(fn($entry) => $entry->user)->unique('id')->values();
                $badges = $this->badgeService->getUsersBadges($users->all());
                    
                $players = $players->map(function ($entry, $index) use ($badges) {
                        return [
                            'rank' => $index + 1,
                            'user' => [
                                'id' => $entry->user->id,
                                'name' => $entry->user->name,
                                'level' => $entry->user->level
                            ],
                            'badge' => $badges[$entry->user->id] ?? null,
                            'games_played' => $entry->games_played,
                            'total_plays' => $entry->total_plays,
                            'total_score' => $entry->total_score,
                            'average_best_score' => round($entry->average_best_score, 2),
                            'highest_score' => $entry->highest_score
                        ];
                    })
                    ->toArray();
            } else {
                // For historical periods, we'd need to implement similar logic
                // For now, return current data
                $players = [];
            }

            return $players;
        });

        return response()->json([
            'success' => true,
            'data' => [
                'period' => $period,
                'period_label' => $period === 'current' ? 'Live Rankings' : ucfirst($period),
                'players' => $players
            ]
        ]);
    }

    /**
     * Get player's rank in a specific game
     */
    public function playerRank(Request $request, Game $game): JsonResponse
    {
        $user = $request->user();
        $period = $request->get('period', 'current');
        $periodKey = $request->get('period_key');

        $cacheKey = "leaderboards:rank:user:{$user->id}:game:{$game->id}:{$period}:{$periodKey}";

        $rankData = Cache::remember($cacheKey, 60, function () use ($user, $game, $period, $periodKey) {
            $query = Leaderboard::where('game_id', $game->id);

            if ($period === 'monthly' && $periodKey) {
                $query->monthly($periodKey);
            } elseif ($period === 'yearly' && $periodKey) {
                $query->yearly($periodKey);
            } else {
                $query->current();
            }

            $userEntry = $query->where('user_id', $user->id)->first();

            if (!$userEntry) {
                return [
                    'user_entry' => null,
                    'rank' => null,
                    'total_players' => $query->distinct('user_id')->count(),
                    'percentile' => null
                ];
            }

            $rank = $query->where('best_score', '>', $userEntry->best_score)->count() + 1;
            $totalPlayers = $query->distinct('user_id')->count();
            $percentile = round((($totalPlayers - $rank) / $totalPlayers) * 100, 1);

            return [
                'user_entry' => [
                    'best_score' => $userEntry->best_score,
                    'average_score' => (float)$userEntry->average_score,
                    'total_score' => $userEntry->total_score,
                    'total_plays' => $userEntry->total_plays,
                    'last_played_at' => $userEntry->last_played_at
                ],
                'rank' => $rank,
                'total_players' => $totalPlayers,
                'percentile' => $percentile
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $rankData
        ]);
    }

    /**
     * Get comprehensive player statistics
     */
    public function playerStats(Request $request): JsonResponse
    {
        $user = $request->user();

        $cacheKey = "leaderboards:stats:user:{$user->id}";

        $stats = Cache::remember($cacheKey, 1800, function () use ($user) {
            // Current stats
            $currentStats = Leaderboard::current()
                ->where('user_id', $user->id)
                ->with('game')
                ->get()
                ->map(function ($entry) use ($user) {
                    // Get badge for this specific game
                    $gameBadge = $this->badgeService->getUserBadge($user, $entry->game->slug);
                    
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

            // Get user's overall badge
            $badge = $this->badgeService->getUserBadge($user);

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
                'badge' => $badge
            ];

            return [
                'overall' => $overallStats,
                'by_game' => $currentStats->toArray()
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    /**
     * Clear leaderboard cache (admin function)
     */
    public function clearCache(Request $request): JsonResponse
    {
        $user = $request->user();
        
        // In production, add admin middleware
        // For now, allowing any authenticated user
        
        $tags = [
            'leaderboards:*',
            'leaderboards:all:*',
            'leaderboards:game:*',
            'leaderboards:periods:*',
            'leaderboards:top_players:*',
            'leaderboards:rank:*',
            'leaderboards:stats:*'
        ];

        foreach ($tags as $tag) {
            Cache::forget($tag);
        }

        // Clear all cache keys that start with leaderboards
        $keys = Cache::getRedis()->keys('*leaderboards*');
        if (!empty($keys)) {
            Cache::getRedis()->del($keys);
        }

        return response()->json([
            'success' => true,
            'message' => 'Leaderboard cache cleared successfully'
        ]);
    }

    /**
     * Get public player statistics for sharing (no authentication required)
     */
    public function shareStats(Request $request, $userId): JsonResponse
    {
        try {
            $user = User::find($userId);
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Player not found'
                ], 404);
            }

            $cacheKey = "leaderboards:share:user:{$userId}";

            $shareData = Cache::remember($cacheKey, 1800, function () use ($user) {
                // Current stats
                $currentStats = Leaderboard::current()
                    ->where('user_id', $user->id)
                    ->with('game')
                    ->get()
                    ->map(function ($entry) use ($user) {
                        // Get badge for this specific game
                        $gameBadge = $this->badgeService->getUserBadge($user, $entry->game->slug);
                        
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
                $globalBadge = $this->badgeService->getUserBadge($user);

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

                // User data (limited for privacy)
                $userData = [
                    'id' => $user->id,
                    'name' => $user->name,
                    'level' => $user->level,
                    'avatar' => $user->avatar,
                    'created_at' => $user->created_at
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
                'data' => $shareData
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load player statistics'
            ], 500);
        }
    }
} 
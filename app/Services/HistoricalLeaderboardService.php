<?php

namespace App\Services;

use App\Models\Game;
use App\Models\Leaderboard;
use App\Models\YearlyLeaderboard;
use App\Models\GamePeriodStats;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class HistoricalLeaderboardService
{
    /**
     * Create monthly leaderboard table if it doesn't exist
     */
    public function ensureMonthlyTableExists(string $monthKey): void
    {
        $tableName = "leaderboards_{$monthKey}";
        
        if (!Schema::hasTable($tableName)) {
            Schema::create($tableName, function ($table) {
                $table->id();
                $table->foreignId('game_id')->constrained()->onDelete('cascade');
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->unsignedInteger('best_score')->default(0);
                $table->decimal('average_score', 10, 2)->default(0);
                $table->unsignedInteger('total_score')->default(0);
                $table->unsignedInteger('total_plays')->default(0);
                $table->timestamp('first_played_at')->nullable();
                $table->timestamp('last_played_at')->nullable();
                $table->timestamps();

                $table->unique(['game_id', 'user_id']);
                $table->index(['game_id', 'average_score']);
                $table->index(['average_score']);
            });
        }
    }

    /**
     * Update leaderboard entry for a user after completing a game
     */
    public function updateLeaderboards(User $user, Game $game, int $score): void
    {
        $now = now();
        $monthKey = $now->format('Ym'); // 202501
        $yearKey = $now->format('Y');   // 2025

        // Store previous rankings to detect badge changes
        $previousGlobalTop = $this->getGlobalTopUser();
        $previousGameTop = $this->getGameTopUser($game);

        // Update current leaderboard (for real-time rankings)
        $this->updateCurrentLeaderboard($user, $game, $score, $now);

        // Update monthly leaderboard
        $this->updateMonthlyLeaderboard($user, $game, $score, $monthKey, $now);

        // Update yearly leaderboard
        $this->updateYearlyLeaderboard($user, $game, $score, (int)$yearKey, $now);

        // Update monthly stats
        $this->updateMonthlyStats($game, $monthKey);

        // Update yearly stats
        $this->updateYearlyStats($game, $yearKey);

        // Invalidate badge caches if rankings changed
        $this->invalidateBadgeCaches($user, $game, $previousGlobalTop, $previousGameTop);

        // Clear general leaderboard caches
        $this->clearLeaderboardCaches($game);
    }

    /**
     * Update current leaderboard (for real-time access)
     */
    private function updateCurrentLeaderboard(User $user, Game $game, int $score, Carbon $now): void
    {
        $leaderboard = Leaderboard::current()
            ->where('user_id', $user->id)
            ->where('game_id', $game->id)
            ->first();

        if ($leaderboard) {
            // Update existing entry
            $newTotalScore = $leaderboard->total_score + $score;
            $newTotalPlays = $leaderboard->total_plays + 1;
            $newAverageScore = round($newTotalScore / $newTotalPlays, 2);
            $newBestScore = max($leaderboard->best_score, $score);

            $leaderboard->update([
                'best_score' => $newBestScore,
                'average_score' => $newAverageScore,
                'total_score' => $newTotalScore,
                'total_plays' => $newTotalPlays,
                'last_played_at' => $now
            ]);
        } else {
            // Create new entry - use updateOrCreate to avoid duplicates
            Leaderboard::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'game_id' => $game->id,
                    'period_type' => 'current',
                    'period_key' => null
                ],
                [
                    'best_score' => $score,
                    'average_score' => $score,
                    'total_score' => $score,
                    'total_plays' => 1,
                    'last_played_at' => $now
                ]
            );
        }
    }

    /**
     * Update monthly leaderboard (stored in main leaderboards table)
     */
    private function updateMonthlyLeaderboard(User $user, Game $game, int $score, string $monthKey, Carbon $now): void
    {
        $leaderboard = Leaderboard::monthly($monthKey)
            ->where('user_id', $user->id)
            ->where('game_id', $game->id)
            ->first();

        if ($leaderboard) {
            // Update existing monthly entry
            $newTotalScore = $leaderboard->total_score + $score;
            $newTotalPlays = $leaderboard->total_plays + 1;
            $newAverageScore = round($newTotalScore / $newTotalPlays, 2);
            $newBestScore = max($leaderboard->best_score, $score);

            $leaderboard->update([
                'best_score' => $newBestScore,
                'average_score' => $newAverageScore,
                'total_score' => $newTotalScore,
                'total_plays' => $newTotalPlays,
                'last_played_at' => $now
            ]);
        } else {
            // Create new monthly entry - use updateOrCreate to avoid duplicates
            Leaderboard::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'game_id' => $game->id,
                    'period_type' => 'monthly',
                    'period_key' => $monthKey
                ],
                [
                    'best_score' => $score,
                    'average_score' => $score,
                    'total_score' => $score,
                    'total_plays' => 1,
                    'last_played_at' => $now
                ]
            );
        }

        // Also update the dynamic monthly table
        $this->updateDynamicMonthlyTable($user, $game, $score, $monthKey, $now);
    }

    /**
     * Update dynamic monthly table (leaderboards_202501)
     */
    private function updateDynamicMonthlyTable(User $user, Game $game, int $score, string $monthKey, Carbon $now): void
    {
        $this->ensureMonthlyTableExists($monthKey);
        $tableName = "leaderboards_{$monthKey}";

        $existing = DB::table($tableName)
            ->where('user_id', $user->id)
            ->where('game_id', $game->id)
            ->first();

        if ($existing) {
            $newTotalScore = $existing->total_score + $score;
            $newTotalPlays = $existing->total_plays + 1;
            $newAverageScore = round($newTotalScore / $newTotalPlays, 2);
            $newBestScore = max($existing->best_score, $score);

            DB::table($tableName)
                ->where('id', $existing->id)
                ->update([
                    'best_score' => $newBestScore,
                    'average_score' => $newAverageScore,
                    'total_score' => $newTotalScore,
                    'total_plays' => $newTotalPlays,
                    'last_played_at' => $now,
                    'updated_at' => $now
                ]);
        } else {
            DB::table($tableName)->insert([
                'user_id' => $user->id,
                'game_id' => $game->id,
                'best_score' => $score,
                'average_score' => $score,
                'total_score' => $score,
                'total_plays' => 1,
                'first_played_at' => $now,
                'last_played_at' => $now,
                'created_at' => $now,
                'updated_at' => $now
            ]);
        }
    }

    /**
     * Update yearly leaderboard
     */
    private function updateYearlyLeaderboard(User $user, Game $game, int $score, int $year, Carbon $now): void
    {
        $yearlyLeaderboard = YearlyLeaderboard::where('user_id', $user->id)
            ->where('game_id', $game->id)
            ->where('year', $year)
            ->first();

        if ($yearlyLeaderboard) {
            $newTotalScore = $yearlyLeaderboard->total_score + $score;
            $newTotalPlays = $yearlyLeaderboard->total_plays + 1;
            $newAverageScore = round($newTotalScore / $newTotalPlays, 2);
            $newBestScore = max($yearlyLeaderboard->best_score, $score);

            $yearlyLeaderboard->update([
                'best_score' => $newBestScore,
                'average_score' => $newAverageScore,
                'total_score' => $newTotalScore,
                'total_plays' => $newTotalPlays,
                'last_played_at' => $now
            ]);
        } else {
            YearlyLeaderboard::create([
                'user_id' => $user->id,
                'game_id' => $game->id,
                'year' => $year,
                'best_score' => $score,
                'average_score' => $score,
                'total_score' => $score,
                'total_plays' => 1,
                'first_played_at' => $now,
                'last_played_at' => $now
            ]);
        }
    }

    /**
     * Update monthly statistics
     */
    private function updateMonthlyStats(Game $game, string $monthKey): void
    {
        $startOfMonth = Carbon::createFromFormat('Ym', $monthKey)->startOfMonth();
        $endOfMonth = Carbon::createFromFormat('Ym', $monthKey)->endOfMonth();

        $stats = Leaderboard::monthly($monthKey)
            ->where('game_id', $game->id)
            ->selectRaw('
                COUNT(*) as total_players,
                SUM(total_plays) as total_plays,
                AVG(average_score) as average_score,
                MAX(best_score) as highest_score,
                MIN(best_score) as lowest_score
            ')
            ->first();

        GamePeriodStats::updateOrCreate(
            [
                'game_id' => $game->id,
                'period_type' => 'monthly',
                'period_key' => $monthKey
            ],
            [
                'total_players' => $stats->total_players ?? 0,
                'total_plays' => $stats->total_plays ?? 0,
                'average_score' => round($stats->average_score ?? 0, 2),
                'highest_score' => $stats->highest_score ?? 0,
                'lowest_score' => $stats->lowest_score ?? 0,
                'period_start' => $startOfMonth,
                'period_end' => $endOfMonth
            ]
        );
    }

    /**
     * Update yearly statistics
     */
    private function updateYearlyStats(Game $game, string $yearKey): void
    {
        $startOfYear = Carbon::createFromFormat('Y', $yearKey)->startOfYear();
        $endOfYear = Carbon::createFromFormat('Y', $yearKey)->endOfYear();

        $stats = YearlyLeaderboard::where('game_id', $game->id)
            ->where('year', (int)$yearKey)
            ->selectRaw('
                COUNT(*) as total_players,
                SUM(total_plays) as total_plays,
                AVG(average_score) as average_score,
                MAX(best_score) as highest_score,
                MIN(best_score) as lowest_score
            ')
            ->first();

        GamePeriodStats::updateOrCreate(
            [
                'game_id' => $game->id,
                'period_type' => 'yearly',
                'period_key' => $yearKey
            ],
            [
                'total_players' => $stats->total_players ?? 0,
                'total_plays' => $stats->total_plays ?? 0,
                'average_score' => round($stats->average_score ?? 0, 2),
                'highest_score' => $stats->highest_score ?? 0,
                'lowest_score' => $stats->lowest_score ?? 0,
                'period_start' => $startOfYear,
                'period_end' => $endOfYear
            ]
        );
    }

    /**
     * Get monthly leaderboard (from dynamic table or main table)
     */
    public function getMonthlyLeaderboard(Game $game, string $monthKey, int $limit = 100): array
    {
        $tableName = "leaderboards_{$monthKey}";
        
        if (Schema::hasTable($tableName)) {
            // Use dynamic table for better performance
            return DB::table($tableName)
                ->join('users', 'users.id', '=', "{$tableName}.user_id")
                ->where("{$tableName}.game_id", $game->id)
                ->select([
                    "{$tableName}.*",
                    'users.name as user_name',
                    'users.level as user_level'
                ])
                ->orderBy('average_score', 'desc')
                ->limit($limit)
                ->get()
                ->map(function ($entry, $index) {
                    return [
                        'rank' => $index + 1,
                        'user' => [
                            'id' => $entry->user_id,
                            'name' => $entry->user_name,
                            'level' => $entry->user_level
                        ],
                        'best_score' => $entry->best_score,
                        'average_score' => (float)$entry->average_score,
                        'total_score' => $entry->total_score,
                        'total_plays' => $entry->total_plays,
                        'first_played_at' => $entry->first_played_at,
                        'last_played_at' => $entry->last_played_at
                    ];
                })
                ->toArray();
        }

        // Fallback to main leaderboards table
        return Leaderboard::monthly($monthKey)
            ->where('game_id', $game->id)
            ->with('user')
            ->orderBy('average_score', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($entry, $index) {
                return [
                    'rank' => $index + 1,
                    'user' => [
                        'id' => $entry->user->id,
                        'name' => $entry->user->name,
                        'level' => $entry->user->level
                    ],
                    'best_score' => $entry->best_score,
                    'average_score' => (float)$entry->average_score,
                    'total_score' => $entry->total_score,
                    'total_plays' => $entry->total_plays,
                    'last_played_at' => $entry->last_played_at
                ];
            })
            ->toArray();
    }

    /**
     * Get yearly leaderboard
     */
    public function getYearlyLeaderboard(Game $game, int $year, int $limit = 100): array
    {
        return YearlyLeaderboard::where('game_id', $game->id)
            ->where('year', $year)
            ->with('user')
            ->orderBy('average_score', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($entry, $index) {
                return [
                    'rank' => $index + 1,
                    'user' => [
                        'id' => $entry->user->id,
                        'name' => $entry->user->name,
                        'level' => $entry->user->level
                    ],
                    'best_score' => $entry->best_score,
                    'average_score' => (float)$entry->average_score,
                    'total_score' => $entry->total_score,
                    'total_plays' => $entry->total_plays,
                    'first_played_at' => $entry->first_played_at,
                    'last_played_at' => $entry->last_played_at
                ];
            })
            ->toArray();
    }

    /**
     * Get available monthly leaderboards
     */
    public function getAvailableMonths(?Game $game = null): array
    {
        $query = GamePeriodStats::where('period_type', 'monthly');
        
        if ($game) {
            $query->where('game_id', $game->id);
        }

        return $query->orderBy('period_key', 'desc')
            ->get()
            ->map(function ($stat) {
                $date = Carbon::createFromFormat('Ym', $stat->period_key);
                return [
                    'key' => $stat->period_key,
                    'label' => $date->format('F Y'),
                    'month' => $date->month,
                    'year' => $date->year,
                    'total_players' => $stat->total_players,
                    'total_plays' => $stat->total_plays
                ];
            })
            ->toArray();
    }

    /**
     * Get available yearly leaderboards
     */
    public function getAvailableYears(?Game $game = null): array
    {
        $query = GamePeriodStats::where('period_type', 'yearly');
        
        if ($game) {
            $query->where('game_id', $game->id);
        }

        return $query->orderBy('period_key', 'desc')
            ->get()
            ->map(function ($stat) {
                return [
                    'key' => $stat->period_key,
                    'label' => $stat->period_key,
                    'year' => (int)$stat->period_key,
                    'total_players' => $stat->total_players,
                    'total_plays' => $stat->total_plays
                ];
            })
            ->toArray();
    }

    /**
     * Get the current global top user (highest total score across all games)
     */
    private function getGlobalTopUser(): ?User
    {
        $topUserData = Leaderboard::current()
            ->selectRaw('user_id, SUM(best_score) as total_score')
            ->groupBy('user_id')
            ->orderByRaw('SUM(best_score) DESC')
            ->first();

        return $topUserData ? User::find($topUserData->user_id) : null;
    }

    /**
     * Get the current top user for a specific game
     */
    private function getGameTopUser(Game $game): ?User
    {
        $topEntry = Leaderboard::current()
            ->where('game_id', $game->id)
            ->orderBy('best_score', 'desc')
            ->first();

        return $topEntry ? $topEntry->user : null;
    }

    /**
     * Invalidate badge caches when rankings change
     */
    private function invalidateBadgeCaches(User $user, Game $game, ?User $previousGlobalTop, ?User $previousGameTop): void
    {
        // Get current top users
        $currentGlobalTop = $this->getGlobalTopUser();
        $currentGameTop = $this->getGameTopUser($game);

        $affectedUsers = collect([$user]);

        // If global top player changed, invalidate both previous and new DemiGod
        if ($previousGlobalTop && $currentGlobalTop && $previousGlobalTop->id !== $currentGlobalTop->id) {
            $affectedUsers->push($previousGlobalTop);
            $affectedUsers->push($currentGlobalTop);
            \Log::info('Global DemiGod changed', [
                'previous' => $previousGlobalTop->name,
                'current' => $currentGlobalTop->name
            ]);
        }

        // If game top player changed, invalidate both previous and new game leader
        if ($previousGameTop && $currentGameTop && $previousGameTop->id !== $currentGameTop->id) {
            $affectedUsers->push($previousGameTop);
            $affectedUsers->push($currentGameTop);
            \Log::info('Game leader changed for ' . $game->title, [
                'previous' => $previousGameTop->name,
                'current' => $currentGameTop->name
            ]);
        }

        // Also invalidate caches for users who might have moved ranks in this game
        $gameAffectedUsers = Leaderboard::current()
            ->where('game_id', $game->id)
            ->orderBy('best_score', 'desc')
            ->limit(25) // Top 25 users who might have badge changes
            ->with('user')
            ->get()
            ->pluck('user');

        $affectedUsers = $affectedUsers->merge($gameAffectedUsers)->unique('id');

        // Clear badge-related caches for affected users
        foreach ($affectedUsers as $affectedUser) {
            if ($affectedUser) {
                \Cache::forget("user_badge_{$affectedUser->id}");
                \Cache::forget("user_badge_{$affectedUser->id}_{$game->slug}");
                \Cache::forget("global_rank_{$affectedUser->id}");
                \Cache::forget("game_rank_{$game->id}_{$affectedUser->id}");
            }
        }

        // Clear general badge caches
        \Cache::forget('badges_all_users');
        \Cache::forget("badges_game_{$game->slug}");
    }

    /**
     * Clear leaderboard-related caches
     */
    private function clearLeaderboardCaches(Game $game): void
    {
        // Clear leaderboard caches
        $patterns = [
            'leaderboards:all:*',
            "leaderboards:game:{$game->id}:*",
            'leaderboards:top_players:*',
            'leaderboards:stats:*',
            'leaderboards:total_players'
        ];

        $cacheStore = \Cache::getStore();
        $isRedisCache = method_exists($cacheStore, 'getRedis');

        foreach ($patterns as $pattern) {
            if (str_contains($pattern, '*')) {
                // Handle wildcard patterns
                $prefix = str_replace('*', '', $pattern);
                
                if ($isRedisCache) {
                    try {
                        $keys = $cacheStore->getRedis()->keys($prefix . '*');
                        if (!empty($keys)) {
                            $cacheStore->getRedis()->del($keys);
                        }
                    } catch (\Exception $e) {
                        // Fallback to individual cache clearing
                        \Log::warning('Failed to clear Redis cache pattern: ' . $pattern, ['error' => $e->getMessage()]);
                        $this->clearCachePatternFallback($prefix);
                    }
                } else {
                    // For non-Redis cache stores, use fallback method
                    $this->clearCachePatternFallback($prefix);
                }
            } else {
                \Cache::forget($pattern);
            }
        }
    }

    /**
     * Fallback method to clear cache patterns for non-Redis stores
     */
    private function clearCachePatternFallback(string $prefix): void
    {
        // For database/file cache stores, clear known cache keys manually
        $commonSuffixes = [
            'current', 'monthly', 'yearly', 'all_time',
            'top_10', 'top_25', 'top_50', 'top_100',
            'stats', 'count', 'summary'
        ];

        foreach ($commonSuffixes as $suffix) {
            \Cache::forget($prefix . $suffix);
        }

        // Also try some common variations
        for ($i = 1; $i <= 12; $i++) {
            \Cache::forget($prefix . str_pad($i, 2, '0', STR_PAD_LEFT));
        }

        // Clear year variations (last 5 years)
        $currentYear = now()->year;
        for ($year = $currentYear - 4; $year <= $currentYear + 1; $year++) {
            \Cache::forget($prefix . $year);
        }
    }
} 
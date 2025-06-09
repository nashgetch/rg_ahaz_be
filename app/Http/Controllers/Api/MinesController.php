<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\Round;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class MinesController extends Controller
{
    private const GRID_SIZE = 5;
    private const TOTAL_TILES = 25;
    private const BOMB_COUNT = 3;
    private const BASE_MULTIPLIER = 1.2;
    private const MULTIPLIER_GROWTH = 1.2;
    private const FLAG_COST = 0.5;

    /**
     * Start a new mines game
     */
    public function start(Request $request): JsonResponse
    {
        $user = $request->user();
        $game = Game::where('slug', 'mines')->firstOrFail();

        // Check if user has enough tokens
        if (!$user->canAfford($game->token_cost)) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient tokens',
                'required' => $game->token_cost,
                'available' => $user->tokens_balance
            ], 422);
        }

        // Check for active round and get daily attempts count in one query
        $activeRound = $user->rounds()
            ->where('game_id', $game->id)
            ->whereNull('completed_at')
            ->first();

        if ($activeRound) {
            return $this->getGameState($activeRound);
        }

        // Check daily limit - use more efficient date query
        $todayAttempts = $user->rounds()
            ->where('game_id', $game->id)
            ->where('created_at', '>=', today())
            ->whereNotNull('completed_at')
            ->count();

        $maxAttemptsPerDay = $game->getConfigValue('max_attempts_per_day', 15);
        if ($todayAttempts >= $maxAttemptsPerDay) {
            return response()->json([
                'success' => false,
                'message' => 'Daily attempt limit reached for this game'
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Deduct tokens
            try {
                $transaction = $user->spendTokens($game->token_cost, "Started Mines game", [
                    'game_id' => $game->id,
                    'action' => 'start_game'
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to spend tokens in Mines start', ['user_id' => $user->id, 'error' => $e->getMessage()]);
                throw $e;
            }

            // Generate provably fair seed and board
            $seed = $this->generateSeed();
            $bombPositions = $this->generateBombPositions($seed);
            $boardHash = $this->generateBoardHash($seed, $bombPositions);

            // Create game data structure
            $gameData = [
                'bomb_positions' => $bombPositions,
                'board_hash' => $boardHash,
                'revealed_tiles' => [],
                'safe_tiles_count' => 0,
                'current_multiplier' => 0,
                'flag_used' => false,
                'flagged_tile' => null,
                'move_history' => []
            ];

            // Create new round
            try {
                $round = Round::create([
                    'user_id' => $user->id,
                    'game_id' => $game->id,
                    'seed' => $seed,
                    'cost_tokens' => $game->token_cost,
                    'duration_ms' => 0,
                    'score_hash' => '',
                    'status' => 'started',
                    'game_data' => $gameData,
                    'started_at' => now()
                ]);
                
            } catch (\Exception $e) {
                Log::error('Failed to create Mines round', ['user_id' => $user->id, 'error' => $e->getMessage()]);
                throw $e;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Mines game started successfully',
                'data' => [
                    'round_id' => $round->id,
                    'board_hash' => $boardHash,
                    'seed' => $seed, // Will be revealed after game ends
                    'grid_size' => self::GRID_SIZE,
                    'bomb_count' => self::BOMB_COUNT,
                    'flag_available' => true,
                    'flag_cost' => self::FLAG_COST,
                    'user_tokens' => $user->fresh()->tokens_balance
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to start mines game', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to start game'
            ], 500);
        }
    }

    /**
     * Reveal a tile
     */
    public function reveal(Request $request, Round $round): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'row' => 'required|integer|min:0|max:4',
            'col' => 'required|integer|min:0|max:4'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid tile position',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();

        // Verify round ownership and status
        if ($round->user_id !== $user->id || $round->completed_at) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid round'
            ], 422);
        }

        $row = $request->row;
        $col = $request->col;
        $position = $row * self::GRID_SIZE + $col;

        $gameData = $round->game_data;
        
        // Ensure game data exists and has required keys
        if (!$gameData || !isset($gameData['bomb_positions'])) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid game state. Please start a new game.'
            ], 422);
        }
        
        $revealedTiles = $gameData['revealed_tiles'] ?? [];

        // Check if tile already revealed
        if (in_array($position, $revealedTiles)) {
            return response()->json([
                'success' => false,
                'message' => 'Tile already revealed'
            ], 422);
        }

        // Rate limiting - 500ms debounce
        $lastMove = Cache::get("mines_last_move_{$user->id}");
        if ($lastMove && (microtime(true) - $lastMove) < 0.5) {
            return response()->json([
                'success' => false,
                'message' => 'Please wait before making another move'
            ], 429);
        }

        Cache::put("mines_last_move_{$user->id}", microtime(true), 60);

        $bombPositions = $gameData['bomb_positions'];
        $isBomb = in_array($position, $bombPositions);

        // Add to move history for anti-cheat
        $moveHistory = $gameData['move_history'] ?? [];
        $moveHistory[] = [
            'position' => $position,
            'timestamp' => microtime(true),
            'is_bomb' => $isBomb
        ];

        if ($isBomb) {
            // Game over - hit bomb
            $round->update([
                'completed_at' => now(),
                'score' => 0,
                'duration_ms' => $round->created_at->diffInMilliseconds(now()),
                'game_data' => array_merge($gameData, [
                    'revealed_tiles' => array_merge($revealedTiles, [$position]),
                    'move_history' => $moveHistory,
                    'game_result' => 'bust',
                    'final_multiplier' => 0
                ])
            ]);

            // Update leaderboards for streak tracking
            $this->updateLeaderboards($user, $round->game, 0, ['type' => 'bust']);

            return response()->json([
                'success' => true,
                'data' => [
                    'safe' => false,
                    'game_over' => true,
                    'position' => $position,
                    'score' => 0,
                    'experience_gained' => 0,
                    'bomb_positions' => $bombPositions,
                    'seed' => $round->seed,
                    'user_tokens' => $user->fresh()->tokens_balance
                ]
            ]);
        }

        // Safe tile revealed
        $revealedTiles[] = $position;
        $safeTilesCount = count($revealedTiles);
        $currentMultiplier = $this->calculateMultiplier($safeTilesCount);
        $currentScore = (int) round($currentMultiplier * 100);

        $round->update([
            'game_data' => array_merge($gameData, [
                'revealed_tiles' => $revealedTiles,
                'safe_tiles_count' => $safeTilesCount,
                'current_multiplier' => $currentMultiplier,
                'move_history' => $moveHistory
            ])
        ]);

        // Check if all safe tiles revealed (perfect game)
        $maxSafeTiles = self::TOTAL_TILES - self::BOMB_COUNT;
        $isPerfectGame = $safeTilesCount === $maxSafeTiles;

        return response()->json([
            'success' => true,
            'data' => [
                'safe' => true,
                'position' => $position,
                'tiles_revealed' => $safeTilesCount,
                'multiplier' => $currentMultiplier,
                'score' => $currentScore,
                'max_safe_tiles' => $maxSafeTiles,
                'perfect_game' => $isPerfectGame,
                'can_cash_out' => true
            ]
        ]);
    }

    /**
     * Cash out current winnings
     */
    public function cashOut(Request $request, Round $round): JsonResponse
    {
        $user = $request->user();

        // Verify round ownership and status
        if ($round->user_id !== $user->id || $round->completed_at) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid round'
            ], 422);
        }

        $gameData = $round->game_data;
        
        // Ensure game data exists
        if (!$gameData) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid game state. Please start a new game.'
            ], 422);
        }
        
        $safeTilesCount = $gameData['safe_tiles_count'] ?? 0;

        if ($safeTilesCount === 0) {
            return response()->json([
                'success' => false,
                'message' => 'No tiles revealed to cash out'
            ], 422);
        }

        $multiplier = $this->calculateMultiplier($safeTilesCount);
        $score = (int) round($multiplier * 100);
        $tokensEarned = $this->calculateTokenReward($score);

        DB::beginTransaction();
        try {
            // Complete the round
            $round->update([
                'completed_at' => now(),
                'score' => $score,
                'duration_ms' => $round->created_at->diffInMilliseconds(now()),
                'reward_tokens' => $tokensEarned,
                'game_data' => array_merge($gameData, [
                    'game_result' => 'cash_out',
                    'final_multiplier' => $multiplier,
                    'final_score' => $score
                ])
            ]);

            // Award tokens
            if ($tokensEarned > 0) {
                $user->awardTokens($tokensEarned, 'prize', "Mines cash out - {$safeTilesCount} tiles", [
                    'game_id' => $round->game_id,
                    'round_id' => $round->id,
                    'tiles_revealed' => $safeTilesCount,
                    'multiplier' => $multiplier
                ]);
            }

            // Award experience
            $experienceGained = $this->calculateExperience($safeTilesCount);
            if ($experienceGained > 0) {
                $user->addExperience($experienceGained);
                $round->update(['experience_gained' => $experienceGained]);
            }

            // Update leaderboards
            $this->updateLeaderboards($user, $round->game, $score, [
                'type' => 'cash_out',
                'tiles_revealed' => $safeTilesCount,
                'multiplier' => $multiplier
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Successfully cashed out!',
                'data' => [
                    'score' => $score,
                    'tokens_earned' => $tokensEarned,
                    'experience_gained' => $experienceGained,
                    'tiles_revealed' => $safeTilesCount,
                    'multiplier' => $multiplier,
                    'user_tokens' => $user->fresh()->tokens_balance,
                    'user_level' => $user->fresh()->level,
                    'seed' => $round->seed,
                    'bomb_positions' => $gameData['bomb_positions'] ?? []
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to cash out mines game', [
                'user_id' => $user->id,
                'round_id' => $round->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to cash out'
            ], 500);
        }
    }

    /**
     * Use flag power-up to reveal a safe tile
     */
    public function useFlag(Request $request, Round $round): JsonResponse
    {
        $user = $request->user();

        // Verify round ownership and status
        if ($round->user_id !== $user->id || $round->completed_at) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid round'
            ], 422);
        }

        $gameData = $round->game_data;
        
        // Ensure game data exists and has required keys
        if (!$gameData || !isset($gameData['bomb_positions'])) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid game state. Please start a new game.'
            ], 422);
        }

        // Check if flag already used
        if ($gameData['flag_used'] ?? false) {
            return response()->json([
                'success' => false,
                'message' => 'Flag already used this round'
            ], 422);
        }

        // Check if user has enough tokens for flag
        if (!$user->canAfford(self::FLAG_COST)) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient tokens for flag',
                'required' => self::FLAG_COST,
                'available' => $user->tokens_balance
            ], 422);
        }

        $bombPositions = $gameData['bomb_positions'];
        $revealedTiles = $gameData['revealed_tiles'] ?? [];

        // Find a safe tile that hasn't been revealed
        $safeTiles = [];
        for ($i = 0; $i < self::TOTAL_TILES; $i++) {
            if (!in_array($i, $bombPositions) && !in_array($i, $revealedTiles)) {
                $safeTiles[] = $i;
            }
        }

        if (empty($safeTiles)) {
            return response()->json([
                'success' => false,
                'message' => 'No safe tiles available to flag'
            ], 422);
        }

        // Randomly select a safe tile
        $flaggedPosition = $safeTiles[array_rand($safeTiles)];

        DB::beginTransaction();
        try {
            // Deduct flag cost
            $user->spendTokens(self::FLAG_COST, "Used flag in Mines game", [
                'game_id' => $round->game_id,
                'round_id' => $round->id,
                'action' => 'use_flag'
            ]);

            // Update round data
            $round->update([
                'game_data' => array_merge($gameData, [
                    'flag_used' => true,
                    'flagged_tile' => $flaggedPosition
                ])
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Flag used successfully!',
                'data' => [
                    'flagged_position' => $flaggedPosition,
                    'row' => (int) floor($flaggedPosition / self::GRID_SIZE),
                    'col' => $flaggedPosition % self::GRID_SIZE,
                    'user_tokens' => $user->fresh()->tokens_balance
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to use flag in mines game', [
                'user_id' => $user->id,
                'round_id' => $round->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to use flag'
            ], 500);
        }
    }

    /**
     * Get game history for fairness verification
     */
    public function history(Request $request): JsonResponse
    {
        $user = $request->user();
        $limit = min($request->get('limit', 50), 100);

        $rounds = $user->rounds()
            ->with('game')
            ->where('game_id', Game::where('slug', 'mines')->first()->id)
            ->whereNotNull('completed_at')
            ->orderBy('completed_at', 'desc')
            ->limit($limit)
            ->get();

        $history = $rounds->map(function ($round) {
            $gameData = $round->game_data;
            return [
                'round_id' => $round->id,
                'date' => $round->completed_at->format('Y-m-d H:i:s'),
                'score' => $round->score,
                'result' => $gameData['game_result'] ?? 'unknown',
                'tiles_revealed' => $gameData['safe_tiles_count'] ?? 0,
                'multiplier' => $gameData['final_multiplier'] ?? 0,
                'seed' => $round->seed,
                'bomb_positions' => $gameData['bomb_positions'] ?? [],
                'revealed_tiles' => $gameData['revealed_tiles'] ?? [],
                'duration_ms' => $round->duration_ms,
                'tokens_earned' => $round->reward_tokens ?? 0
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $history
        ]);
    }

    /**
     * Get current game state for active round
     */
    private function getGameState(Round $round): JsonResponse
    {
        $gameData = $round->game_data;
        
        // Check if game data is valid
        if (!$gameData || !isset($gameData['board_hash']) || !isset($gameData['bomb_positions'])) {
            Log::warning('Invalid Mines game data for active round', ['round_id' => $round->id]);
            
            return response()->json([
                'success' => false,
                'message' => 'Invalid game state. Please start a new game.'
            ], 422);
        }
        
        $safeTilesCount = $gameData['safe_tiles_count'] ?? 0;
        $currentMultiplier = $safeTilesCount > 0 ? $this->calculateMultiplier($safeTilesCount) : 0;
        $currentScore = $safeTilesCount > 0 ? (int) round($currentMultiplier * 100) : 0;

        return response()->json([
            'success' => true,
            'message' => 'Resuming active game',
            'data' => [
                'round_id' => $round->id,
                'board_hash' => $gameData['board_hash'],
                'grid_size' => self::GRID_SIZE,
                'bomb_count' => self::BOMB_COUNT,
                'revealed_tiles' => $gameData['revealed_tiles'] ?? [],
                'tiles_revealed' => $safeTilesCount,
                'multiplier' => $currentMultiplier,
                'score' => $currentScore,
                'flag_available' => !($gameData['flag_used'] ?? false),
                'flag_cost' => self::FLAG_COST,
                'flagged_tile' => $gameData['flagged_tile'] ?? null
            ]
        ]);
    }

    /**
     * Generate provably fair seed
     */
    private function generateSeed(): string
    {
        return hash('sha256', microtime() . random_int(100000, 999999) . auth()->id());
    }

    /**
     * Generate bomb positions from seed
     */
    private function generateBombPositions(string $seed): array
    {
        $positions = [];
        $hash = $seed;
        $attemptCounter = 0;
        $maxAttempts = 1000; // Prevent infinite loops
        
        for ($i = 0; $i < self::BOMB_COUNT; $i++) {
            $attempts = 0;
            $position = null;
            
            do {
                $hash = hash('sha256', $hash . $i . $attempts);
                $hexChunk = substr($hash, 0, 8); // Use first 8 chars consistently
                $position = hexdec($hexChunk) % self::TOTAL_TILES;
                $attempts++;
                $attemptCounter++;
                
                // Fallback to prevent infinite loops
                if ($attemptCounter > $maxAttempts) {
                    // Fill remaining positions with first available slots
                    $availablePositions = array_diff(range(0, self::TOTAL_TILES - 1), $positions);
                    $positions = array_merge($positions, array_slice($availablePositions, 0, self::BOMB_COUNT - count($positions)));
                    break 2;
                }
            } while (in_array($position, $positions));
            
            if ($position !== null && !in_array($position, $positions)) {
                $positions[] = $position;
            }
        }
        
        sort($positions);
        return $positions;
    }

    /**
     * Generate board hash for verification
     */
    private function generateBoardHash(string $seed, array $bombPositions): string
    {
        return hash('sha256', $seed . implode(',', $bombPositions));
    }

    /**
     * Calculate multiplier based on safe tiles revealed
     */
    private function calculateMultiplier(int $safeTiles): float
    {
        if ($safeTiles === 0) return 0;
        return round(pow(self::MULTIPLIER_GROWTH, $safeTiles), 2);
    }

    /**
     * Calculate token reward based on score
     */
    private function calculateTokenReward(int $score): int
    {
        $game = Game::where('slug', 'mines')->first();
        return min($score / 100, $game->max_score_reward);
    }

    /**
     * Calculate experience based on tiles revealed
     */
    private function calculateExperience(int $safeTiles): int
    {
        return $safeTiles * 5; // 5 XP per safe tile revealed
    }

    /**
     * Update leaderboards for mines game
     */
    private function updateLeaderboards(User $user, Game $game, int $score, array $metadata): void
    {
        try {
            $leaderboardService = app(\App\Services\HistoricalLeaderboardService::class);
            $leaderboardService->updateLeaderboards($user, $game, $score, $metadata);
        } catch (\Exception $e) {
            Log::error('Failed to update mines leaderboards', [
                'user_id' => $user->id,
                'game_id' => $game->id,
                'score' => $score,
                'metadata' => $metadata,
                'error' => $e->getMessage()
            ]);
        }
    }
}
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

class SumChaserController extends Controller
{
    private const TOTAL_DIGITS = 3;
    private const INITIAL_THRESHOLD = 13.5;
    private const BASE_MULTIPLIER = 1.0;
    private const MULTIPLIER_GROWTH = 0.3;

    /**
     * Start a new Sum Chaser game
     */
    public function start(Request $request): JsonResponse
    {
        $user = $request->user();
        $game = Game::where('slug', 'sum-chaser')->firstOrFail();

        // Check if user has enough tokens
        if (!$user->canAfford($game->token_cost)) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient tokens',
                'required' => $game->token_cost,
                'available' => $user->tokens_balance
            ], 422);
        }

        // Check for active round
        $activeRound = $user->rounds()
            ->where('game_id', $game->id)
            ->whereNull('completed_at')
            ->first();

        if ($activeRound) {
            return $this->getGameState($activeRound);
        }

        // Check daily limit
        $todayAttempts = $user->rounds()
            ->where('game_id', $game->id)
            ->where('created_at', '>=', today())
            ->whereNotNull('completed_at')
            ->count();

        $maxAttemptsPerDay = $game->getConfigValue('max_attempts_per_day', 20);
        if ($todayAttempts >= $maxAttemptsPerDay) {
            return response()->json([
                'success' => false,
                'message' => 'Daily attempt limit reached for this game'
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Deduct tokens
            $user->spendTokens($game->token_cost, "Started Sum Chaser game", [
                'game_id' => $game->id,
                'action' => 'start_game'
            ]);

            // Generate provably fair seed and digits
            $seed = $this->generateSeed();
            $digits = $this->generateDigits($seed);
            $gameHash = $this->generateGameHash($seed, $digits);

            // Create game data structure
            $gameData = [
                'digits' => $digits,
                'game_hash' => $gameHash,
                'current_step' => 0,
                'current_sum' => 0,
                'current_threshold' => self::INITIAL_THRESHOLD,
                'current_multiplier' => self::BASE_MULTIPLIER,
                'predictions' => [],
                'revealed_digits' => [],
                'correct_predictions' => 0,
                'game_status' => 'active'
            ];

            // Create new round
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

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Sum Chaser game started successfully',
                'data' => [
                    'round_id' => $round->id,
                    'game_hash' => $gameHash,
                    'seed' => $seed,
                    'current_step' => 0,
                    'current_sum' => 0,
                    'current_threshold' => self::INITIAL_THRESHOLD,
                    'current_multiplier' => self::BASE_MULTIPLIER,
                    'total_digits' => self::TOTAL_DIGITS,
                    'user_tokens' => $user->fresh()->tokens_balance
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to start Sum Chaser game', [
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
     * Make a prediction (Over or Under)
     */
    public function predict(Request $request, Round $round): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'prediction' => 'required|in:over,under'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid prediction. Must be "over" or "under"',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();
        $prediction = $request->input('prediction');

        // Verify round ownership and status
        if ($round->user_id !== $user->id || $round->completed_at) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid round'
            ], 422);
        }

        $gameData = $round->game_data;

        // Ensure game data exists and game is active
        if (!$gameData || $gameData['game_status'] !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Game is not active'
            ], 422);
        }

        $currentStep = $gameData['current_step'];
        
        // Check if we can make a prediction at this step
        if ($currentStep >= self::TOTAL_DIGITS) {
            return response()->json([
                'success' => false,
                'message' => 'All digits have been revealed'
            ], 422);
        }

        // Add prediction to the game data
        $gameData['predictions'][] = [
            'step' => $currentStep,
            'prediction' => $prediction,
            'threshold' => $gameData['current_threshold']
        ];

        // Reveal the next digit
        $nextDigit = $gameData['digits'][$currentStep];
        $gameData['revealed_digits'][] = $nextDigit;
        $gameData['current_sum'] += $nextDigit;

        // Check if prediction was correct
        $predictedSum = $gameData['current_sum'];
        $threshold = $gameData['current_threshold'];
        
        $isCorrect = false;
        if ($prediction === 'over' && $predictedSum > $threshold) {
            $isCorrect = true;
        } elseif ($prediction === 'under' && $predictedSum < $threshold) {
            $isCorrect = true;
        }

        if ($isCorrect) {
            // Correct prediction - increase multiplier and adjust threshold
            $gameData['correct_predictions']++;
            $gameData['current_multiplier'] += self::MULTIPLIER_GROWTH;
            
            // Adjust threshold towards current sum (makes it more challenging)
            $adjustment = ($gameData['current_sum'] - $threshold) * 0.1;
            $gameData['current_threshold'] += $adjustment;
            
            $gameData['current_step']++;
            
            // Mark as complete if all digits revealed, but don't return early
            if ($gameData['current_step'] >= self::TOTAL_DIGITS) {
                $gameData['game_status'] = 'won';
            }
        }

        // Update round with new game data
        $round->update(['game_data' => $gameData]);

        // Return prediction result (both correct and incorrect)
        $response = [
            'success' => true,
            'message' => 'Prediction recorded successfully',
            'data' => [
                'revealed_digit' => $nextDigit,
                'current_sum' => $gameData['current_sum'],
                'current_threshold' => round($gameData['current_threshold'], 2),
                'current_multiplier' => round($gameData['current_multiplier'], 2),
                'current_step' => $gameData['current_step'],
                'prediction_correct' => $isCorrect,
                'game_complete' => !$isCorrect || $gameData['current_step'] >= self::TOTAL_DIGITS,
                'can_cash_out' => $gameData['correct_predictions'] > 0
            ]
        ];

        // Handle game completion for both win and loss scenarios
        if (!$isCorrect || $gameData['current_step'] >= self::TOTAL_DIGITS) {
            $isWin = $isCorrect && $gameData['current_step'] >= self::TOTAL_DIGITS;
            $score = $isWin ? (int) round($gameData['current_multiplier'] * 100) : 0;
            $tokensEarned = $isWin ? $this->calculateTokenReward($score) : 0;
            $experienceGained = $this->calculateExperience($gameData['correct_predictions']);

            // Complete the round
            $round->update([
                'completed_at' => now(),
                'score' => $score,
                'duration_ms' => $round->created_at->diffInMilliseconds(now()),
                'reward_tokens' => $tokensEarned,
                'experience_gained' => $experienceGained,
                'game_data' => array_merge($gameData, [
                    'game_status' => $isWin ? 'won' : 'lost',
                    'final_multiplier' => $gameData['current_multiplier'],
                    'final_score' => $score
                ])
            ]);

            // Award tokens for wins
            if ($tokensEarned > 0) {
                $user->awardTokens($tokensEarned, 'prize', "Sum Chaser - {$gameData['correct_predictions']} correct predictions", [
                    'game_id' => $round->game_id,
                    'round_id' => $round->id,
                    'correct_predictions' => $gameData['correct_predictions'],
                    'multiplier' => $gameData['current_multiplier']
                ]);
            }

            // Award experience
            if ($experienceGained > 0) {
                $user->addExperience($experienceGained);
            }

            // Update leaderboards
            $this->updateLeaderboards($user, $round->game, $score, [
                'type' => $isWin ? 'win' : 'loss',
                'correct_predictions' => $gameData['correct_predictions'],
                'multiplier' => $gameData['current_multiplier']
            ]);
        }

        return response()->json($response);
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

        if (!$gameData || $gameData['correct_predictions'] === 0) {
            return response()->json([
                'success' => false,
                'message' => 'No predictions made to cash out'
            ], 422);
        }

        return $this->completeGame($round, $gameData, true);
    }

    /**
     * Complete the game (win or loss)
     */
    private function completeGame(Round $round, array $gameData, bool $isWin): JsonResponse
    {
        $user = $round->user;
        $correctPredictions = $gameData['correct_predictions'];
        $multiplier = $gameData['current_multiplier'];
        
        // Calculate score and rewards
        $score = $isWin ? (int) round($multiplier * 100) : 0;
        $tokensEarned = $isWin ? $this->calculateTokenReward($score) : 0;
        $experienceGained = $this->calculateExperience($correctPredictions);

        DB::beginTransaction();
        try {
            // Complete the round
            $round->update([
                'completed_at' => now(),
                'score' => $score,
                'duration_ms' => $round->created_at->diffInMilliseconds(now()),
                'reward_tokens' => $tokensEarned,
                'experience_gained' => $experienceGained,
                'game_data' => array_merge($gameData, [
                    'game_status' => $isWin ? 'won' : 'lost',
                    'final_multiplier' => $multiplier,
                    'final_score' => $score
                ])
            ]);

            // Award tokens
            if ($tokensEarned > 0) {
                $user->awardTokens($tokensEarned, 'prize', "Sum Chaser - {$correctPredictions} correct predictions", [
                    'game_id' => $round->game_id,
                    'round_id' => $round->id,
                    'correct_predictions' => $correctPredictions,
                    'multiplier' => $multiplier
                ]);
            }

            // Award experience
            if ($experienceGained > 0) {
                $user->addExperience($experienceGained);
            }

            // Update leaderboards
            $this->updateLeaderboards($user, $round->game, $score, [
                'type' => $isWin ? 'win' : 'loss',
                'correct_predictions' => $correctPredictions,
                'multiplier' => $multiplier
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $isWin ? 'Game completed successfully!' : 'Game over!',
                'data' => [
                    'game_result' => $isWin ? 'win' : 'loss',
                    'score' => $score,
                    'tokens_earned' => $tokensEarned,
                    'experience_gained' => $experienceGained,
                    'correct_predictions' => $correctPredictions,
                    'final_multiplier' => round($multiplier, 2),
                    'final_sum' => $gameData['current_sum'],
                    'all_digits' => $gameData['digits'],
                    'user_tokens' => $user->fresh()->tokens_balance,
                    'user_level' => $user->fresh()->level,
                    'seed' => $round->seed
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to complete Sum Chaser game', [
                'user_id' => $user->id,
                'round_id' => $round->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to complete game'
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
            ->where('game_id', Game::where('slug', 'sum-chaser')->first()->id)
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
                'result' => $gameData['game_status'] ?? 'unknown',
                'correct_predictions' => $gameData['correct_predictions'] ?? 0,
                'final_multiplier' => $gameData['final_multiplier'] ?? 0,
                'final_sum' => $gameData['current_sum'] ?? 0,
                'digits' => $gameData['digits'] ?? [],
                'predictions' => $gameData['predictions'] ?? [],
                'seed' => $round->seed,
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
        
        if (!$gameData || !isset($gameData['game_hash'])) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid game state. Please start a new game.'
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Resuming active game',
            'data' => [
                'round_id' => $round->id,
                'game_hash' => $gameData['game_hash'],
                'current_step' => $gameData['current_step'],
                'current_sum' => $gameData['current_sum'],
                'current_threshold' => round($gameData['current_threshold'], 2),
                'current_multiplier' => round($gameData['current_multiplier'], 2),
                'revealed_digits' => $gameData['revealed_digits'] ?? [],
                'correct_predictions' => $gameData['correct_predictions'],
                'total_digits' => self::TOTAL_DIGITS,
                'can_cash_out' => $gameData['correct_predictions'] > 0
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
     * Generate three random digits from seed
     */
    private function generateDigits(string $seed): array
    {
        $digits = [];
        $hash = $seed;
        
        for ($i = 0; $i < self::TOTAL_DIGITS; $i++) {
            $hash = hash('sha256', $hash . $i);
            $digit = hexdec(substr($hash, 0, 8)) % 10;
            $digits[] = $digit;
        }
        
        return $digits;
    }

    /**
     * Generate game hash for verification
     */
    private function generateGameHash(string $seed, array $digits): string
    {
        return hash('sha256', $seed . implode(',', $digits));
    }

    /**
     * Calculate token reward based on score
     */
    private function calculateTokenReward(int $score): int
    {
        $game = Game::where('slug', 'sum-chaser')->first();
        return min($score / 50, $game->max_score_reward ?? 10);
    }

    /**
     * Calculate experience based on correct predictions
     */
    private function calculateExperience(int $correctPredictions): int
    {
        return $correctPredictions * 8; // 8 XP per correct prediction
    }

    /**
     * Update leaderboards for Sum Chaser game
     */
    private function updateLeaderboards(User $user, Game $game, int $score, array $metadata): void
    {
        try {
            $leaderboardService = app(\App\Services\HistoricalLeaderboardService::class);
            $leaderboardService->updateLeaderboards($user, $game, $score, $metadata);
        } catch (\Exception $e) {
            Log::error('Failed to update Sum Chaser leaderboards', [
                'user_id' => $user->id,
                'game_id' => $game->id,
                'score' => $score,
                'metadata' => $metadata,
                'error' => $e->getMessage()
            ]);
        }
    }
} 
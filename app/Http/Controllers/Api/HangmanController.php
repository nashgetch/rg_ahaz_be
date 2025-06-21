<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\Round;
use App\Services\Games\HangmanService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class HangmanController extends Controller
{
    protected HangmanService $hangmanService;

    public function __construct(HangmanService $hangmanService)
    {
        $this->hangmanService = $hangmanService;
    }

    /**
     * Start a new Hangman game round
     */
    public function startRound(Request $request): JsonResponse
    {
        $user = $request->user();
        $game = Game::where('slug', 'hangman')->firstOrFail();

        // Check if game is enabled
        if (!$game->enabled) {
            return response()->json([
                'success' => false,
                'message' => 'Hangman is currently unavailable'
            ], 422);
        }

        // Check for existing active round
        $activeRound = $user->rounds()
            ->where('game_id', $game->id)
            ->whereNull('completed_at')
            ->first();

        $timeLimit = $game->getConfigValue('time_limit', 120);

        if ($activeRound) {
            // Check if round has expired
            if ($activeRound->created_at->addSeconds($timeLimit)->isPast()) {
                $activeRound->update([
                    'completed_at' => now(),
                    'score' => 0,
                    'is_timeout' => true
                ]);
            } else {
                // Check if the active round has valid game data
                $gameData = $activeRound->game_data ?? [];
                
                // If game data is corrupted (empty or missing required keys), clean it up
                if (empty($gameData) || !isset($gameData['guessed_letters']) || !isset($gameData['word'])) {
                    Log::warning('Found corrupted active round, cleaning up', [
                        'user_id' => $user->id,
                        'round_id' => $activeRound->id,
                        'game_data' => $gameData
                    ]);
                    
                    // Complete the corrupted round
                    $activeRound->update([
                        'completed_at' => now(),
                        'score' => 0,
                        'is_flagged' => true,
                        'flag_reason' => 'Corrupted game data detected during start'
                    ]);
                    
                    // Continue to create a new round below
                } else {
                    // Return existing valid round data
                    return response()->json([
                        'success' => true,
                        'message' => 'Continuing active round',
                        'data' => [
                            'round_id' => $activeRound->id,
                            'seed' => $activeRound->seed,
                            'time_limit' => $timeLimit,
                            'started_at' => $activeRound->started_at,
                            'time_remaining' => $timeLimit - $activeRound->created_at->diffInSeconds(now()),
                            'user_tokens' => $user->tokens_balance,
                            'game_data' => [
                                'word_length' => $gameData['word_length'],
                                'guessed_letters' => $gameData['guessed_letters'],
                                'correct_letters' => $gameData['correct_letters'],
                                'wrong_letters' => $gameData['wrong_letters'],
                                'wrong_guesses' => $gameData['wrong_guesses'],
                                'max_wrong_guesses' => $gameData['max_wrong_guesses'],
                                'is_complete' => $gameData['is_complete'],
                                'is_won' => $gameData['is_won'],
                                'word_state' => $this->hangmanService->getWordState(
                                    $gameData['word'], 
                                    $gameData['correct_letters']
                                )
                            ]
                        ]
                    ]);
                }
            }
        }

        // Check if user has enough tokens
        if (!$user->canAfford($game->token_cost)) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient tokens',
                'required' => $game->token_cost,
                'available' => $user->tokens_balance
            ], 422);
        }

        // Check daily attempt limit
        $todayAttempts = $user->rounds()
            ->where('game_id', $game->id)
            ->whereDate('created_at', today())
            ->whereNotNull('completed_at')
            ->count();

        $maxAttemptsPerDay = $game->getConfigValue('max_attempts_per_day', 20);
        
        if ($todayAttempts >= $maxAttemptsPerDay) {
            return response()->json([
                'success' => false,
                'message' => 'Daily attempt limit reached for Hangman'
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Deduct tokens
            $user->spendTokens($game->token_cost, "Started game: {$game->title}", [
                'game_id' => $game->id,
                'action' => 'start_hangman_game'
            ]);

            // Generate game seed and initialize game
            $seed = $game->generateSeed();
            $gameData = $this->hangmanService->initializeGame($seed, $game->config);

            // Debug logging
            Log::info('Initializing Hangman game', [
                'user_id' => $user->id,
                'seed' => $seed,
                'game_config' => $game->config,
                'initialized_data' => $gameData
            ]);

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

            // Verify the data was saved correctly
            $savedGameData = $round->fresh()->game_data;
            Log::info('Saved game data verification', [
                'round_id' => $round->id,
                'saved_data' => $savedGameData,
                'data_type' => gettype($savedGameData),
                'is_array' => is_array($savedGameData),
                'has_guessed_letters' => isset($savedGameData['guessed_letters'])
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Hangman round started successfully',
                'data' => [
                    'round_id' => $round->id,
                    'seed' => $seed,
                    'time_limit' => $timeLimit,
                    'started_at' => $round->started_at,
                    'user_tokens' => $user->fresh()->tokens_balance,
                    'game_data' => [
                        'word_length' => $gameData['word_length'],
                        'guessed_letters' => $gameData['guessed_letters'],
                        'correct_letters' => $gameData['correct_letters'],
                        'wrong_letters' => $gameData['wrong_letters'],
                        'wrong_guesses' => $gameData['wrong_guesses'],
                        'max_wrong_guesses' => $gameData['max_wrong_guesses'],
                        'is_complete' => $gameData['is_complete'],
                        'is_won' => $gameData['is_won'],
                        'word_state' => $this->hangmanService->getWordState(
                            $gameData['word'], 
                            $gameData['correct_letters']
                        )
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to start Hangman round', [
                'user_id' => $user->id,
                'game_id' => $game->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to start round'
            ], 500);
        }
    }

    /**
     * Process a letter guess
     */
    public function processGuess(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'round_id' => 'required|exists:rounds,id',
            'letter' => 'required|string|size:1|regex:/^[A-Za-z]$/'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();
        $round = Round::find($request->round_id);
        $game = Game::where('slug', 'hangman')->firstOrFail();

        // Verify round ownership and game
        if ($round->user_id !== $user->id || $round->game_id !== $game->id) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid round'
            ], 422);
        }

        // Check if round is already completed
        if ($round->completed_at) {
            return response()->json([
                'success' => false,
                'message' => 'Round is already completed'
            ], 422);
        }

        // Check time limit
        $timeLimit = $game->getConfigValue('time_limit', 120);
        $elapsedTime = $round->created_at->diffInSeconds(now());
        if ($elapsedTime > $timeLimit) {
            // Handle timeout - update leaderboard and battle history
            DB::beginTransaction();
            try {
                $round->update([
                    'completed_at' => now(),
                    'score' => 0,
                    'completion_time' => $timeLimit,
                    'is_timeout' => true,
                    'reward_tokens' => 0,
                    'experience_gained' => 0
                ]);

                // Update leaderboard with 0 score
                $historicalLeaderboardService = app(\App\Services\HistoricalLeaderboardService::class);
                $historicalLeaderboardService->updateLeaderboards($user, $game, 0);

                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Failed to handle hangman timeout', [
                    'user_id' => $user->id,
                    'round_id' => $round->id,
                    'error' => $e->getMessage()
                ]);
            }

            // Return as completed game to trigger battle history
            return response()->json([
                'success' => true,
                'message' => 'Round completed due to timeout',
                'data' => [
                    'game_complete' => true,
                    'score' => 0,
                    'tokens_earned' => 0,
                    'experience_gained' => 0,
                    'time_elapsed' => $timeLimit,
                    'won' => false,
                    'reason' => 'timeout'
                ]
            ]);
        }

        try {
            $gameData = $round->game_data;
            $letter = strtoupper($request->letter);

            // Debug logging
            Log::info('Processing Hangman guess', [
                'user_id' => $user->id,
                'round_id' => $round->id,
                'letter' => $letter,
                'game_data_keys' => $gameData ? array_keys($gameData) : 'null',
                'game_data' => $gameData
            ]);

            // Ensure game_data has the required structure
            if (!$gameData || !isset($gameData['guessed_letters'])) {
                Log::error('Invalid game data structure', [
                    'user_id' => $user->id,
                    'round_id' => $round->id,
                    'game_data' => $gameData,
                    'data_type' => gettype($gameData),
                    'is_array' => is_array($gameData),
                    'array_keys' => $gameData ? array_keys($gameData) : 'no keys'
                ]);

                // Complete the round with 0 score and update battle history
                $elapsedTime = $round->created_at->diffInSeconds(now());
                $round->update([
                    'completed_at' => now(),
                    'score' => 0,
                    'duration_ms' => $elapsedTime * 1000,
                    'is_flagged' => true,
                    'flag_reason' => 'Invalid game data structure'
                ]);

                // Update battle history and leaderboard with 0 score (like timeout)
                try {
                    $leaderboardService = app(\App\Services\HistoricalLeaderboardService::class);
                    $leaderboardService->updateLeaderboards($user, $game, 0);
                } catch (\Exception $e) {
                    Log::warning('Failed to update leaderboard for invalid game state', [
                        'user_id' => $user->id,
                        'round_id' => $round->id,
                        'error' => $e->getMessage()
                    ]);
                }
                
                // Return as if the game completed with 0 score to trigger battle history
                return response()->json([
                    'success' => true,
                    'message' => 'Round completed due to invalid game state',
                    'data' => [
                        'game_complete' => true,
                        'score' => 0,
                        'tokens_earned' => 0,
                        'experience_gained' => 0,
                        'time_elapsed' => $elapsedTime,
                        'won' => false,
                        'reason' => 'invalid_state'
                    ]
                ]);
            }

            // Process the guess
            $updatedGameData = $this->hangmanService->processGuess($gameData, $letter);

            // Update the round with new game data
            $round->update([
                'game_data' => $updatedGameData
            ]);

            // If game is complete, automatically finish the round
            if ($updatedGameData['is_complete']) {
                $completionTime = $elapsedTime;
                $score = $updatedGameData['is_won'] ? $this->hangmanService->calculateScore($updatedGameData, $completionTime, $game->config) : 0;
                
                // No token rewards - set to 0
                $rewardTokens = 0;
                $experienceGained = $this->calculateExperience($game, $score);

                // Complete the round
                $round->update([
                    'completed_at' => now(),
                    'score' => $score,
                    'completion_time' => $completionTime,
                    'reward_tokens' => $rewardTokens,
                    'experience_gained' => $experienceGained
                ]);

                // Update leaderboard
                try {
                    $historicalLeaderboardService = app(\App\Services\HistoricalLeaderboardService::class);
                    $historicalLeaderboardService->updateLeaderboards($user, $game, $score);
                } catch (\Exception $e) {
                    Log::warning('Failed to update leaderboard for completed hangman game', [
                        'user_id' => $user->id,
                        'round_id' => $round->id,
                        'error' => $e->getMessage()
                    ]);
                }

                // Return completion data
                return response()->json([
                    'success' => true,
                    'message' => 'Game completed',
                    'data' => [
                        'letter' => $letter,
                        'is_correct' => in_array($letter, $updatedGameData['correct_letters']),
                        'game_data' => [
                            'word_length' => $updatedGameData['word_length'],
                            'guessed_letters' => $updatedGameData['guessed_letters'],
                            'correct_letters' => $updatedGameData['correct_letters'],
                            'wrong_letters' => $updatedGameData['wrong_letters'],
                            'wrong_guesses' => $updatedGameData['wrong_guesses'],
                            'max_wrong_guesses' => $updatedGameData['max_wrong_guesses'],
                            'is_complete' => $updatedGameData['is_complete'],
                            'is_won' => $updatedGameData['is_won'],
                            'word_state' => $this->hangmanService->getWordState(
                                $updatedGameData['word'], 
                                $updatedGameData['correct_letters']
                            )
                        ],
                        'time_remaining' => max(0, $timeLimit - $elapsedTime),
                        'final_score' => $score,
                        'tokens_earned' => $rewardTokens,
                        'experience_gained' => $experienceGained,
                        'word' => $updatedGameData['word']
                    ]
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Guess processed successfully',
                'data' => [
                    'letter' => $letter,
                    'is_correct' => in_array($letter, $updatedGameData['correct_letters']),
                    'game_data' => [
                        'word_length' => $updatedGameData['word_length'],
                        'guessed_letters' => $updatedGameData['guessed_letters'],
                        'correct_letters' => $updatedGameData['correct_letters'],
                        'wrong_letters' => $updatedGameData['wrong_letters'],
                        'wrong_guesses' => $updatedGameData['wrong_guesses'],
                        'max_wrong_guesses' => $updatedGameData['max_wrong_guesses'],
                        'is_complete' => $updatedGameData['is_complete'],
                        'is_won' => $updatedGameData['is_won'],
                        'word_state' => $this->hangmanService->getWordState(
                            $updatedGameData['word'], 
                            $updatedGameData['correct_letters']
                        )
                    ],
                    'time_remaining' => max(0, $timeLimit - $elapsedTime)
                ]
            ]);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to process Hangman guess', [
                'user_id' => $user->id,
                'round_id' => $round->id,
                'letter' => $request->letter,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process guess'
            ], 500);
        }
    }

    /**
     * Submit round results
     */
    public function submitRound(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'round_id' => 'required|exists:rounds,id',
            'score' => 'required|integer|min:0',
            'completion_time' => 'required|integer|min:1'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();
        $round = Round::find($request->round_id);
        $game = Game::where('slug', 'hangman')->firstOrFail();

        // Verify round ownership and game
        if ($round->user_id !== $user->id || $round->game_id !== $game->id) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid round'
            ], 422);
        }

        // Check if round is already completed
        if ($round->completed_at) {
            return response()->json([
                'success' => true,
                'message' => 'Round was already completed',
                'data' => [
                    'score' => $round->score,
                    'tokens_earned' => $round->reward_tokens ?? 0,
                    'experience_gained' => $round->experience_gained ?? 0,
                    'user_tokens' => $user->fresh()->tokens_balance,
                    'user_level' => $user->fresh()->level,
                    'user_experience' => $user->fresh()->experience,
                    'is_personal_best' => $this->isPersonalBest($user, $game, $round->score),
                    'already_completed' => true
                ]
            ]);
        }

        // Check time limit
        $timeLimit = $game->getConfigValue('time_limit', 120);
        $elapsedTime = $round->created_at->diffInSeconds(now());
        if ($elapsedTime > $timeLimit) {
            // Handle timeout - update leaderboard and battle history
            DB::beginTransaction();
            try {
                $round->update([
                    'completed_at' => now(),
                    'score' => 0,
                    'completion_time' => $timeLimit,
                    'is_timeout' => true,
                    'reward_tokens' => 0,
                    'experience_gained' => 0
                ]);

                // Update leaderboard with 0 score
                $historicalLeaderboardService = app(\App\Services\HistoricalLeaderboardService::class);
                $historicalLeaderboardService->updateLeaderboards($user, $game, 0);

                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Failed to handle hangman timeout', [
                    'user_id' => $user->id,
                    'round_id' => $round->id,
                    'error' => $e->getMessage()
                ]);
            }

            // Return as completed game to trigger battle history
            return response()->json([
                'success' => true,
                'message' => 'Round completed due to timeout',
                'data' => [
                    'game_complete' => true,
                    'score' => 0,
                    'tokens_earned' => 0,
                    'experience_gained' => 0,
                    'time_elapsed' => $timeLimit,
                    'won' => false,
                    'reason' => 'timeout'
                ]
            ]);
        }

        // Validate the game completion
        $gameData = $round->game_data;
        $validationResult = $this->hangmanService->validateCompletion(
            $gameData, 
            $request->score, 
            $request->completion_time
        );

        if (!$validationResult['valid']) {
            Log::warning('Hangman validation failed', [
                'user_id' => $user->id,
                'round_id' => $round->id,
                'reason' => $validationResult['reason']
            ]);

            $round->update([
                'completed_at' => now(),
                'score' => 0,
                'is_flagged' => true,
                'flag_reason' => 'Hangman: ' . $validationResult['reason']
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid game data detected'
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Calculate rewards
            $rewardTokens = $game->calculateReward($request->score);
            $experienceGained = $this->calculateExperience($game, $request->score);

            // Update round
            $round->update([
                'completed_at' => now(),
                'score' => $request->score,
                'completion_time' => $request->completion_time,
                'reward_tokens' => $rewardTokens,
                'experience_gained' => $experienceGained
            ]);

            // Award tokens and experience
            $user->awardTokens($rewardTokens, 'prize', "Hangman game reward", [
                'game_id' => $game->id,
                'round_id' => $round->id,
                'score' => $request->score
            ]);
            $user->addExperience($experienceGained);

            // Update leaderboard
            $historicalLeaderboardService = app(\App\Services\HistoricalLeaderboardService::class);
            $historicalLeaderboardService->updateLeaderboards($user, $game, $request->score);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Hangman round completed successfully',
                'data' => [
                    'score' => $request->score,
                    'tokens_earned' => $rewardTokens,
                    'experience_gained' => $experienceGained,
                    'user_tokens' => $user->fresh()->tokens_balance,
                    'user_level' => $user->fresh()->level,
                    'user_experience' => $user->fresh()->experience,
                    'is_personal_best' => $this->isPersonalBest($user, $game, $request->score),
                    'word' => $gameData['word'] ?? 'Unknown'
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to submit Hangman round', [
                'user_id' => $user->id,
                'round_id' => $round->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to submit round'
            ], 500);
        }
    }

    /**
     * Get hint for current round
     */
    public function getHint(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'round_id' => 'required|exists:rounds,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();
        $round = Round::find($request->round_id);
        $game = Game::where('slug', 'hangman')->firstOrFail();

        // Verify round ownership and game
        if ($round->user_id !== $user->id || $round->game_id !== $game->id) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid round'
            ], 422);
        }

        // Check if round is already completed
        if ($round->completed_at) {
            return response()->json([
                'success' => false,
                'message' => 'Round is already completed'
            ], 422);
        }

        $gameData = $round->game_data;
        $hint = $this->hangmanService->getHint($gameData);

        if (!$hint) {
            return response()->json([
                'success' => false,
                'message' => 'No hints available'
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'hint_letter' => $hint
            ]
        ]);
    }

    /**
     * Calculate experience gained from score
     */
    private function calculateExperience(Game $game, int $score): int
    {
        // Base experience + score-based bonus
        $baseExp = 10;
        $scoreExp = intval($score / 50); // 1 exp per 50 points
        return $baseExp + $scoreExp;
    }

    /**
     * Check if this is a personal best score
     */
    private function isPersonalBest($user, Game $game, int $score): bool
    {
        $bestScore = $user->rounds()
            ->where('game_id', $game->id)
            ->whereNotNull('completed_at')
            ->max('score');

        return $bestScore === null || $score > $bestScore;
    }
} 
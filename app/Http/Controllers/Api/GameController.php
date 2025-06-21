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

class GameController extends Controller
{
    /**
     * Get all available games
     */
    public function index(): JsonResponse
    {
        $games = Game::enabled()->get()->map(function ($game) {
            return [
                'id' => $game->id,
                'name' => $game->title,
                'slug' => $game->slug,
                'description' => $game->description,
                'category' => $game->mechanic,
                'difficulty' => $game->getConfigValue('difficulty', 'medium'),
                'cost_tokens' => $game->token_cost,
                'reward_tokens' => $game->max_score_reward,
                'time_limit' => $game->getConfigValue('time_limit', 300),
                'max_attempts_per_day' => $game->getConfigValue('max_attempts_per_day', 15),
                'is_multiplayer' => $game->getConfigValue('is_multiplayer', false),
                'icon' => $game->getConfigValue('icon', '🎮'),
                'thumbnail' => $game->getConfigValue('thumbnail', '/images/games/default.png'),
                'instructions' => $game->instructions ?? [
                    'en' => $game->getConfigValue('instructions', ''),
                    'am' => ''
                ],
                'settings' => $game->config
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $games
        ]);
    }

    /**
     * Get specific game details
     */
    public function show(Game $game): JsonResponse
    {
        $gameData = [
            'id' => $game->id,
            'name' => $game->title,
            'slug' => $game->slug,
            'description' => $game->description,
            'instructions' => $game->instructions ?? [
                'en' => $game->getConfigValue('instructions', ''),
                'am' => ''
            ],
            'category' => $game->mechanic,
            'difficulty' => $game->getConfigValue('difficulty', 'medium'),
            'cost_tokens' => $game->token_cost,
            'reward_tokens' => $game->max_score_reward,
            'time_limit' => $game->getConfigValue('time_limit', 300),
            'max_attempts_per_day' => $game->getConfigValue('max_attempts_per_day', 15),
            'is_multiplayer' => $game->getConfigValue('is_multiplayer', false),
            'icon' => $game->getConfigValue('icon', '🎮'),
            'thumbnail' => $game->getConfigValue('thumbnail', '/images/games/default.png'),
            'settings' => $game->config,
            'total_plays' => $game->rounds()->count(),
            'average_score' => $game->rounds()->avg('score') ?? 0
        ];

        return response()->json([
            'success' => true,
            'data' => $gameData
        ]);
    }

    /**
     * Start a new game round
     */
    public function startRound(Request $request, Game $game): JsonResponse
    {
        $user = $request->user();

        // Check if game is active
        if (!$game->enabled) {
            return response()->json([
                'success' => false,
                'message' => 'Game is currently unavailable'
            ], 422);
        }

        // Check if user has enough tokens for NEW rounds only
        if (!$user->canAfford($game->token_cost)) {
            // First check if there's an active round they can continue
            $activeRound = $user->rounds()
                ->where('game_id', $game->id)
                ->whereNull('completed_at')
                ->first();

            $timeLimit = $game->getConfigValue('time_limit', 300);
            if ($activeRound) {
                // Check if round has expired
                if ($activeRound->created_at->addSeconds($timeLimit)->isPast()) {
                    $activeRound->update([
                        'completed_at' => now(),
                        'score' => 0,
                        'is_timeout' => true
                    ]);
                } else {
                    // Return the active round data
                    $responseData = [
                        'round_id' => $activeRound->id,
                        'seed' => $activeRound->seed,
                        'time_limit' => $timeLimit,
                        'started_at' => $activeRound->started_at,
                        'time_remaining' => $timeLimit - $activeRound->created_at->diffInSeconds(now()),
                        'user_tokens' => $user->tokens_balance
                    ];

                    // Add game-specific response data
                    if ($game->slug === 'codebreaker') {
                        $gameData = $activeRound->game_data ?? [];
                        $responseData['game_data'] = [
                            'max_attempts' => $gameData['max_attempts'] ?? 15,
                            'hints_available' => $gameData['hints_available'] ?? 2
                        ];
                    }

                    return response()->json([
                        'success' => true,
                        'message' => 'Continuing active round',
                        'data' => $responseData
                    ]);
                }
            }

            // No active round and insufficient tokens
            return response()->json([
                'success' => false,
                'message' => 'Insufficient tokens',
                'required' => $game->token_cost,
                'available' => $user->tokens_balance
            ], 422);
        }

        // Check for active round first (before daily limit check)
        $activeRound = $user->rounds()
            ->where('game_id', $game->id)
            ->whereNull('completed_at')
            ->first();

        $timeLimit = $game->getConfigValue('time_limit', 300);
        if ($activeRound) {
            // Check if round has expired
            if ($activeRound->created_at->addSeconds($timeLimit)->isPast()) {
                $activeRound->update([
                    'completed_at' => now(),
                    'score' => 0,
                    'is_timeout' => true
                ]);
            } else {
                // Return the active round data
                $responseData = [
                    'round_id' => $activeRound->id,
                    'seed' => $activeRound->seed,
                    'time_limit' => $timeLimit,
                    'started_at' => $activeRound->started_at,
                    'time_remaining' => $timeLimit - $activeRound->created_at->diffInSeconds(now()),
                    'user_tokens' => $user->tokens_balance
                ];

                // Add game-specific response data
                if ($game->slug === 'codebreaker') {
                    $gameData = $activeRound->game_data ?? [];
                    $responseData['game_data'] = [
                        'max_attempts' => $gameData['max_attempts'] ?? 15,
                        'hints_available' => $gameData['hints_available'] ?? 2
                    ];
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Continuing active round',
                    'data' => $responseData
                ]);
            }
        }

        // Check daily attempt limit (only for NEW rounds, excluding active rounds)
        $todayAttempts = $user->rounds()
            ->where('game_id', $game->id)
            ->whereDate('created_at', today())
            ->whereNotNull('completed_at') // Only count completed rounds
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
            $user->spendTokens($game->token_cost, "Started game: {$game->title}", [
                'game_id' => $game->id,
                'action' => 'start_game'
            ]);

            // Generate game seed for consistency
            $seed = $game->generateSeed();

            // Initialize game-specific data
            $gameData = [];
            if ($game->slug === 'codebreaker') {
                $codeBreakerService = app(\App\Services\Games\CodeBreakerService::class);
                $secretCode = $codeBreakerService->generateDailyCode($seed);
                
                $gameData = [
                    'secret_code' => $secretCode,
                    'max_attempts' => $game->getConfigValue('max_attempts', 15),
                    'hints_available' => $game->getConfigValue('hint_system', true) ? 2 : 0,
                    'hints_used' => 0,
                    'guesses' => [],
                    'solved' => false,
                    'hints' => []
                ];
            }

            // Create new round
            $round = Round::create([
                'user_id' => $user->id,
                'game_id' => $game->id,
                'seed' => $seed,
                'cost_tokens' => $game->token_cost,
                'duration_ms' => 0, // Will be updated when round completes
                'score_hash' => '', // Will be updated when round completes
                'status' => 'started',
                'game_data' => $gameData,
                'started_at' => now()
            ]);

            DB::commit();

            $responseData = [
                'round_id' => $round->id,
                'seed' => $seed,
                'time_limit' => $timeLimit,
                'started_at' => $round->started_at,
                'user_tokens' => $user->fresh()->tokens_balance
            ];

            // Add game-specific response data
            if ($game->slug === 'codebreaker') {
                $responseData['game_data'] = [
                    'max_attempts' => $gameData['max_attempts'],
                    'hints_available' => $gameData['hints_available']
                ];
            } elseif ($game->slug === 'hangman') {
                // For hangman, delegate to HangmanController
                return app(\App\Http\Controllers\Api\HangmanController::class)->startRound($request);
            }

            return response()->json([
                'success' => true,
                'message' => 'Round started successfully',
                'data' => $responseData
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to start game round', [
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
     * Submit game round results
     */
    public function submitRound(Request $request, Game $game): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'round_id' => 'required|exists:rounds,id',
            'score' => 'required|integer|min:0',
            'moves' => 'sometimes|array',
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

        // Verify round ownership
        if ($round->user_id !== $user->id || $round->game_id !== $game->id) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid round'
            ], 422);
        }

        // Check if round is already completed
        if ($round->completed_at) {
            // If round is already completed, return the existing data instead of failing
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
        $elapsedTime = $round->created_at->diffInSeconds(now());
        if ($elapsedTime > $game->getConfigValue('time_limit', 300)) {
            // For these games, use the submitted score even on timeout
            // For other games, timeout results in score 0
            $timeoutScore = (in_array($game->slug, ['letter-leap', 'math-sprint-duel', 'pixel-reveal', 'rapid-recall', 'number-merge-2048', 'word-grid-blitz', 'geo-sprint'])) ? $request->score : 0;
            
            Log::info('Game timeout detected', [
                'user_id' => $user->id,
                'game_slug' => $game->slug,
                'round_id' => $round->id,
                'elapsed_time' => $elapsedTime,
                'time_limit' => $game->getConfigValue('time_limit', 300),
                'submitted_score' => $request->score,
                'timeout_score' => $timeoutScore
            ]);
            
            $round->update([
                'completed_at' => now(),
                'score' => $timeoutScore,
                'is_timeout' => true
            ]);

            // Update leaderboards for Letter Leap and Math Sprint Duel even on timeout
            if (in_array($game->slug, ['letter-leap', 'math-sprint-duel', 'pixel-reveal', 'rapid-recall', 'number-merge-2048', 'word-grid-blitz', 'geo-sprint']) && $timeoutScore > 0) {
                Log::info('Updating leaderboards for timed out ' . $game->title . ' round', [
                    'user_id' => $user->id,
                    'game_id' => $game->id,
                    'round_id' => $round->id,
                    'score' => $timeoutScore
                ]);
                
                try {
                    $historicalLeaderboardService = app(\App\Services\HistoricalLeaderboardService::class);
                    $historicalLeaderboardService->updateLeaderboards($user, $game, $timeoutScore);
                    Log::info('Successfully updated leaderboards for timed out ' . $game->title . ' round');
                } catch (\Exception $e) {
                    Log::error('Failed to update leaderboards for timed out ' . $game->title . ' round', [
                        'user_id' => $user->id,
                        'game_id' => $game->id,
                        'round_id' => $round->id,
                        'score' => $timeoutScore,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            } else {
                Log::info('Skipping leaderboard update for timeout', [
                    'game_slug' => $game->slug,
                    'timeout_score' => $timeoutScore,
                    'is_letter_leap' => $game->slug === 'letter-leap'
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Round timed out'
            ], 422);
        }

        // Anti-cheat validation
        $antiCheatResult = $this->validateScore($game, $request->score, $request->completion_time, $request->moves);
        if (!$antiCheatResult['valid']) {
            Log::warning('Potential cheating detected', [
                'user_id' => $user->id,
                'game_id' => $game->id,
                'round_id' => $round->id,
                'score' => $request->score,
                'reason' => $antiCheatResult['reason']
            ]);

            $round->update([
                'completed_at' => now(),
                'score' => 0,
                'is_flagged' => true,
                'flag_reason' => $antiCheatResult['reason']
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid game data detected'
            ], 422);
        }

        // Additional validation for Code Breaker
        if ($game->slug === 'codebreaker') {
            $codeBreakerService = app(\App\Services\Games\CodeBreakerService::class);
            $gameData = $round->game_data ?? [];
            
            $validationResult = $codeBreakerService->validateCompletion(
                $gameData, 
                $request->score, 
                $request->completion_time
            );
            
            if (!$validationResult['valid']) {
                Log::warning('Code Breaker validation failed', [
                    'user_id' => $user->id,
                    'round_id' => $round->id,
                    'reason' => $validationResult['reason']
                ]);

                $round->update([
                    'completed_at' => now(),
                    'score' => 0,
                    'is_flagged' => true,
                    'flag_reason' => 'Code Breaker: ' . $validationResult['reason']
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Invalid Code Breaker game data'
                ], 422);
            }
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
                'moves' => $request->moves,
                'reward_tokens' => $rewardTokens,
                'experience_gained' => $experienceGained
            ]);

            // Award tokens and experience
            $user->awardTokens($rewardTokens, 'prize', "Game reward: {$game->title}", [
                'game_id' => $game->id,
                'round_id' => $round->id,
                'score' => $request->score
            ]);
            $user->addExperience($experienceGained);

            // Update leaderboard using the historical service
            $historicalLeaderboardService = app(\App\Services\HistoricalLeaderboardService::class);
            $historicalLeaderboardService->updateLeaderboards($user, $game, $request->score);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Round completed successfully',
                'data' => [
                    'score' => $request->score,
                    'tokens_earned' => $rewardTokens,
                    'experience_gained' => $experienceGained,
                    'user_tokens' => $user->fresh()->tokens_balance,
                    'user_level' => $user->fresh()->level,
                    'user_experience' => $user->fresh()->experience,
                    'is_personal_best' => $this->isPersonalBest($user, $game, $request->score)
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to submit game round', [
                'user_id' => $user->id,
                'game_id' => $game->id,
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
     * Get user's rounds for a specific game
     */
    public function userRounds(Request $request, Game $game): JsonResponse
    {
        $user = $request->user();
        
        $rounds = $user->rounds()
            ->where('game_id', $game->id)
            ->whereNotNull('completed_at')
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->map(function ($round) {
                return [
                    'id' => $round->id,
                    'score' => $round->score,
                    'completion_time' => $round->completion_time,
                    'tokens_earned' => $round->reward_tokens,
                    'experience_gained' => $round->experience_gained,
                    'played_at' => $round->created_at,
                    'is_personal_best' => false // Will be calculated
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $rounds
        ]);
    }

    /**
     * Validate score for anti-cheat
     */
    private function validateScore(Game $game, int $score, int $completionTime, ?array $moves): array
    {
        $settings = $game->settings;

        // Check maximum possible score
        if (isset($settings['max_score']) && $score > $settings['max_score']) {
            return ['valid' => false, 'reason' => 'Score exceeds maximum possible'];
        }

        // Check minimum completion time
        if (isset($settings['min_completion_time']) && $completionTime < $settings['min_completion_time']) {
            return ['valid' => false, 'reason' => 'Completion time too fast'];
        }

        // Check score vs time ratio
        if (isset($settings['max_score_per_second'])) {
            $scorePerSecond = $score / max($completionTime, 1);
            if ($scorePerSecond > $settings['max_score_per_second']) {
                return ['valid' => false, 'reason' => 'Score per second too high'];
            }
        }

        return ['valid' => true];
    }

    /**
     * Calculate experience gained
     */
    private function calculateExperience(Game $game, int $score): int
    {
        $baseExp = 5;
        $scoreMultiplier = $score / 100;
        $difficultyMultiplier = match($game->difficulty) {
            'easy' => 1.0,
            'medium' => 1.5,
            'hard' => 2.0,
            default => 1.0
        };
        $calculatedExp = (int) ($baseExp + ($scoreMultiplier * $difficultyMultiplier));
        
        // Cap experience at 10 XP maximum
        return min($calculatedExp, 10);
    }

    /**
     * Check if score is personal best
     */
    private function isPersonalBest(User $user, Game $game, int $score): bool
    {
        $bestScore = $user->rounds()
            ->where('game_id', $game->id)
            ->whereNotNull('completed_at')
            ->max('score');

        return $score >= ($bestScore ?? 0);
    }
} 
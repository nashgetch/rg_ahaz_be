<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Games\CodeBreakerService;
use App\Models\Round;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use App\Services\HistoricalLeaderboardService;

class CodeBreakerController extends Controller
{
    private CodeBreakerService $codeBreakerService;

    public function __construct(CodeBreakerService $codeBreakerService)
    {
        $this->codeBreakerService = $codeBreakerService;
    }

    /**
     * Make a guess for the current round
     */
    public function makeGuess(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'round_id' => 'required|exists:rounds,id',
            'guess' => 'required|string|size:4|regex:/^[0-9]{4}$/'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid guess format',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();
        $round = Round::find($request->round_id);

        // Verify round ownership
        if ($round->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid round'
            ], 422);
        }

        // Check if round is still active
        if ($round->status !== 'started') {
            return response()->json([
                'success' => false,
                'message' => 'Round is not active'
            ], 422);
        }

        // Get current game data
        $gameData = $round->game_data ?? [];
        $secretCode = $gameData['secret_code'] ?? null;
        $guesses = $gameData['guesses'] ?? [];
        $maxAttempts = $gameData['max_attempts'] ?? 15;

        if (!$secretCode) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid game state'
            ], 422);
        }

        // Check if max attempts reached
        if (count($guesses) >= $maxAttempts) {
            return response()->json([
                'success' => false,
                'message' => 'Maximum attempts reached'
            ], 422);
        }

        // Validate the guess
        $result = $this->codeBreakerService->validateGuess($secretCode, $request->guess);
        
        if (!$result['valid']) {
            return response()->json([
                'success' => false,
                'message' => $result['error']
            ], 422);
        }

        // Add guess to game data
        $guesses[] = [
            'guess' => $request->guess,
            'correct_position' => $result['correct_position'],
            'correct_digit' => $result['correct_digit'],
            'feedback' => $result['feedback'],
            'timestamp' => now()->toISOString()
        ];

        $gameData['guesses'] = $guesses;
        $gameData['attempts'] = count($guesses);
        $gameData['solved'] = $result['is_solved'];

        // Update round
        $round->update([
            'game_data' => $gameData,
            'score' => $gameData['solved'] ? $this->calculateCurrentScore($round, $gameData) : 0
        ]);

        $responseData = [
            'round_id' => $round->id,
            'guess_result' => $result,
            'attempts_made' => count($guesses),
            'attempts_remaining' => $maxAttempts - count($guesses),
            'game_solved' => $result['is_solved'],
            'current_score' => $round->score
        ];

        // Include all guesses for game state
        $responseData['all_guesses'] = $guesses;

        // If game is solved or max attempts reached, automatically complete the round
        if ($result['is_solved'] || count($guesses) >= $maxAttempts) {
            $responseData['game_complete'] = true;
            $responseData['can_submit'] = true;
            
            // Auto-complete the round for consistency
            if (!$round->completed_at) {
                $completionTime = $round->started_at->diffInSeconds(now());
                
                $round->update([
                    'completed_at' => now(),
                    'status' => 'completed',
                    'completion_time' => $completionTime
                ]);
                
                // Update leaderboards when round is auto-completed
                $historicalLeaderboardService = app(HistoricalLeaderboardService::class);
                $historicalLeaderboardService->updateLeaderboards($request->user(), $round->game, $round->score);
                
                // Include secret code in response when game ends
                $responseData['secret_code'] = $gameData['secret_code'];
            }
        }

        return response()->json([
            'success' => true,
            'message' => $result['is_solved'] ? 'Code cracked!' : 'Guess recorded',
            'data' => $responseData
        ]);
    }

    /**
     * Get a hint for the current round
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

        // Verify round ownership
        if ($round->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid round'
            ], 422);
        }

        // Get current game data
        $gameData = $round->game_data ?? [];
        $secretCode = $gameData['secret_code'] ?? null;
        $hintsUsed = $gameData['hints_used'] ?? 0;
        $hintsAvailable = $gameData['hints_available'] ?? 2;

        if (!$secretCode) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid game state'
            ], 422);
        }

        if ($hintsUsed >= $hintsAvailable) {
            return response()->json([
                'success' => false,
                'message' => 'No hints remaining'
            ], 422);
        }

        // Generate hint
        $hint = $this->codeBreakerService->generateHint(
            $secretCode, 
            $gameData['guesses'] ?? [], 
            $hintsUsed + 1
        );

        // Update game data
        $gameData['hints_used'] = $hintsUsed + 1;
        $gameData['hints'][] = [
            'hint' => $hint,
            'used_at' => now()->toISOString()
        ];

        $round->update(['game_data' => $gameData]);

        return response()->json([
            'success' => true,
            'message' => 'Hint provided',
            'data' => [
                'hint' => $hint,
                'hints_used' => $gameData['hints_used'],
                'hints_remaining' => $hintsAvailable - $gameData['hints_used']
            ]
        ]);
    }

    /**
     * Get current game state
     */
    public function getGameState(Request $request, Round $round): JsonResponse
    {
        $user = $request->user();

        // Verify round ownership
        if ($round->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid round'
            ], 422);
        }

        $gameData = $round->game_data ?? [];

        return response()->json([
            'success' => true,
            'data' => [
                'round_id' => $round->id,
                'status' => $round->status,
                'attempts_made' => count($gameData['guesses'] ?? []),
                'max_attempts' => $gameData['max_attempts'] ?? 15,
                'hints_used' => $gameData['hints_used'] ?? 0,
                'hints_available' => $gameData['hints_available'] ?? 2,
                'guesses' => $gameData['guesses'] ?? [],
                'solved' => $gameData['solved'] ?? false,
                'current_score' => $round->score,
                'time_started' => $round->started_at->toISOString()
            ]
        ]);
    }

    /**
     * Handle game timeout
     */
    public function handleTimeout(Request $request): JsonResponse
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

        // Verify round ownership
        if ($round->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid round'
            ], 422);
        }

        // Check if round is still active
        if ($round->status !== 'started') {
            return response()->json([
                'success' => false,
                'message' => 'Round is not active'
            ], 422);
        }

        // Check if round is already completed
        if ($round->completed_at) {
            return response()->json([
                'success' => false,
                'message' => 'Round already completed'
            ], 422);
        }

        $gameData = $round->game_data ?? [];
        
        // Mark game as timed out
        $gameData['timed_out'] = true;
        $gameData['timeout_at'] = now()->toISOString();
        
        // Calculate completion time
        $completionTime = $round->started_at->diffInSeconds(now());
        
        // Complete the round with score 0 for timeout
        $round->update([
            'completed_at' => now(),
            'status' => 'completed',
            'completion_time' => $completionTime,
            'score' => 0, // Timeout results in 0 score
            'game_data' => $gameData
        ]);

        // Update leaderboards with timeout completion
        $historicalLeaderboardService = app(HistoricalLeaderboardService::class);
        $historicalLeaderboardService->updateLeaderboards($user, $round->game, 0);

        return response()->json([
            'success' => true,
            'message' => 'Game completed due to timeout',
            'data' => [
                'round_id' => $round->id,
                'status' => 'completed',
                'score' => 0,
                'completion_time' => $completionTime,
                'timed_out' => true,
                'secret_code' => $gameData['secret_code'] ?? null
            ]
        ]);
    }

    /**
     * Calculate current score based on game progress
     */
    private function calculateCurrentScore(Round $round, array $gameData): int
    {
        if (!($gameData['solved'] ?? false)) {
            return 0;
        }

        $attempts = count($gameData['guesses'] ?? []);
        $timeElapsed = $round->started_at->diffInSeconds(now());
        
        return $this->codeBreakerService->calculateScore($attempts, $timeElapsed, true);
    }
} 
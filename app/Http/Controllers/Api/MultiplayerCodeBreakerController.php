<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MultiplayerRoom;
use App\Models\MultiplayerParticipant;
use App\Services\StrictBettingService;
use App\Events\CodebreakerGameUpdated;
use App\Events\MultiplayerRoomUpdated;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class MultiplayerCodeBreakerController extends Controller
{
    protected StrictBettingService $bettingService;

    public function __construct(StrictBettingService $bettingService)
    {
        $this->bettingService = $bettingService;
    }

    /**
     * Start multiplayer CodeBreaker game for a room
     */
    public function start(string $roomCode): JsonResponse
    {
        Log::info('CodeBreaker start method called', [
            'room_code' => $roomCode,
            'user_id' => Auth::id()
        ]);

        $room = MultiplayerRoom::with('game')->where('room_code', $roomCode)->first();

        if (!$room) {
            Log::warning('Room not found', ['room_code' => $roomCode]);
            return response()->json([
                'success' => false,
                'message' => 'Room not found'
            ], 404);
        }

        Log::info('Room found', [
            'room_code' => $roomCode,
            'room_status' => $room->status,
            'game_slug' => $room->game->slug ?? 'null'
        ]);

        if ($room->game->slug !== 'codebreaker') {
            Log::warning('Wrong game type', [
                'room_code' => $roomCode,
                'expected' => 'codebreaker',
                'actual' => $room->game->slug
            ]);
            return response()->json([
                'success' => false,
                'message' => 'This room is not for CodeBreaker'
            ], 400);
        }

        $participant = $room->participants()->where('user_id', Auth::id())->first();

        if (!$participant) {
            Log::warning('User not in room', [
                'room_code' => $roomCode,
                'user_id' => Auth::id()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'You are not in this room'
            ], 400);
        }

        Log::info('Participant found', [
            'room_code' => $roomCode,
            'user_id' => Auth::id(),
            'participant_status' => $participant->status
        ]);

        if ($room->status !== 'starting' && $room->status !== 'in_progress' && $room->status !== 'waiting') {
            Log::warning('Game not ready to start', [
                'room_code' => $roomCode,
                'room_status' => $room->status,
                'allowed_statuses' => ['starting', 'in_progress', 'waiting']
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Game is not ready to start. Current status: ' . $room->status
            ], 400);
        }

        // Generate the secret code for this room if not already generated
        if (!isset($room->game_state['secret_code'])) {
            $secretCode = $this->generateSecretCode();
            $room->update([
                'status' => 'in_progress',
                'game_state' => array_merge($room->game_state ?? [], [
                    'secret_code' => $secretCode,
                    'max_attempts' => 7,
                    'started_at' => now()->toISOString()
                ])
            ]);

            Log::info('Game state initialized', [
                'room_code' => $roomCode,
                'secret_code_length' => strlen($secretCode)
            ]);
        } else {
            Log::info('Game state already exists', [
                'room_code' => $roomCode,
                'has_secret_code' => isset($room->game_state['secret_code'])
            ]);
        }

        // Initialize participant's game progress
        $participant->updateProgress([
            'attempts' => 0,
            'guesses' => [],
            'hints_used' => 0,
            'started_at' => now()->toISOString()
        ]);

        $totalPot = $room->participants()->sum('bet_amount');

        Log::info('Game started successfully', [
            'room_code' => $roomCode,
            'user_id' => Auth::id(),
            'total_pot' => $totalPot
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Game started successfully',
            'data' => [
                'room_code' => $room->room_code,
                'max_attempts' => 7,
                'hints_available' => 2,
                'total_pot' => $totalPot,
                'participants' => $room->participants()
                    ->with('user:id,name')
                    ->get()
                    ->map(function ($p) {
                        return [
                            'username' => $p->user ? $p->user->name : 'Unknown User',
                            'attempts' => $p->game_progress['attempts'] ?? 0,
                            'status' => $p->status,
                            'score' => $p->score,
                            'bet_amount' => $p->locked_tokens ?? 0
                        ];
                    })
            ]
        ]);
    }

    /**
     * Make a guess in multiplayer CodeBreaker
     */
    public function guess(Request $request, string $roomCode): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'guess' => 'required|string|size:4|regex:/^[0-9]{4}$/',
            'time_taken' => 'nullable|integer|min:0'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid guess format. Must be 4 digits.',
                'errors' => $validator->errors()
            ], 422);
        }

        $room = MultiplayerRoom::where('room_code', $roomCode)->first();

        if (!$room || $room->status !== 'in_progress') {
            return response()->json([
                'success' => false,
                'message' => 'Game is not active'
            ], 400);
        }

        $participant = $room->participants()->where('user_id', Auth::id())->first();

        if (!$participant || $participant->status === 'finished') {
            return response()->json([
                'success' => false,
                'message' => 'You cannot make guesses at this time'
            ], 400);
        }

        $gameProgress = $participant->game_progress ?? [];
        $attempts = $gameProgress['attempts'] ?? 0;
        $guesses = $gameProgress['guesses'] ?? [];

        if ($attempts >= 7) {
            return response()->json([
                'success' => false,
                'message' => 'Maximum attempts reached'
            ], 400);
        }

        $guess = $request->guess;
        $secretCode = $room->game_state['secret_code'];
        $timeTaken = $request->time_taken ?? 0;
        
        // Calculate feedback
        $feedback = $this->calculateFeedback($guess, $secretCode);
        $isCorrect = $feedback['correct_positions'] === 4;
        
        $attempts++;
        $guesses[] = [
            'guess' => $guess,
            'feedback' => $feedback,
            'attempt' => $attempts,
            'timestamp' => now()->toISOString()
        ];

        // ✅ FIX: Initialize score for all cases (ongoing games and finished games)
        $score = $participant->score ?? 0; // Use current participant score for ongoing games
        
        if ($isCorrect || $attempts >= 7) {
            if ($isCorrect) {
                // Calculate score based on attempts and time
                $score = max(0, 2000 - (($attempts - 1) * 200) - ($timeTaken * 0.5));
                $score = round($score);
                
                $participant->markFinished($score);
                
                Log::info('Player marked as finished', [
                    'room_code' => $room->room_code,
                    'user_id' => Auth::id(),
                    'player_name' => Auth::user()->name,
                    'final_score' => $score,
                    'participant_status' => $participant->fresh()->status,
                    'is_solved' => true
                ]);
                
                $participant->updateProgress([
                    'attempts' => $attempts,
                    'guesses' => $guesses,
                    'is_solved' => true,
                    'final_score' => $score,
                    'completion_time' => $timeTaken,
                    'finished_at' => now()->toISOString()
                ]);

                // Broadcast that this player broke the code
                broadcast(new CodebreakerGameUpdated(
                    $room->room_code,
                    'code_broken',
                    Auth::id(),
                    [
                        'player_broke_code' => true,
                        'player_name' => Auth::user()->name,
                        'player_score' => $score,
                        'attempts_used' => count($guesses),
                        'leaderboard' => $this->getRoomLeaderboard($room)
                    ]
                ));

                Log::info('Player broke the code', [
                    'room_code' => $room->room_code,
                    'user_id' => Auth::id(),
                    'player_name' => Auth::user()->name,
                    'score' => $score,
                    'attempts_used' => count($guesses)
                ]);
            } elseif ($attempts >= 7) {
                // Out of attempts - small consolation score based on attempts made
                $score = max(0, 100 - ($attempts * 10) - ($timeTaken * 0.1));
                $score = round($score);
                
                $participant->markFinished($score);
                
                Log::info('Player finished - out of attempts', [
                    'room_code' => $room->room_code,
                    'user_id' => Auth::id(),
                    'final_score' => $score,
                    'participant_status' => $participant->fresh()->status,
                    'attempts_used' => $attempts,
                    'is_solved' => false
                ]);
                
                $participant->updateProgress([
                    'attempts' => $attempts,
                    'guesses' => $guesses,
                    'is_solved' => false,
                    'final_score' => $score,
                    'completion_time' => $timeTaken,
                    'finished_at' => now()->toISOString()
                ]);
            }
            
            // ✅ ADD: Handle betting when player finishes
            $bettingResult = null;
            if ($room->has_active_bets) {
                try {
                    $bettingResult = $this->bettingService->handleGameCompletion($room, $participant, $score);
                    
                    Log::info('Betting service result for completed player', [
                        'room_code' => $room->room_code,
                        'user_id' => Auth::id(),
                        'betting_completed' => $bettingResult['completed'],
                        'has_winner' => !is_null($bettingResult['winner']),
                        'total_winnings' => $bettingResult['total_winnings']
                    ]);
                    
                    // Update participant progress with betting results
                    $currentProgress = $participant->game_progress ?? [];
                    $currentProgress['betting_results'] = [
                        'game_completed' => $bettingResult['completed'],
                        'total_pot' => $room->total_bet_pool ?? 0,
                        'tokens_won' => 0,
                        'tokens_lost' => 0
                    ];
                    
                    // If this player is the winner, record their winnings
                    if ($bettingResult['winner'] && $bettingResult['winner']->user_id === Auth::id()) {
                        $currentProgress['betting_results']['tokens_won'] = $bettingResult['total_winnings'];
                    } else {
                        // This player lost their bet - use locked_tokens not bet_amount
                        $currentProgress['betting_results']['tokens_lost'] = $participant->locked_tokens ?? 0;
                    }
                    
                    $participant->update(['game_progress' => $currentProgress]);
                    
                } catch (\Exception $e) {
                    Log::error('Error handling codebreaker betting completion', [
                        'room_code' => $room->room_code,
                        'user_id' => Auth::id(),
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            }

            // Check if all participants have finished
            Log::info('Triggering game completion check', [
                'room_code' => $room->room_code,
                'user_id' => Auth::id(),
                'trigger_reason' => $isCorrect ? 'player_solved' : ($attempts >= 7 ? 'player_out_of_attempts' : 'player_guess')
            ]);
            
            $this->checkGameCompletion($room);
            
            Log::info('Game completion check completed', [
                'room_code' => $room->room_code,
                'current_room_status' => $room->fresh()->status
            ]);

            // Broadcast game update via WebSocket
            broadcast(new CodebreakerGameUpdated(
                $room->room_code,
                'guess_made',
                Auth::id(),
                [
                    'guess' => $guess,
                    'feedback' => $feedback,
                    'is_correct' => $isCorrect,
                    'attempts_remaining' => 7 - $attempts,
                    'attempt' => $attempts,
                    'score' => $score,
                    'game_finished' => $isCorrect || $attempts >= 7,
                    'leaderboard' => $this->getRoomLeaderboard($room)
                ]
            ));

            return response()->json([
                'success' => true,
                'data' => [
                    'guess' => $guess,
                    'feedback' => $feedback,
                    'is_correct' => $isCorrect,
                    'attempts_remaining' => 7 - $attempts,
                    'attempt' => $attempts,
                    'score' => $score,
                    'game_finished' => $isCorrect || $attempts >= 7,
                    'leaderboard' => $this->getRoomLeaderboard($room)
                ]
            ]);
        } else {
            // Update progress for ongoing game
            $participant->updateProgress([
                'attempts' => $attempts,
                'guesses' => $guesses,
                'current_time' => $timeTaken
            ]);

            // Broadcast game update via WebSocket
            broadcast(new CodebreakerGameUpdated(
                $room->room_code,
                'guess_made',
                Auth::id(),
                [
                    'guess' => $guess,
                    'feedback' => $feedback,
                    'is_correct' => $isCorrect,
                    'attempts_remaining' => 7 - $attempts,
                    'attempt' => $attempts,
                    'score' => $score,
                    'game_finished' => $isCorrect || $attempts >= 7,
                    'leaderboard' => $this->getRoomLeaderboard($room)
                ]
            ));

            return response()->json([
                'success' => true,
                'data' => [
                    'guess' => $guess,
                    'feedback' => $feedback,
                    'is_correct' => $isCorrect,
                    'attempts_remaining' => 7 - $attempts,
                    'attempt' => $attempts,
                    'score' => $score,
                    'game_finished' => $isCorrect || $attempts >= 7,
                    'leaderboard' => $this->getRoomLeaderboard($room)
                ]
            ]);
        }
    }

    /**
     * Forfeit/timeout the current game for a participant
     */
    public function forfeit(string $roomCode): JsonResponse
    {
        $room = MultiplayerRoom::where('room_code', $roomCode)->first();

        if (!$room || $room->status !== 'in_progress') {
            return response()->json([
                'success' => false,
                'message' => 'Game is not active'
            ], 400);
        }

        $participant = $room->participants()->where('user_id', Auth::id())->first();

        if (!$participant || $participant->status === 'finished') {
            return response()->json([
                'success' => false,
                'message' => 'You have already finished or are not in this game'
            ], 400);
        }

        $gameProgress = $participant->game_progress ?? [];
        $attempts = $gameProgress['attempts'] ?? 0;
        $timeTaken = $gameProgress['current_time'] ?? 0;

        // Mark as finished with current score (likely 0 or very low)
        $score = max(0, 25 - ($attempts * 3)); // Small score based on attempts made
        $participant->markFinished($score);
        
        $participant->updateProgress([
            'attempts' => $attempts,
            'guesses' => $gameProgress['guesses'] ?? [],
            'is_solved' => false,
            'final_score' => $score,
            'completion_time' => $timeTaken,
            'finished_at' => now()->toISOString(),
            'forfeited' => true
        ]);

        Log::info("Player forfeited game", [
            'room_code' => $room->room_code,
            'user_id' => Auth::id(),
            'attempts_made' => $attempts,
            'final_score' => $score
        ]);

        // ✅ ADD: Handle betting when player forfeits
        $bettingResult = null;
        if ($room->has_active_bets) {
            try {
                $bettingResult = $this->bettingService->handleGameCompletion($room, $participant, $score);
                
                Log::info('Betting service result for forfeited player', [
                    'room_code' => $room->room_code,
                    'user_id' => Auth::id(),
                    'betting_completed' => $bettingResult['completed'],
                    'has_winner' => !is_null($bettingResult['winner']),
                    'total_winnings' => $bettingResult['total_winnings']
                ]);
                
                // Update participant progress with betting results
                $currentProgress = $participant->game_progress ?? [];
                $currentProgress['betting_results'] = [
                    'game_completed' => $bettingResult['completed'],
                    'total_pot' => $room->total_bet_pool ?? 0,
                    'tokens_won' => 0,
                    'tokens_lost' => $participant->locked_tokens ?? 0 // Forfeited players lose their bet
                ];
                
                $participant->update(['game_progress' => $currentProgress]);
                
            } catch (\Exception $e) {
                Log::error('Error handling codebreaker betting forfeit', [
                    'room_code' => $room->room_code,
                    'user_id' => Auth::id(),
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }

        // Check if all participants have finished
        $this->checkGameCompletion($room);

        return response()->json([
            'success' => true,
            'data' => [
                'message' => 'Game forfeited',
                'final_score' => $score,
                'leaderboard' => $this->getRoomLeaderboard($room)
            ]
        ]);
    }

    /**
     * Get a hint for the current game (shared across all players)
     */
    public function hint(string $roomCode): JsonResponse
    {
        $room = MultiplayerRoom::where('room_code', $roomCode)->first();

        if (!$room || $room->status !== 'in_progress') {
            return response()->json([
                'success' => false,
                'message' => 'Game is not active'
            ], 400);
        }

        $participant = $room->participants()->where('user_id', Auth::id())->first();

        if (!$participant || $participant->status === 'finished') {
            return response()->json([
                'success' => false,
                'message' => 'You cannot get hints at this time'
            ], 400);
        }

        $gameState = $room->game_state ?? [];
        $hintsUsed = $gameState['hints_used'] ?? 0;
        $globalHints = $gameState['hints'] ?? [];

        if ($hintsUsed >= 2) {
            return response()->json([
                'success' => false,
                'message' => 'No more hints available for this room'
            ], 400);
        }

        $secretCode = $room->game_state['secret_code'];
        $hint = $this->generateHint($secretCode, $hintsUsed);
        
        // Update global room hints
        $globalHints[] = $hint;
        $room->update([
            'game_state' => array_merge($gameState, [
                'hints_used' => $hintsUsed + 1,
                'hints' => $globalHints
            ])
        ]);

        // Broadcast hint update via WebSocket
        broadcast(new CodebreakerGameUpdated(
            $room->room_code,
            'hint_used',
            Auth::id(),
            [
                'hint' => $hint,
                'hints_remaining' => 1 - $hintsUsed,
                'all_hints' => $globalHints
            ]
        ));

        return response()->json([
            'success' => true,
            'data' => [
                'hint' => $hint,
                'hints_remaining' => 1 - $hintsUsed,
                'all_hints' => $globalHints
            ]
        ]);
    }

    /**
     * Get current room status and leaderboard
     */
    public function status(string $roomCode): JsonResponse
    {
        try {
            $room = MultiplayerRoom::where('room_code', $roomCode)
                ->with(['participants.user', 'game'])
                ->firstOrFail();

            // Get room leaderboard
            $leaderboard = $this->getRoomLeaderboard($room);

            // Get current user's progress
            $userProgress = null;
            $participant = $room->participants()->where('user_id', Auth::id())->first();
            if ($participant) {
                $userProgress = $this->getUserProgress($participant);
            }

            // Get shared hints from room game state (not individual participants)
            $gameState = $room->game_state ?? [];
            $sharedHints = $gameState['hints'] ?? [];
            $hintsUsed = $gameState['hints_used'] ?? 0;
            $hintsRemaining = max(0, 2 - $hintsUsed);

            // Prepare response data
            $responseData = [
                'room_code' => $room->room_code,
                'room_status' => $room->status,
                'leaderboard' => $leaderboard,
                'your_progress' => $userProgress,
                'shared_hints' => $sharedHints,
                'hints_remaining' => $hintsRemaining,
                'total_pot' => $this->calculateTotalPot($room)
            ];

            // Add winner and battle history if game is completed
            if ($room->status === 'completed') {
                $winner = !empty($leaderboard) ? $leaderboard[0] : null;
                
                if ($winner) {
                    $responseData['winner'] = [
                        'username' => $winner['username'],
                        'user_id' => $winner['user_id'] ?? null,
                        'score' => $winner['score'],
                        'rank' => $winner['rank'],
                        'is_solved' => $winner['is_solved'],
                        'completion_time' => $winner['time_taken']
                    ];
                }

                // Generate battle history
                $responseData['battle_history'] = $this->generateBattleHistory($room, $leaderboard);
        }

        return response()->json([
            'success' => true,
                'data' => $responseData
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get codebreaker status', [
                'room_code' => $roomCode,
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get game status'
            ], 500);
        }
    }

    private function generateSecretCode(): string
    {
        $digits = [];
        $availableDigits = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9];
        
        // Generate 4 unique digits
        for ($i = 0; $i < 4; $i++) {
            $randomIndex = array_rand($availableDigits);
            $digits[] = $availableDigits[$randomIndex];
            unset($availableDigits[$randomIndex]);
            $availableDigits = array_values($availableDigits); // Reindex array
        }
        
        return implode('', $digits);
    }

    private function calculateFeedback(string $guess, string $secret): array
    {
        $correctPositions = 0;
        $correctDigits = 0;
        
        $guessArray = str_split($guess);
        $secretArray = str_split($secret);
        $secretCount = array_count_values($secretArray);
        $guessCount = array_count_values($guessArray);
        
        // Count correct positions
        for ($i = 0; $i < 4; $i++) {
            if ($guessArray[$i] === $secretArray[$i]) {
                $correctPositions++;
            }
        }
        
        // Count correct digits (including correct positions)
        foreach ($guessCount as $digit => $count) {
            if (isset($secretCount[$digit])) {
                $correctDigits += min($count, $secretCount[$digit]);
            }
        }
        
        return [
            'correct_positions' => $correctPositions,
            'correct_digits' => $correctDigits,
            'wrong_positions' => $correctDigits - $correctPositions
        ];
    }

    private function generateHint(string $secretCode, int $hintNumber): string
    {
        $digits = str_split($secretCode);
        
        if ($hintNumber === 0) {
            // First hint: reveal one random digit
            $position = rand(0, 3);
            return "Position " . ($position + 1) . " is " . $digits[$position];
        } else {
            // Second hint: reveal if a specific digit is in the code
            $testDigit = rand(0, 9);
            $isInCode = in_array((string)$testDigit, $digits);
            return $isInCode 
                ? "The digit {$testDigit} is in the code"
                : "The digit {$testDigit} is not in the code";
        }
    }

    private function getRoomLeaderboard(MultiplayerRoom $room): array
    {
        // For completed games, use the saved final_scores which preserves bet amounts
        if ($room->status === 'completed' && $room->final_scores) {
            return $room->final_scores;
        }
        
        // For active games, use live participant data
        return $room->participants()
            ->with('user:id,name')
            ->get()
            ->sortByDesc('score')
            ->values()
            ->map(function ($participant, $index) {
                $progress = $participant->game_progress ?? [];
                return [
                    'rank' => $index + 1,
                    'user_id' => $participant->user_id,
                    'username' => $participant->user ? $participant->user->name : 'Unknown User',
                    'score' => $participant->score,
                    'attempts' => $progress['attempts'] ?? 0,
                    'status' => $participant->status,
                    'is_solved' => $progress['is_solved'] ?? false,
                    'bet_amount' => $participant->locked_tokens ?? 0,
                    'time_taken' => $this->formatTime($progress['completion_time'] ?? $progress['current_time'] ?? 0),
                    'finished_at' => $participant->finished_at?->toISOString()
                ];
            })
            ->toArray();
    }

    private function getParticipantProgress(MultiplayerRoom $room, int $userId): ?array
    {
        $participant = $room->participants()->where('user_id', $userId)->first();
        
        if (!$participant) {
            return null;
        }

        $progress = $participant->game_progress ?? [];
        
        return [
            'attempts' => $progress['attempts'] ?? 0,
            'guesses' => $progress['guesses'] ?? [],
            'hints_used' => $progress['hints_used'] ?? 0,
            'hints' => $progress['hints'] ?? [],
            'score' => $participant->score,
            'status' => $participant->status,
            'is_solved' => $progress['is_solved'] ?? false
        ];
    }

    /**
     * Get user progress from participant object
     */
    private function getUserProgress($participant): ?array
    {
        if (!$participant) {
            return null;
        }

        $progress = $participant->game_progress ?? [];
        
        return [
            'attempts' => $progress['attempts'] ?? 0,
            'guesses' => $progress['guesses'] ?? [],
            'hints_used' => $progress['hints_used'] ?? 0,
            'hints' => $progress['hints'] ?? [],
            'score' => $participant->score ?? 0,
            'status' => $participant->status,
            'is_solved' => $progress['is_solved'] ?? false,
            'finished_at' => $progress['finished_at'] ?? null,
            'completion_time' => $progress['completion_time'] ?? null,
            'betting_results' => $progress['betting_results'] ?? null
        ];
    }

    /**
     * Generate battle history for the completed game
     */
    private function generateBattleHistory(MultiplayerRoom $room, $leaderboard)
    {
        // Convert to collection if it's an array
        $leaderboardCollection = is_array($leaderboard) ? collect($leaderboard) : $leaderboard;
        
        $battleHistory = $leaderboardCollection->map(function ($player, $index) {
            // Handle both array and object data
            if (is_array($player)) {
                return [
                    'user_id' => $player['user_id'] ?? null,
                    'username' => $player['username'] ?? 'Unknown',
                    'rank' => $player['rank'] ?? ($index + 1),
                    'score' => $player['score'] ?? 0,
                    'is_solved' => $player['is_solved'] ?? false,
                    'completion_time' => $player['time_taken'] ?? 'N/A',
                    'attempts_used' => $player['attempts'] ?? 0,
                    'finished_at' => $player['finished_at'] ?? null
                ];
            } else {
                // Fallback for object data
                return [
                    'user_id' => $player->user_id ?? null,
                    'username' => $player->username ?? 'Unknown',
                    'rank' => $player->rank ?? ($index + 1),
                    'score' => $player->score ?? 0,
                    'is_solved' => $player->is_solved ?? false,
                    'completion_time' => $player->completion_time ?? $player->time_taken ?? 'N/A',
                    'attempts_used' => $player->attempts_used ?? $player->attempts ?? 0,
                    'finished_at' => $player->finished_at ?? null
                ];
            }
        });

        return $battleHistory->toArray();
    }

    /**
     * Check if all participants have finished and handle game completion
     */
    private function checkGameCompletion(MultiplayerRoom $room)
    {
        // Refresh room data to check current status
        $room = $room->fresh();
        
        // If betting service already completed the game, just handle broadcasting
        if ($room->status === 'completed') {
            Log::info('Room already completed by betting service', [
                'room_code' => $room->room_code,
                'room_status' => $room->status
            ]);
            
            // Get final leaderboard
            $finalLeaderboard = $this->getRoomLeaderboard($room);
            $leaderboard = collect($finalLeaderboard);
            
            // Determine winner (highest ranked player)
            $winner = $leaderboard->isNotEmpty() ? $leaderboard->first() : null;

            // Generate battle history
            $battleHistory = $this->generateBattleHistory($room, $leaderboard);

            // Calculate total pot
            $totalPot = $this->calculateTotalPot($room);

            // Broadcast game completion
            broadcast(new CodebreakerGameUpdated(
                $room->room_code,
                'game_completed',
                null,
                [
                    'game_completed' => true,
                    'room_status' => 'completed',
                    'winner' => $winner ? [
                        'username' => $winner['username'],
                        'user_id' => $winner['user_id'] ?? null,
                        'score' => $winner['score'],
                        'rank' => $winner['rank'],
                        'is_solved' => $winner['is_solved'],
                        'completion_time' => $winner['time_taken']
                    ] : null,
                    'final_leaderboard' => $leaderboard->toArray(),
                    'battle_history' => $battleHistory,
                    'total_pot' => $totalPot
                ]
            ));

            Log::info('Game completion broadcast sent for betting-completed room', [
                'room_code' => $room->room_code,
                'winner' => $winner ? $winner['username'] : 'None',
                'total_pot' => $totalPot
            ]);
            
            return; // Exit early since betting service handled completion
        }
        
        // Original logic for non-betting games or if betting service hasn't completed yet
        $participants = $room->participants()->where('status', '!=', 'invited')->get();
        $totalParticipants = $participants->count();
        $finishedCount = $participants->where('status', 'finished')->count();
        
        Log::info('Checking game completion', [
            'room_code' => $room->room_code,
            'total_participants' => $totalParticipants,
            'finished_participants' => $finishedCount,
            'participants_status' => $participants->pluck('status', 'user_id')->toArray(),
            'has_active_bets' => $room->has_active_bets ?? false
        ]);

        if ($finishedCount === $totalParticipants) {
            Log::info('All participants finished, completing game', [
                'room_code' => $room->room_code,
                'total_participants' => $totalParticipants,
                'finished_participants' => $finishedCount
            ]);

            // Get final leaderboard before updating room status
            $finalLeaderboard = $this->getRoomLeaderboard($room);
            
            // ✅ FIX: Update room status FIRST, then broadcast (only for non-betting games)
            if (!$room->has_active_bets) {
                $room->update([
                    'status' => 'completed',
                    'final_scores' => $finalLeaderboard
                ]);
                
                // Refresh room instance to ensure status is updated
                $room = $room->fresh();
            }

            // Convert to collection for consistent data handling
            $leaderboard = collect($finalLeaderboard);
            
            // Determine winner (highest ranked player)
            $winner = $leaderboard->isNotEmpty() ? $leaderboard->first() : null;

            // Generate battle history
            $battleHistory = $this->generateBattleHistory($room, $leaderboard);

            // Calculate total pot
            $totalPot = $this->calculateTotalPot($room);

            // ✅ FIX: Broadcast game completion AFTER room status is updated
            broadcast(new CodebreakerGameUpdated(
                $room->room_code,
                'game_completed',
                null,
                [
                    'game_completed' => true,
                    'room_status' => $room->status,
                    'winner' => $winner ? [
                        'username' => $winner['username'],
                        'user_id' => $winner['user_id'] ?? null,
                        'score' => $winner['score'],
                        'rank' => $winner['rank'],
                        'is_solved' => $winner['is_solved'],
                        'completion_time' => $winner['time_taken']
                    ] : null,
                    'final_leaderboard' => $leaderboard->toArray(),
                    'battle_history' => $battleHistory,
                    'total_pot' => $totalPot
                ]
            ));

            Log::info('Game completion broadcast sent', [
                'room_code' => $room->room_code,
                'room_status' => $room->status,
                'winner' => $winner ? $winner['username'] : 'None',
                'leaderboard_count' => $leaderboard->count(),
                'battle_history_count' => is_array($battleHistory) ? count($battleHistory) : 0,
                'total_pot' => $totalPot,
                'handled_by_betting_service' => $room->has_active_bets ?? false
            ]);
        } else {
            Log::info('Game still in progress', [
                'room_code' => $room->room_code,
                'finished' => $finishedCount,
                'total' => $totalParticipants,
                'waiting_for' => $totalParticipants - $finishedCount . ' more players'
            ]);
        }
    }

    /**
     * Calculate total pot for the room
     */
    private function calculateTotalPot(MultiplayerRoom $room)
    {
        $participants = $room->participants;
        return $participants->sum('locked_tokens') + ($participants->count() * $room->entry_fee);
    }

    private function checkForInactivePlayers(MultiplayerRoom $room): void
    {
        $inactivityTimeout = 300; // 5 minutes in seconds
        $gameStartTime = $room->started_at;
        
        if (!$gameStartTime || $gameStartTime->diffInSeconds(now()) < $inactivityTimeout) {
            return; // Don't timeout in first 5 minutes
        }

        $participants = $room->participants()->where('status', 'playing')->get();
        
        foreach ($participants as $participant) {
            $gameProgress = $participant->game_progress ?? [];
            $lastActivity = $participant->updated_at;
            
            // Check if player has been inactive for too long
            if ($lastActivity->diffInSeconds(now()) >= $inactivityTimeout) {
                $attempts = $gameProgress['attempts'] ?? 0;
                $timeTaken = $gameProgress['current_time'] ?? 0;
                
                // Mark as finished with timeout
                $score = max(0, 10 - ($attempts * 2)); // Very small score for timeout
                $participant->markFinished($score);
                
                $participant->updateProgress([
                    'attempts' => $attempts,
                    'guesses' => $gameProgress['guesses'] ?? [],
                    'is_solved' => false,
                    'final_score' => $score,
                    'completion_time' => $timeTaken,
                    'finished_at' => now()->toISOString(),
                    'timed_out' => true
                ]);

                Log::info("Player timed out due to inactivity", [
                    'room_code' => $room->room_code,
                    'user_id' => $participant->user_id,
                    'attempts_made' => $attempts,
                    'final_score' => $score,
                    'inactive_time' => $lastActivity->diffInSeconds(now())
                ]);
            }
        }
        
        // Check if all participants have finished after timeout processing
        $this->checkGameCompletion($room);
    }

    private function formatTime(int $seconds): string
    {
        if ($seconds <= 0) return 'N/A';
        
        $minutes = floor($seconds / 60);
        $remainingSeconds = $seconds % 60;
        
        if ($minutes > 0) {
            return sprintf('%dm %ds', $minutes, $remainingSeconds);
        } else {
            return sprintf('%ds', $remainingSeconds);
        }
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MultiplayerRoom;
use App\Models\MultiplayerParticipant;
use App\Services\StrictBettingService;
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
        $room = MultiplayerRoom::with('game')->where('room_code', $roomCode)->first();

        if (!$room) {
            return response()->json([
                'success' => false,
                'message' => 'Room not found'
            ], 404);
        }

        if ($room->game->slug !== 'codebreaker') {
            return response()->json([
                'success' => false,
                'message' => 'This room is not for CodeBreaker'
            ], 400);
        }

        $participant = $room->participants()->where('user_id', Auth::id())->first();

        if (!$participant) {
            return response()->json([
                'success' => false,
                'message' => 'You are not in this room'
            ], 400);
        }

        if ($room->status !== 'starting' && $room->status !== 'in_progress') {
            return response()->json([
                'success' => false,
                'message' => 'Game is not ready to start'
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
        }

        // Initialize participant's game progress
        $participant->updateProgress([
            'attempts' => 0,
            'guesses' => [],
            'hints_used' => 0,
            'started_at' => now()->toISOString()
        ]);

        $totalPot = $room->participants()->sum('bet_amount');

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
                            'username' => $p->user->name,
                            'attempts' => $p->game_progress['attempts'] ?? 0,
                            'status' => $p->status,
                            'score' => $p->score,
                            'bet_amount' => $p->bet_amount
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

        // Calculate improved score based on attempts and time
        $score = 0;
        if ($isCorrect) {
            // Base score for solving
            $baseScore = 1000;
            
            // Bonus for fewer attempts (max 700 bonus, heavily weighted)
            $attemptBonus = max(0, (8 - $attempts) * 100);
            
            // Time bonus (max 500 bonus for solving under 30 seconds, more generous for fast solvers)
            $timeBonus = 0;
            if ($timeTaken > 0) {
                if ($timeTaken <= 30) {
                    $timeBonus = 500; // Full bonus for very fast solvers
                } elseif ($timeTaken <= 60) {
                    $timeBonus = 400 - (($timeTaken - 30) * 10); // Linear decrease from 400 to 100
                } elseif ($timeTaken <= 120) {
                    $timeBonus = 100 - (($timeTaken - 60) * 1.67); // Linear decrease from 100 to 0
                } else {
                    $timeBonus = 0; // No time bonus for slow solvers
                }
            }
            
            // Additional multiplier for perfect games (1 attempt)
            $perfectBonus = ($attempts === 1) ? 300 : 0;
            
            $score = $baseScore + $attemptBonus + $timeBonus + $perfectBonus;
            $score = round($score);
            
            $participant->markFinished($score);
            
            // Store completion time
            $participant->updateProgress([
                'attempts' => $attempts,
                'guesses' => $guesses,
                'is_solved' => true,
                'final_score' => $score,
                'completion_time' => $timeTaken,
                'finished_at' => now()->toISOString()
            ]);
        } elseif ($attempts >= 7) {
            // Out of attempts - small consolation score based on attempts made
            $score = max(0, 100 - ($attempts * 10) - ($timeTaken * 0.1));
            $score = round($score);
            
            $participant->markFinished($score);
            
            $participant->updateProgress([
                'attempts' => $attempts,
                'guesses' => $guesses,
                'is_solved' => false,
                'final_score' => $score,
                'completion_time' => $timeTaken,
                'finished_at' => now()->toISOString()
            ]);
        } else {
            // Update progress for ongoing game
            $participant->updateProgress([
                'attempts' => $attempts,
                'guesses' => $guesses,
                'current_time' => $timeTaken
            ]);
        }

        // Check if all participants have finished
        $this->checkGameCompletion($room);

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
        $room = MultiplayerRoom::with('participants.user:id,name')
            ->where('room_code', $roomCode)
            ->first();

        if (!$room) {
            return response()->json([
                'success' => false,
                'message' => 'Room not found'
            ], 404);
        }

        // Check for inactive players and auto-forfeit them after 5 minutes of inactivity
        if ($room->status === 'in_progress') {
            $this->checkForInactivePlayers($room);
        }

        // For completed games, calculate from the saved final_scores which preserves bet amounts
        if ($room->status === 'completed' && $room->final_scores) {
            $totalPot = collect($room->final_scores)->sum('bet_amount');
        } else {
            // For active games, use total_bet_pool if available, otherwise sum bet_amount
            $totalPot = $room->total_bet_pool ?? $room->participants()->sum('bet_amount');
        }

        return response()->json([
            'success' => true,
            'data' => [
                'room_status' => $room->status,
                'game_state' => $room->game_state,
                'leaderboard' => $this->getRoomLeaderboard($room),
                'your_progress' => $this->getParticipantProgress($room, Auth::id()),
                'shared_hints' => $room->game_state['hints'] ?? [],
                'hints_remaining' => 2 - ($room->game_state['hints_used'] ?? 0),
                'total_pot' => $totalPot
            ]
        ]);
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
                    'username' => $participant->user->name,
                    'score' => $participant->score,
                    'attempts' => $progress['attempts'] ?? 0,
                    'status' => $participant->status,
                    'is_solved' => $progress['is_solved'] ?? false,
                    'bet_amount' => $participant->bet_amount,
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

    private function checkGameCompletion(MultiplayerRoom $room): void
    {
        $totalParticipants = $room->participants()->count();
        $finishedParticipants = $room->participants()->where('status', 'finished')->count();

        if ($finishedParticipants < $totalParticipants) {
            return; // Game not over yet
        }

        // Use a transaction to ensure all database changes are atomic
        DB::transaction(function () use ($room) {
            // Eager load fresh data for participants and their users
            $participants = $room->participants()->with('user')->get();
            
            if ($participants->isEmpty()) {
                Log::warning("checkGameCompletion triggered for a room with no participants.", ['room_code' => $room->room_code]);
                $room->update(['status' => 'completed']);
                return;
            }

            // Sort participants by score (descending), then by attempts (ascending), then by completion time (ascending)
            $participants = $participants->sortBy([
                ['score', 'desc'],
                function ($participant) {
                    $progress = $participant->game_progress ?? [];
                    return $progress['attempts'] ?? 999; // Lower attempts = better rank
                },
                function ($participant) {
                    $progress = $participant->game_progress ?? [];
                    return $progress['completion_time'] ?? 999999; // Faster time = better rank
                }
            ])->values();

            $winnerParticipant = $participants->first();
            $winnerUser = $winnerParticipant->user;

            $originalBetAmounts = [];
            $isBettingGame = false;
            foreach ($participants as $p) {
                // Check both bet_amount and user's locked_bet_tokens to determine betting
                $betAmount = $p->bet_amount ?? 0;
                $lockedTokens = $p->user->locked_bet_tokens ?? 0;
                
                // Use the higher of the two as the actual bet amount
                $actualBetAmount = max($betAmount, $lockedTokens);
                
                if ($actualBetAmount > 0) {
                    $isBettingGame = true;
                }
                $originalBetAmounts[$p->user_id] = $actualBetAmount;
            }

            $totalWinnings = 0;
            
            // --- Betting Logic ---
            if ($isBettingGame) {
                $totalWinnings = array_sum($originalBetAmounts);

                // 1. Award winner the total pot
                if ($totalWinnings > 0) {
                    $winnerUser->awardTokens(
                        $totalWinnings,
                        'prize',
                        "Multiplayer winner from room {$room->room_code}"
                    );
                    Log::info("Awarded winnings.", ['user_id' => $winnerUser->id, 'amount' => $totalWinnings, 'room' => $room->room_code]);
                }

                // 2. Clear locked tokens for ALL participants and log transactions for losers
                foreach ($participants as $p) {
                    $user = $p->user;
                    $betAmount = $originalBetAmounts[$user->id] ?? 0;

                    if ($user->id !== $winnerUser->id && $betAmount > 0) {
                        // Deduct tokens from loser's balance and create transaction log
                        $user->decrement('tokens_balance', $betAmount);
                        $user->transactions()->create([
                            'amount' => -$betAmount,
                            'type' => 'powerup',
                            'description' => "Multiplayer loss in room {$room->room_code}",
                            'meta' => ['room_code' => $room->room_code, 'bet_loss' => true],
                            'status' => 'completed',
                        ]);
                        Log::info("Deducted tokens and logged loss transaction.", ['user_id' => $user->id, 'amount' => -$betAmount, 'room' => $room->room_code]);
                    }
                    
                    // Crucially, clear the lock.
                    if ($user->locked_bet_tokens > 0) {
                        $user->update(['locked_bet_tokens' => 0]);
                    }
                    if ($p->locked_tokens > 0) {
                        $p->update(['locked_tokens' => 0]);
                    }
                }
            }

            // --- Non-Betting Rewards ---
            $tokensAwarded = min(5, $winnerParticipant->score / 100); // Cap at 5 tokens
            if ($tokensAwarded > 0 && !$isBettingGame) { // Only give score rewards in non-betting games
                $winnerUser->awardTokens($tokensAwarded, 'prize', "Multiplayer {$room->game->title} Winner");
            }

            // --- Final Scores Array with proper ranking ---
            $finalScores = $participants->map(function ($participant, $index) use ($winnerUser, $originalBetAmounts, $totalWinnings, $isBettingGame, $tokensAwarded) {
                $userId = $participant->user_id;
                $progress = $participant->game_progress ?? [];
                $betAmount = $originalBetAmounts[$userId] ?? 0;
                $tokensChange = 0;

                if ($isBettingGame) {
                    if ($userId === $winnerUser->id) {
                        $tokensChange = $totalWinnings;
                    } else {
                        $tokensChange = -$betAmount;
                    }
                } elseif ($userId === $winnerUser->id) {
                    $tokensChange = $tokensAwarded;
                }

                return [
                    'user_id' => $userId,
                    'username' => $participant->user->name,
                    'score' => $participant->score,
                    'rank' => $index + 1,
                    'attempts' => $progress['attempts'] ?? 0,
                    'is_solved' => $progress['is_solved'] ?? false,
                    'time_taken' => $this->formatTime($progress['completion_time'] ?? 0),
                    'bet_amount' => $betAmount,
                    'tokens_change' => $tokensChange,
                    'finished_at' => $participant->finished_at?->toISOString()
                ];
            });

            // --- FAILSAFE: Always clear locked tokens for all participants ---
            foreach ($participants as $p) {
                $user = $p->user;
                if ($user->locked_bet_tokens > 0) {
                    Log::info("Failsafe: Clearing locked tokens for user", [
                        'user_id' => $user->id,
                        'locked_amount' => $user->locked_bet_tokens,
                        'room_code' => $room->room_code
                    ]);
                    $user->update(['locked_bet_tokens' => 0]);
                }
                if ($p->locked_tokens > 0) {
                    $p->update(['locked_tokens' => 0]);
                }
            }

            // --- Final Room Update ---
            $room->update([
                'status' => 'completed',
                'winner_user_id' => $winnerUser->id,
                'final_scores' => $finalScores->toArray(),
                'completed_at' => now(),
                'has_active_bets' => false, // Now it's safe to clear
                'total_bet_pool' => 0
            ]);

            Log::info("CodeBreaker game completed definitively.", [
                'room_code' => $room->room_code,
                'winner_id' => $winnerUser->id,
                'winner_score' => $winnerParticipant->score,
                'total_participants' => $participants->count()
            ]);
        });
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

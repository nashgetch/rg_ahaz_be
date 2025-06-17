<?php

namespace App\Services;

use App\Models\User;
use App\Models\PenaltyLog;
use App\Models\MultiplayerRoom;
use App\Models\MultiplayerParticipant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StrictBettingService
{
    const ABANDONMENT_PENALTY_RATE = 0.20; // 20% penalty
    const MINIMUM_COMPLETION_TIME = 30; // 30 seconds minimum to prevent instant completion cheating

    /**
     * Lock tokens for all participants when bet is accepted
     */
    public function lockBetTokens(MultiplayerRoom $room, float $betAmount): void
    {
        DB::transaction(function () use ($room, $betAmount) {
            foreach ($room->participants as $participant) {
                // Lock the bet amount
                $participant->user->lockTokensForBet($betAmount);
                
                // Update participant record
                $participant->update([
                    'locked_tokens' => $betAmount
                ]);
            }

            // Update room with bet info
            $room->update([
                'has_active_bets' => true,
                'total_bet_pool' => $betAmount * $room->participants->count()
            ]);
        });

        Log::info("Locked bet tokens for room {$room->room_code}", [
            'bet_amount' => $betAmount,
            'participants' => $room->participants->count(),
            'total_pool' => $betAmount * $room->participants->count()
        ]);
    }

    /**
     * Handle game completion with strict enforcement
     */
    public function handleGameCompletion(MultiplayerRoom $room, MultiplayerParticipant $participant, int $finalScore): array
    {
        $result = [
            'completed' => false,
            'winner' => null,
            'penalties_applied' => [],
            'reimbursements' => [],
            'total_winnings' => 0
        ];

        DB::transaction(function () use ($room, $participant, $finalScore, &$result) {
            // Mark participant as completed
            $participant->update([
                'completed_game' => true,
                'score' => $finalScore,
                'finished_at' => now()
            ]);

            // Check if this is a valid completion (anti-cheating)
            $this->validateGameCompletion($participant);

            // Check if all participants have finished or abandoned
            $allParticipants = $room->participants;
            $completedCount = $allParticipants->where('completed_game', true)->count();
            $abandonedCount = $allParticipants->where('abandoned_game', true)->count();
            $totalCount = $allParticipants->count();

            if (($completedCount + $abandonedCount) >= $totalCount) {
                // Game is finished, process results
                $result = $this->processGameResults($room);
            }
        });

        return $result;
    }

    /**
     * Handle player abandonment with penalties
     */
    public function handlePlayerAbandonment(MultiplayerRoom $room, MultiplayerParticipant $participant, string $reason = 'left_room'): array
    {
        $result = [
            'penalty_applied' => false,
            'penalty_amount' => 0,
            'tokens_forfeited' => 0,
            'remaining_players_rewarded' => false
        ];

        DB::transaction(function () use ($room, $participant, $reason, &$result) {
            // Mark as abandoned
            $participant->update([
                'abandoned_game' => true,
                'abandoned_at' => now()
            ]);

            // Add to room's abandonment log
            $abandonmentLog = $room->abandonment_log ?? [];
            $abandonmentLog[] = [
                'user_id' => $participant->user_id,
                'username' => $participant->user->name,
                'reason' => $reason,
                'abandoned_at' => now()->toISOString(),
                'bet_amount' => $participant->bet_amount ?? 0
            ];
            
            $room->update(['abandonment_log' => $abandonmentLog]);

            if ($room->has_active_bets && $participant->locked_tokens > 0) {
                // Apply abandonment penalty
                $penaltyAmount = $participant->locked_tokens * self::ABANDONMENT_PENALTY_RATE;
                $forfeitedAmount = $participant->locked_tokens;

                // Apply penalty to user
                $participant->user->applyPenalty(
                    PenaltyLog::VIOLATION_ABANDONMENT,
                    "Abandoned multiplayer game with active bet in room {$room->room_code}",
                    [
                        'room_code' => $room->room_code,
                        'bet_amount' => $participant->locked_tokens,
                        'penalty_rate' => self::ABANDONMENT_PENALTY_RATE,
                        'reason' => $reason
                    ],
                    $penaltyAmount
                );

                // Update participant record
                $participant->update([
                    'penalty_amount' => $penaltyAmount
                ]);

                // Unlock the tokens (they're already spent as penalty)
                $participant->user->unlockTokensFromBet($participant->locked_tokens);
                $participant->update(['locked_tokens' => 0]);

                $result['penalty_applied'] = true;
                $result['penalty_amount'] = $penaltyAmount;
                $result['tokens_forfeited'] = $forfeitedAmount;

                Log::warning("Applied abandonment penalty", [
                    'user_id' => $participant->user_id,
                    'room_code' => $room->room_code,
                    'penalty_amount' => $penaltyAmount,
                    'reason' => $reason
                ]);
            }

            // Check if we should process early completion for remaining players
            $this->checkEarlyCompletion($room);
        });

        return $result;
    }

    /**
     * Process final game results with strict enforcement
     */
    private function processGameResults(MultiplayerRoom $room): array
    {
        $result = [
            'completed' => true,
            'winner' => null,
            'penalties_applied' => [],
            'reimbursements' => [],
            'total_winnings' => 0
        ];

        $participants = $room->participants;
        $completedParticipants = $participants->where('completed_game', true);
        $abandonedParticipants = $participants->where('abandoned_game', true);

        Log::info("Processing game results for room {$room->room_code}", [
            'room_code' => $room->room_code,
            'has_active_bets' => $room->has_active_bets,
            'total_bet_pool' => $room->total_bet_pool,
            'completed_count' => $completedParticipants->count(),
            'abandoned_count' => $abandonedParticipants->count(),
            'total_participants' => $participants->count()
        ]);

        if ($room->has_active_bets) {
            if ($completedParticipants->count() === 0) {
                // All players abandoned - apply penalties and no winner
                Log::info("All players abandoned in room {$room->room_code}");
                $result = $this->handleAllPlayersAbandoned($room, $participants);
            } elseif ($completedParticipants->count() === 1) {
                // One winner takes all
                $winner = $completedParticipants->first();
                Log::info("Winner takes all in room {$room->room_code}", [
                    'winner_id' => $winner->user_id,
                    'winner_name' => $winner->user->name
                ]);
                $result = $this->awardWinnerTakesAll($room, $winner, $abandonedParticipants);
            } else {
                // Multiple completions - determine winner by score and time
                $winner = $completedParticipants
                    ->sortByDesc('score')
                    ->sortBy('finished_at')
                    ->first();
                
                Log::info("Competitive winner in room {$room->room_code}", [
                    'winner_id' => $winner->user_id,
                    'winner_name' => $winner->user->name,
                    'winner_score' => $winner->score
                ]);
                $result = $this->awardCompetitiveWinnings($room, $winner, $completedParticipants, $abandonedParticipants);
            }
            
            // Force unlock all remaining tokens after distributing winnings
            $this->unlockAllRemainingTokens($room);
            
            // Double check that all tokens are unlocked
            foreach ($participants as $participant) {
                if ($participant->user->locked_bet_tokens > 0) {
                    Log::warning("Found remaining locked tokens after unlock attempt", [
                        'room_code' => $room->room_code,
                        'user_id' => $participant->user_id,
                        'locked_amount' => $participant->user->locked_bet_tokens
                    ]);
                    // Force unlock one more time
                    $participant->user->update(['locked_bet_tokens' => 0]);
                }
            }
        }

        // Mark room as completed (preserve total_bet_pool for display)
        $room->update([
            'status' => 'completed',
            'completed_at' => now()
            // Keep has_active_bets and total_bet_pool for display purposes
        ]);

        Log::info("Game results processed for room {$room->room_code}", [
            'winner_id' => $result['winner'] ? $result['winner']->user_id : null,
            'total_winnings' => $result['total_winnings'],
            'penalties_count' => count($result['penalties_applied']),
            'reimbursements_count' => count($result['reimbursements'])
        ]);

        return $result;
    }

    /**
     * Handle case where all players abandoned
     */
    private function handleAllPlayersAbandoned(MultiplayerRoom $room, $participants): array
    {
        $result = [
            'completed' => true,
            'winner' => null,
            'penalties_applied' => [],
            'reimbursements' => [],
            'total_winnings' => 0
        ];

        foreach ($participants as $participant) {
            if ($participant->locked_tokens > 0 && !$participant->penalty_amount) {
                // Apply 20% penalty and reimburse 80%
                $penaltyAmount = $participant->locked_tokens * self::ABANDONMENT_PENALTY_RATE;
                $reimbursementAmount = $participant->locked_tokens - $penaltyAmount;

                // Apply penalty
                $participant->user->applyPenalty(
                    PenaltyLog::VIOLATION_ABANDONMENT,
                    "All players abandoned game with bets in room {$room->room_code}",
                    ['room_code' => $room->room_code, 'bet_amount' => $participant->locked_tokens],
                    $penaltyAmount
                );

                // Reimburse remaining amount
                if ($reimbursementAmount > 0) {
                    $participant->user->awardTokens(
                        $reimbursementAmount,
                        'bet_reimbursement',
                        "Bet reimbursement from abandoned game {$room->room_code}"
                    );
                }

                $participant->update([
                    'penalty_amount' => $penaltyAmount,
                    'reimbursement_amount' => $reimbursementAmount
                ]);

                $result['penalties_applied'][] = [
                    'user_id' => $participant->user_id,
                    'penalty_amount' => $penaltyAmount
                ];

                $result['reimbursements'][] = [
                    'user_id' => $participant->user_id,
                    'reimbursement_amount' => $reimbursementAmount
                ];
            }
        }

        return $result;
    }

    /**
     * Award winner takes all scenario
     */
    private function awardWinnerTakesAll(MultiplayerRoom $room, MultiplayerParticipant $winner, $abandonedParticipants): array
    {
        // Use the total bet pool as winnings
        $totalWinnings = $room->total_bet_pool;

        // Award to winner
        if ($totalWinnings > 0) {
            $winner->user->awardTokens(
                $totalWinnings,
                'bet_winnings',
                "Winner takes all from room {$room->room_code}"
            );
            
            Log::info("Awarded winner takes all winnings", [
                'room_code' => $room->room_code,
                'winner_id' => $winner->user_id,
                'winner_name' => $winner->user->name,
                'total_winnings' => $totalWinnings,
                'previous_locked_tokens' => $winner->locked_tokens,
                'user_locked_bet_tokens' => $winner->user->locked_bet_tokens
            ]);
        }

        return [
            'completed' => true,
            'winner' => $winner,
            'total_winnings' => $totalWinnings,
            'penalties_applied' => [],
            'reimbursements' => []
        ];
    }

    /**
     * Award competitive winnings when multiple players complete
     */
    private function awardCompetitiveWinnings(MultiplayerRoom $room, MultiplayerParticipant $winner, $completedParticipants, $abandonedParticipants): array
    {
        $totalPool = $room->total_bet_pool;

        // Winner gets the entire pool
        if ($totalPool > 0) {
            $winner->user->awardTokens(
                $totalPool,
                'bet_winnings',
                "Multiplayer winner from room {$room->room_code}"
            );
            
            Log::info("Awarded competitive winnings", [
                'room_code' => $room->room_code,
                'winner_id' => $winner->user_id,
                'winner_name' => $winner->user->name,
                'total_winnings' => $totalPool,
                'previous_locked_tokens' => $winner->locked_tokens,
                'user_locked_bet_tokens' => $winner->user->locked_bet_tokens,
                'completed_participants' => $completedParticipants->count(),
                'abandoned_participants' => $abandonedParticipants->count()
            ]);
        }

        return [
            'completed' => true,
            'winner' => $winner,
            'total_winnings' => $totalPool,
            'penalties_applied' => [],
            'reimbursements' => []
        ];
    }

    /**
     * Validate game completion for anti-cheating
     */
    private function validateGameCompletion(MultiplayerParticipant $participant): void
    {
        $room = $participant->room;
        $gameStartTime = $room->updated_at; // When game status changed to 'in_progress'
        $completionTime = now();
        $timeTaken = $gameStartTime->diffInSeconds($completionTime);

        // Check minimum time to prevent instant completion cheating
        if ($timeTaken < self::MINIMUM_COMPLETION_TIME) {
            $participant->user->applyPenalty(
                PenaltyLog::VIOLATION_CHEATING,
                "Suspiciously fast game completion in room {$room->room_code}",
                [
                    'room_code' => $room->room_code,
                    'completion_time_seconds' => $timeTaken,
                    'minimum_required' => self::MINIMUM_COMPLETION_TIME,
                    'score' => $participant->score
                ]
            );

            Log::warning("Suspicious fast completion detected", [
                'user_id' => $participant->user_id,
                'room_code' => $room->room_code,
                'time_taken' => $timeTaken,
                'score' => $participant->score
            ]);
        }
    }

    /**
     * Check if remaining players should get early completion rewards
     */
    private function checkEarlyCompletion(MultiplayerRoom $room): void
    {
        $participants = $room->participants;
        $activeParticipants = $participants->where('abandoned_game', false)->where('completed_game', false);
        $abandonedParticipants = $participants->where('abandoned_game', true);

        // If only one active player remains and there were abandonments with bets
        if ($activeParticipants->count() === 1 && $abandonedParticipants->count() > 0 && $room->has_active_bets) {
            $lastPlayer = $activeParticipants->first();
            
            // Award abandoned bet amounts to the last remaining player
            $totalAbandonedBets = $abandonedParticipants->sum('locked_tokens');
            
            if ($totalAbandonedBets > 0) {
                $lastPlayer->user->awardTokens(
                    $totalAbandonedBets,
                    'abandonment_reward',
                    "Reward for staying in game while others abandoned - room {$room->room_code}"
                );

                Log::info("Awarded abandonment reward to last remaining player", [
                    'user_id' => $lastPlayer->user_id,
                    'room_code' => $room->room_code,
                    'reward_amount' => $totalAbandonedBets
                ]);
            }
        }
    }

    /**
     * Unlock all remaining locked tokens
     */
    private function unlockAllRemainingTokens(MultiplayerRoom $room): void
    {
        $unlockedCount = 0;
        $totalUnlocked = 0;
        
        foreach ($room->participants as $participant) {
            // Force unlock any remaining locked tokens on the user
            $user = $participant->user;
            if ($user->locked_bet_tokens > 0) {
                $amount = $user->locked_bet_tokens;
                $user->update(['locked_bet_tokens' => 0]);
                
                $unlockedCount++;
                $totalUnlocked += $amount;
                
                Log::info("Force unlocked remaining tokens for participant", [
                    'room_code' => $room->room_code,
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'amount_unlocked' => $amount,
                    'previous_locked_tokens' => $participant->locked_tokens
                ]);
            }
            
            // Also ensure participant's locked_tokens is cleared
            if ($participant->locked_tokens > 0) {
                $participant->update(['locked_tokens' => 0]);
            }
        }
        
        if ($unlockedCount > 0) {
            Log::info("Finished force unlocking remaining tokens for room {$room->room_code}", [
                'participants_unlocked' => $unlockedCount,
                'total_amount_unlocked' => $totalUnlocked
            ]);
        }
    }

    /**
     * Check if user can start another game (prevent multiple active bets)
     */
    public function canUserStartNewGame(User $user): array
    {
        // Check if user is suspended
        if ($user->isSuspended()) {
            return [
                'can_play' => false,
                'reason' => 'suspended',
                'message' => 'Your account is suspended due to violations.',
                'expires_at' => $user->penalty_expires_at
            ];
        }

        // Check if user has locked tokens in other games
        if ($user->locked_bet_tokens > 0) {
            return [
                'can_play' => false,
                'reason' => 'active_bets',
                'message' => 'You have active bets in other games. Complete or abandon those games first.',
                'locked_amount' => $user->locked_bet_tokens
            ];
        }

        return [
            'can_play' => true,
            'reason' => null,
            'message' => 'You can start a new game.'
        ];
    }

    /**
     * Set game duration based on selection
     */
    public function setGameDuration(MultiplayerRoom $room, string $durationType): void
    {
        $durationMinutes = $durationType === 'rush' ? 3 : 5;
        
        $room->update([
            'game_duration' => $durationType,
            'duration_minutes' => $durationMinutes
        ]);
    }
}

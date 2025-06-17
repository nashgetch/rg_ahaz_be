<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\MultiplayerRoom;
use App\Models\MultiplayerParticipant;

class CleanupStuckBetTokens extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'betting:cleanup-stuck-tokens {--dry-run : Show what would be cleaned up without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up stuck bet tokens from completed or abandoned games';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');
        
        if ($dryRun) {
            $this->info('DRY RUN MODE - No changes will be made');
        }

        // Find users with locked tokens
        $usersWithLockedTokens = User::where('locked_bet_tokens', '>', 0)->get();
        
        if ($usersWithLockedTokens->isEmpty()) {
            $this->info('No users found with locked bet tokens.');
            return;
        }

        $this->info("Found {$usersWithLockedTokens->count()} users with locked bet tokens:");

        foreach ($usersWithLockedTokens as $user) {
            $this->line("- {$user->name}: {$user->locked_bet_tokens} tokens");
            
            // Check if user has any active bet rooms
            $activeRooms = MultiplayerRoom::where('has_active_bets', true)
                ->whereHas('participants', function ($query) use ($user) {
                    $query->where('user_id', $user->id);
                })
                ->count();

            if ($activeRooms > 0) {
                $this->warn("  User has {$activeRooms} active bet rooms - skipping");
                continue;
            }

            // Check for participant records with locked tokens
            $participantTokens = MultiplayerParticipant::where('user_id', $user->id)
                ->where('locked_tokens', '>', 0)
                ->whereHas('room', function ($query) {
                    $query->whereIn('status', ['completed', 'cancelled']);
                })
                ->get();

            if ($participantTokens->isNotEmpty()) {
                $this->line("  Found {$participantTokens->count()} completed participant records with locked tokens");
                
                if (!$dryRun) {
                    foreach ($participantTokens as $participant) {
                        $participant->update(['locked_tokens' => 0]);
                    }
                }
            }

            // Unlock the user's tokens
            if (!$dryRun) {
                $user->unlockTokensFromBet($user->locked_bet_tokens);
                $this->info("  ✓ Unlocked {$user->locked_bet_tokens} tokens for {$user->name}");
            } else {
                $this->info("  Would unlock {$user->locked_bet_tokens} tokens for {$user->name}");
            }
        }

        // Check for rooms with active bets but completed status
        $stuckRooms = MultiplayerRoom::where('has_active_bets', true)
            ->where('status', 'completed')
            ->get();

        if ($stuckRooms->isNotEmpty()) {
            $this->info("Found {$stuckRooms->count()} completed rooms still marked as having active bets:");
            
            foreach ($stuckRooms as $room) {
                $this->line("- Room {$room->room_code}: Pool {$room->total_bet_pool}");
                
                if (!$dryRun) {
                    $room->update(['has_active_bets' => false]);
                    $this->info("  ✓ Cleared active bets flag for room {$room->room_code}");
                } else {
                    $this->info("  Would clear active bets flag for room {$room->room_code}");
                }
            }
        }

        if ($dryRun) {
            $this->info('DRY RUN COMPLETE - Run without --dry-run to apply changes');
        } else {
            $this->info('Cleanup completed successfully!');
        }
    }
}

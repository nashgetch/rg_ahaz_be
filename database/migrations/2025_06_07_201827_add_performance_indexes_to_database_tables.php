<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add indexes to users table for better performance
        Schema::table('users', function (Blueprint $table) {
            // Composite index for user authentication and stats queries
            $table->index(['phone', 'last_login_at'], 'users_phone_last_login_idx');
            
            // Composite index for leaderboard queries by level and experience
            $table->index(['level', 'experience'], 'users_level_experience_idx');
            
            // Index for user ranking by total score across games (level + tokens as proxy)
            $table->index(['level', 'tokens_balance'], 'users_level_tokens_idx');
            
            // Index for daily bonus queries
            $table->index(['daily_bonus_claimed_at'], 'users_daily_bonus_idx');
            
            // Index for user creation analytics
            $table->index(['created_at', 'locale'], 'users_created_locale_idx');
        });

        // Add indexes to rounds table for better performance
        Schema::table('rounds', function (Blueprint $table) {
            // Composite index for recent scores and analytics
            $table->index(['user_id', 'game_id', 'completed_at'], 'rounds_user_game_completed_idx');
            
            // Composite index for best score calculations
            $table->index(['game_id', 'user_id', 'score'], 'rounds_game_user_score_idx');
            
            // Index for game analytics and recent activity
            $table->index(['game_id', 'completed_at', 'status'], 'rounds_game_completed_status_idx');
            
            // Index for user activity tracking
            $table->index(['user_id', 'started_at', 'status'], 'rounds_user_started_status_idx');
            
            // Index for duration analysis
            $table->index(['game_id', 'duration_ms'], 'rounds_game_duration_idx');
            
            // Index for score verification and anti-cheat
            $table->index(['score_hash', 'status'], 'rounds_hash_status_idx');
        });

        // Add indexes to leaderboards table (enhancing existing ones)
        Schema::table('leaderboards', function (Blueprint $table) {
            // Composite index for user profile stats
            $table->index(['user_id', 'best_score'], 'leaderboards_user_score_idx');
            
            // Composite index for recent activity across games
            $table->index(['user_id', 'last_played_at'], 'leaderboards_user_activity_idx');
            
            // Composite index for game popularity analytics
            $table->index(['game_id', 'total_plays'], 'leaderboards_game_plays_idx');
            
            // Composite index for rank updates and calculations
            $table->index(['game_id', 'rank_global', 'best_score'], 'leaderboards_game_rank_score_idx');
            
            // Index for daily leaderboard updates
            $table->index(['rank_daily', 'last_played_at'], 'leaderboards_daily_activity_idx');
            
            // Index for weekly leaderboard updates
            $table->index(['rank_weekly', 'last_played_at'], 'leaderboards_weekly_activity_idx');
        });

        // Add indexes to transactions table for better performance
        Schema::table('transactions', function (Blueprint $table) {
            // Composite index for user transaction history
            $table->index(['user_id', 'type', 'created_at'], 'transactions_user_type_date_idx');
            
            // Composite index for transaction reporting
            $table->index(['type', 'status', 'created_at'], 'transactions_type_status_date_idx');
            
            // Index for transaction amounts (analytics)
            $table->index(['amount', 'type'], 'transactions_amount_type_idx');
            
            // Index for pending transactions processing
            $table->index(['status', 'created_at'], 'transactions_status_date_idx');
            
            // Index for external payment reconciliation
            $table->index(['reference', 'status'], 'transactions_reference_status_idx');
        });

        // Add indexes to otps table for better performance
        Schema::table('otps', function (Blueprint $table) {
            // Composite index for OTP validation (most common query)
            $table->index(['phone', 'code', 'expires_at', 'consumed_at'], 'otps_validation_idx');
            
            // Index for cleanup of expired OTPs
            $table->index(['expires_at', 'consumed_at'], 'otps_cleanup_idx');
            
            // Index for rate limiting and security
            $table->index(['ip_address', 'created_at'], 'otps_ip_rate_limit_idx');
            
            // Index for OTP attempt tracking
            $table->index(['phone', 'attempts', 'created_at'], 'otps_attempts_idx');
        });

        // Add indexes to games table if it exists
        if (Schema::hasTable('games')) {
            Schema::table('games', function (Blueprint $table) {
                // Index for active games filtering
                if (Schema::hasColumn('games', 'is_active')) {
                    $table->index(['is_active', 'created_at'], 'games_active_date_idx');
                }
                
                // Index for game categories if exists
                if (Schema::hasColumn('games', 'category')) {
                    $table->index(['category', 'is_active'], 'games_category_active_idx');
                }
            });
        }

        // Add indexes to personal_access_tokens table for API performance
        if (Schema::hasTable('personal_access_tokens')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                // Composite index for token validation
                if (!Schema::hasIndex('personal_access_tokens', 'personal_access_tokens_token_validation_idx')) {
                    $table->index(['tokenable_id', 'tokenable_type', 'expires_at'], 'personal_access_tokens_token_validation_idx');
                }
                
                // Index for token cleanup
                if (!Schema::hasIndex('personal_access_tokens', 'personal_access_tokens_expires_idx')) {
                    $table->index(['expires_at'], 'personal_access_tokens_expires_idx');
                }
            });
        }

        // Add indexes to sessions table for better session management
        if (Schema::hasTable('sessions')) {
            Schema::table('sessions', function (Blueprint $table) {
                // Composite index for active session tracking
                if (!Schema::hasIndex('sessions', 'sessions_user_activity_idx')) {
                    $table->index(['user_id', 'last_activity'], 'sessions_user_activity_idx');
                }
                
                // Index for session cleanup
                if (!Schema::hasIndex('sessions', 'sessions_last_activity_idx')) {
                    $table->index(['last_activity'], 'sessions_last_activity_idx');
                }
            });
        }

        // Add indexes to cache table for better caching performance
        if (Schema::hasTable('cache')) {
            Schema::table('cache', function (Blueprint $table) {
                // Index for cache expiration cleanup
                if (Schema::hasColumn('cache', 'expiration') && !Schema::hasIndex('cache', 'cache_expiration_idx')) {
                    $table->index(['expiration'], 'cache_expiration_idx');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop indexes from users table
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_phone_last_login_idx');
            $table->dropIndex('users_level_experience_idx');
            $table->dropIndex('users_level_tokens_idx');
            $table->dropIndex('users_daily_bonus_idx');
            $table->dropIndex('users_created_locale_idx');
        });

        // Drop indexes from rounds table
        Schema::table('rounds', function (Blueprint $table) {
            $table->dropIndex('rounds_user_game_completed_idx');
            $table->dropIndex('rounds_game_user_score_idx');
            $table->dropIndex('rounds_game_completed_status_idx');
            $table->dropIndex('rounds_user_started_status_idx');
            $table->dropIndex('rounds_game_duration_idx');
            $table->dropIndex('rounds_hash_status_idx');
        });

        // Drop indexes from leaderboards table
        Schema::table('leaderboards', function (Blueprint $table) {
            $table->dropIndex('leaderboards_user_score_idx');
            $table->dropIndex('leaderboards_user_activity_idx');
            $table->dropIndex('leaderboards_game_plays_idx');
            $table->dropIndex('leaderboards_game_rank_score_idx');
            $table->dropIndex('leaderboards_daily_activity_idx');
            $table->dropIndex('leaderboards_weekly_activity_idx');
        });

        // Drop indexes from transactions table
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('transactions_user_type_date_idx');
            $table->dropIndex('transactions_type_status_date_idx');
            $table->dropIndex('transactions_amount_type_idx');
            $table->dropIndex('transactions_status_date_idx');
            $table->dropIndex('transactions_reference_status_idx');
        });

        // Drop indexes from otps table
        Schema::table('otps', function (Blueprint $table) {
            $table->dropIndex('otps_validation_idx');
            $table->dropIndex('otps_cleanup_idx');
            $table->dropIndex('otps_ip_rate_limit_idx');
            $table->dropIndex('otps_attempts_idx');
        });

        // Drop indexes from games table if it exists
        if (Schema::hasTable('games')) {
            Schema::table('games', function (Blueprint $table) {
                if (Schema::hasIndex('games', 'games_active_date_idx')) {
                    $table->dropIndex('games_active_date_idx');
                }
                if (Schema::hasIndex('games', 'games_category_active_idx')) {
                    $table->dropIndex('games_category_active_idx');
                }
            });
        }

        // Drop indexes from personal_access_tokens table
        if (Schema::hasTable('personal_access_tokens')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                if (Schema::hasIndex('personal_access_tokens', 'personal_access_tokens_token_validation_idx')) {
                    $table->dropIndex('personal_access_tokens_token_validation_idx');
                }
                if (Schema::hasIndex('personal_access_tokens', 'personal_access_tokens_expires_idx')) {
                    $table->dropIndex('personal_access_tokens_expires_idx');
                }
            });
        }

        // Drop indexes from sessions table
        if (Schema::hasTable('sessions')) {
            Schema::table('sessions', function (Blueprint $table) {
                if (Schema::hasIndex('sessions', 'sessions_user_activity_idx')) {
                    $table->dropIndex('sessions_user_activity_idx');
                }
                if (Schema::hasIndex('sessions', 'sessions_last_activity_idx')) {
                    $table->dropIndex('sessions_last_activity_idx');
                }
            });
        }

        // Drop indexes from cache table
        if (Schema::hasTable('cache')) {
            Schema::table('cache', function (Blueprint $table) {
                if (Schema::hasIndex('cache', 'cache_expiration_idx')) {
                    $table->dropIndex('cache_expiration_idx');
                }
            });
        }
    }
};

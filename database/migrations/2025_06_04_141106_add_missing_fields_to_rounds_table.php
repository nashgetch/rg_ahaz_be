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
        Schema::table('rounds', function (Blueprint $table) {
            // Game round specific fields
            $table->string('seed')->nullable()->after('user_id'); // Game seed for reproducibility
            $table->unsignedInteger('cost_tokens')->default(0)->after('seed'); // Tokens spent to start
            $table->unsignedInteger('completion_time')->nullable()->after('duration_ms'); // Time taken in seconds
            
            // Reward and progression tracking
            $table->unsignedInteger('reward_tokens')->default(0)->after('completion_time'); // Tokens earned
            $table->unsignedInteger('experience_gained')->default(0)->after('reward_tokens'); // XP earned
            
            // Anti-cheat and moderation
            $table->boolean('is_timeout')->default(false)->after('status'); // Round timed out
            $table->boolean('is_flagged')->default(false)->after('is_timeout'); // Flagged for review
            $table->string('flag_reason')->nullable()->after('is_flagged'); // Reason for flagging
            
            // Add indexes for performance
            $table->index(['user_id', 'reward_tokens']);
            $table->index(['game_id', 'is_flagged']);
            $table->index('seed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rounds', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'reward_tokens']);
            $table->dropIndex(['game_id', 'is_flagged']);
            $table->dropIndex(['seed']);
            
            $table->dropColumn([
                'seed',
                'cost_tokens', 
                'completion_time',
                'reward_tokens',
                'experience_gained',
                'is_timeout',
                'is_flagged',
                'flag_reason'
            ]);
        });
    }
};

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
        // Add fields to multiplayer_rooms table
        Schema::table('multiplayer_rooms', function (Blueprint $table) {
            $table->enum('game_duration', ['rush', 'normal'])->default('normal')->after('game_id');
            $table->integer('duration_minutes')->default(5)->after('game_duration'); // 3 for rush, 5 for normal
            $table->boolean('has_active_bets')->default(false)->after('duration_minutes');
            $table->decimal('total_bet_pool', 10, 2)->default(0)->after('has_active_bets');
            $table->json('abandonment_log')->nullable()->after('total_bet_pool'); // Track who abandoned
        });

        // Add fields to multiplayer_participants table
        Schema::table('multiplayer_participants', function (Blueprint $table) {
            $table->decimal('locked_tokens', 10, 2)->default(0)->after('bet_amount');
            $table->boolean('completed_game')->default(false)->after('locked_tokens');
            $table->boolean('abandoned_game')->default(false)->after('completed_game');
            $table->timestamp('abandoned_at')->nullable()->after('abandoned_game');
            $table->decimal('penalty_amount', 10, 2)->default(0)->after('abandoned_at');
            $table->decimal('reimbursement_amount', 10, 2)->default(0)->after('penalty_amount');
        });

        // Add fields to users table for penalty tracking
        Schema::table('users', function (Blueprint $table) {
            $table->integer('penalty_points')->default(0)->after('experience');
            $table->timestamp('penalty_expires_at')->nullable()->after('penalty_points');
            $table->boolean('is_suspended')->default(false)->after('penalty_expires_at');
            $table->decimal('locked_bet_tokens', 10, 2)->default(0)->after('is_suspended'); // Tokens locked in active bets
        });

        // Create penalty_logs table for tracking violations
        Schema::create('penalty_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('violation_type'); // 'abandonment', 'cheating', 'multiple_games'
            $table->text('description');
            $table->integer('penalty_points');
            $table->decimal('token_penalty', 10, 2)->default(0);
            $table->json('metadata')->nullable(); // Additional context
            $table->timestamps();
            
            $table->index(['user_id', 'violation_type']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('penalty_logs');
        
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'penalty_points',
                'penalty_expires_at', 
                'is_suspended',
                'locked_bet_tokens'
            ]);
        });

        Schema::table('multiplayer_participants', function (Blueprint $table) {
            $table->dropColumn([
                'locked_tokens',
                'completed_game',
                'abandoned_game',
                'abandoned_at',
                'penalty_amount',
                'reimbursement_amount'
            ]);
        });

        Schema::table('multiplayer_rooms', function (Blueprint $table) {
            $table->dropColumn([
                'game_duration',
                'duration_minutes',
                'has_active_bets',
                'total_bet_pool',
                'abandonment_log'
            ]);
        });
    }
};

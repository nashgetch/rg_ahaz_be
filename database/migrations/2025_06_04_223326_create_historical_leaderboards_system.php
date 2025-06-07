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
        // Update the main leaderboards table to support average scores
        Schema::table('leaderboards', function (Blueprint $table) {
            $table->decimal('average_score', 10, 2)->default(0)->after('best_score');
            $table->unsignedInteger('total_score')->default(0)->after('average_score');
            $table->string('period_type')->default('current')->after('total_plays'); // current, monthly, yearly
            $table->string('period_key')->nullable()->after('period_type'); // 202501, 2025, etc.
            
            // Add indexes for new columns
            $table->index(['game_id', 'average_score']);
            $table->index(['game_id', 'period_type', 'period_key']);
            $table->index(['period_type', 'period_key']);
        });

        // Create yearly leaderboards table
        Schema::create('yearly_leaderboards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->year('year');
            $table->unsignedInteger('best_score')->default(0);
            $table->decimal('average_score', 10, 2)->default(0);
            $table->unsignedInteger('total_score')->default(0);
            $table->unsignedInteger('total_plays')->default(0);
            $table->timestamp('first_played_at')->nullable();
            $table->timestamp('last_played_at')->nullable();
            $table->timestamps();

            $table->unique(['game_id', 'user_id', 'year']);
            $table->index(['game_id', 'year', 'average_score']);
            $table->index(['year', 'average_score']);
        });

        // Create game statistics table for historical data
        Schema::create('game_period_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->onDelete('cascade');
            $table->string('period_type'); // monthly, yearly
            $table->string('period_key'); // 202501, 2025
            $table->unsignedInteger('total_players')->default(0);
            $table->unsignedInteger('total_plays')->default(0);
            $table->decimal('average_score', 10, 2)->default(0);
            $table->unsignedInteger('highest_score')->default(0);
            $table->unsignedInteger('lowest_score')->default(0);
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->timestamps();

            $table->unique(['game_id', 'period_type', 'period_key']);
            $table->index(['period_type', 'period_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leaderboards', function (Blueprint $table) {
            $table->dropIndex(['game_id', 'average_score']);
            $table->dropIndex(['game_id', 'period_type', 'period_key']);
            $table->dropIndex(['period_type', 'period_key']);
            
            $table->dropColumn(['average_score', 'total_score', 'period_type', 'period_key']);
        });

        Schema::dropIfExists('game_period_stats');
        Schema::dropIfExists('yearly_leaderboards');
    }
};

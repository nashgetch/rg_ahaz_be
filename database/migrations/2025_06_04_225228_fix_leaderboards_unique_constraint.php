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
        Schema::table('leaderboards', function (Blueprint $table) {
            // Drop the old unique constraint
            $table->dropUnique(['game_id', 'user_id']);
            
            // Add new unique constraint that includes period_type and period_key
            $table->unique(['game_id', 'user_id', 'period_type', 'period_key'], 'leaderboards_game_user_period_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leaderboards', function (Blueprint $table) {
            // Drop the new unique constraint
            $table->dropUnique('leaderboards_game_user_period_unique');
            
            // Restore the old unique constraint
            $table->unique(['game_id', 'user_id']);
        });
    }
};

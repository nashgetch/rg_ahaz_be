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
        Schema::create('leaderboards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->unsignedInteger('best_score');
            $table->unsignedInteger('total_plays')->default(1);
            $table->unsignedInteger('rank_global')->nullable();
            $table->unsignedInteger('rank_daily')->nullable();
            $table->unsignedInteger('rank_weekly')->nullable();
            $table->timestamp('last_played_at');
            $table->timestamps();

            $table->unique(['game_id', 'user_id']);
            $table->index(['game_id', 'best_score']);
            $table->index(['game_id', 'rank_global']);
            $table->index(['game_id', 'rank_daily']);
            $table->index(['game_id', 'rank_weekly']);
            $table->index('last_played_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leaderboards');
    }
};

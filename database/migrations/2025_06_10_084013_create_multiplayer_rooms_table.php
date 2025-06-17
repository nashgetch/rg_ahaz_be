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
        Schema::create('multiplayer_rooms', function (Blueprint $table) {
            $table->id();
            $table->string('room_code', 8)->unique(); // Short shareable code
            $table->foreignId('host_user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('game_id')->constrained('games')->onDelete('cascade');
            $table->string('room_name');
            $table->text('description')->nullable();
            $table->enum('status', ['waiting', 'starting', 'in_progress', 'completed', 'cancelled'])->default('waiting');
            $table->integer('max_players')->default(4);
            $table->integer('current_players')->default(1); // Host counts as 1
            $table->boolean('is_private')->default(false);
            $table->string('password')->nullable(); // For private rooms
            $table->json('game_config')->nullable(); // Game-specific settings
            $table->json('game_state')->nullable(); // Current game state
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('winner_user_id')->nullable();
            $table->json('final_scores')->nullable(); // Array of user scores
            $table->timestamps();

            $table->index(['status', 'is_private']);
            $table->index(['game_id', 'status']);
            $table->index('room_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('multiplayer_rooms');
    }
};

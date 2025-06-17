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
        Schema::create('multiplayer_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('multiplayer_rooms')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->enum('status', ['invited', 'joined', 'ready', 'playing', 'finished', 'disconnected'])->default('joined');
            $table->boolean('is_host')->default(false);
            $table->integer('score')->default(0);
            $table->json('game_progress')->nullable(); // Game-specific progress data
            $table->timestamp('joined_at');
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->integer('final_rank')->nullable(); // 1st, 2nd, 3rd place etc.
            $table->timestamps();

            $table->unique(['room_id', 'user_id']); // One entry per user per room
            $table->index(['room_id', 'status']);
            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('multiplayer_participants');
    }
};

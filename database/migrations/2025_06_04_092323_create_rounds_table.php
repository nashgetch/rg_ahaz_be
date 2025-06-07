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
        Schema::create('rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->unsignedInteger('score')->default(0);
            $table->unsignedInteger('moves')->nullable(); // For games with move counts
            $table->unsignedInteger('duration_ms'); // Game duration in milliseconds
            $table->json('game_data')->nullable(); // Game-specific data (seeds, sequences, etc.)
            $table->string('score_hash'); // Anti-cheat verification
            $table->enum('status', ['started', 'completed', 'abandoned'])->default('started');
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['game_id', 'user_id', 'completed_at']);
            $table->index(['game_id', 'score']);
            $table->index(['user_id', 'completed_at']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rounds');
    }
};

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
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->text('description');
            $table->string('mechanic'); // 'word', 'puzzle', 'trivia', 'memory', etc.
            $table->json('config'); // Game-specific configuration
            $table->unsignedInteger('token_cost')->default(10);
            $table->unsignedInteger('max_score_reward')->default(100);
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('play_count')->default(0);
            $table->timestamps();

            $table->index(['enabled', 'slug']);
            $table->index('mechanic');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};

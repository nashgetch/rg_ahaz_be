<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Insert the Mines game with id 9
        DB::table('games')->insert([
            'id' => 9,
            'slug' => 'mines',
            'title' => 'Mines',
            'description' => 'Risk-reward game: reveal safe tiles to increase multiplier, avoid bombs',
            'mechanic' => 'strategy',
            'config' => json_encode([
                'grid_size' => 5,
                'total_tiles' => 25,
                'bomb_count' => 3,
                'flag_cost' => 0.5,
                'base_multiplier' => 1.2,
                'multiplier_growth' => 1.2,
                'max_safe_tiles' => 22,
                'difficulty' => 'medium',
                'time_limit' => null, // No time limit for mines
                'max_attempts_per_day' => 15,
                'is_multiplayer' => false,
                'icon' => '💣',
                'thumbnail' => '/images/games/mines.png',
                'instructions' => 'Click tiles to reveal them. Each safe tile increases your multiplier. Cash out anytime to secure winnings, but hit a bomb and lose everything! Use the flag power-up (0.5 tokens) to reveal one guaranteed safe tile.',
            ]),
            'token_cost' => 1,
            'max_score_reward' => 2200, // 22x multiplier * 100
            'enabled' => true,
            'play_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('games')->where('slug', 'mines')->delete();
    }
};

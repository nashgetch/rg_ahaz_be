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
        // Insert Hangman game into the games table
        DB::table('games')->insert([
            'slug' => 'hangman',
            'title' => 'Hangman',
            'description' => 'Classic word guessing game. Guess the word letter by letter before the drawing is complete.',
            'mechanic' => 'word',
            'config' => json_encode([
                'min_word_length' => 4,
                'max_word_length' => 12,
                'max_wrong_guesses' => 7,
                'time_limit' => 300, // 5 minutes
                'dictionary_size' => 400,
                'scoring' => [
                    'base_points' => 100,
                    'letter_bonus' => 10,
                    'wrong_guess_penalty' => 20,
                    'time_bonus_max' => 50,
                    'perfect_game_bonus' => 200
                ],
                'difficulty_levels' => ['easy', 'medium', 'hard'],
                'languages' => ['en'],
                'lives' => 7
            ]),
            'token_cost' => 9,
            'max_score_reward' => 100,
            'enabled' => true,
            'play_count' => 0,
            'instructions' => json_encode([
                'en' => 'Guess the hidden word by selecting letters. You have 7 wrong guesses before the hangman is complete. Correct guesses reveal all instances of that letter.',
                'am' => 'የተደበቀውን ቃል ፊደላትን በመምረጥ ይገምቱ። የሰቅላው ስዕል ከመጠናቀቁ በፊት 7 የተሳሳቱ ግምቶች አሉዎት። ትክክለኛ ግምቶች የዚያን ፊደል ሁሉንም ምሳሌዎች ያሳያሉ።'
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove Hangman game from the games table
        DB::table('games')->where('slug', 'hangman')->delete();
    }
}; 
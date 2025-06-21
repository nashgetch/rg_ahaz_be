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
        Schema::table('users', function (Blueprint $table) {
            $table->integer('geo_longest_streak')->default(0)->after('avatar');
            $table->integer('geo_total_questions_answered')->default(0)->after('geo_longest_streak');
            $table->integer('geo_correct_answers')->default(0)->after('geo_total_questions_answered');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['geo_longest_streak', 'geo_total_questions_answered', 'geo_correct_answers']);
        });
    }
}; 
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
        Schema::create('geo_questions', function (Blueprint $table) {
            $table->id();
            $table->string('question_id')->unique(); // Custom ID like 'eth-1', 'afr-2'
            $table->text('question');
            $table->json('options'); // Store 4 options as JSON array
            $table->tinyInteger('correct_answer'); // Index of correct answer (0-3)
            $table->enum('category', ['ethiopia', 'africa', 'world']);
            $table->enum('difficulty', ['easy', 'medium', 'hard']);
            $table->text('explanation')->nullable();
            $table->integer('points');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            // Indexes for better performance
            $table->index(['category', 'difficulty']);
            $table->index(['is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('geo_questions');
    }
}; 
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GeoQuestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'question_id',
        'question',
        'options',
        'correct_answer',
        'category',
        'difficulty',
        'explanation',
        'points',
        'is_active',
    ];

    protected $casts = [
        'options' => 'array',
        'correct_answer' => 'integer',
        'points' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Scope for active questions only
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope for questions by category
     */
    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Scope for questions by difficulty
     */
    public function scopeByDifficulty($query, $difficulty)
    {
        return $query->where('difficulty', $difficulty);
    }

    /**
     * Get random questions with variety
     */
    public static function getRandomQuestions($count = 10)
    {
        return self::active()
            ->inRandomOrder()
            ->limit($count)
            ->get();
    }

    /**
     * Get questions with category variety
     */
    public static function getVariedQuestions($count = 10)
    {
        $questionsPerCategory = ceil($count / 3);
        
        $questions = collect();
        
        // Get questions from each category
        foreach (['ethiopia', 'africa', 'world'] as $category) {
            $categoryQuestions = self::active()
                ->byCategory($category)
                ->inRandomOrder()
                ->limit($questionsPerCategory)
                ->get();
            
            $questions = $questions->merge($categoryQuestions);
        }
        
        // Shuffle and take the required count
        return $questions->shuffle()->take($count);
    }
} 
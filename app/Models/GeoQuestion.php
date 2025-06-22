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

    /**
     * Get random questions excluding specific IDs
     */
    public static function getRandomQuestionsExcluding($count = 10, $excludeIds = [])
    {
        $query = self::active()->inRandomOrder();
        
        if (!empty($excludeIds)) {
            $query->whereNotIn('question_id', $excludeIds);
        }
        
        return $query->limit($count)->get();
    }

    /**
     * Get enhanced varied questions with exclusions and better distribution
     */
    public static function getEnhancedVariedQuestions($count = 10, $excludeIds = [])
    {
        $questions = collect();
        $categories = ['ethiopia', 'africa', 'world'];
        $difficulties = ['easy', 'medium', 'hard'];
        
        // Target distribution: balanced across categories and difficulties
        $questionsPerCategory = ceil($count / 3);
        $difficultyWeights = ['easy' => 0.4, 'medium' => 0.4, 'hard' => 0.2];
        
        foreach ($categories as $category) {
            $categoryQuestions = collect();
            
            // Get questions for each difficulty level within category
            foreach ($difficulties as $difficulty) {
                $targetCount = max(1, round($questionsPerCategory * $difficultyWeights[$difficulty]));
                
                $difficultyQuestions = self::active()
                    ->byCategory($category)
                    ->byDifficulty($difficulty)
                    ->when(!empty($excludeIds), function ($query) use ($excludeIds) {
                        $query->whereNotIn('question_id', $excludeIds);
                    })
                    ->inRandomOrder()
                    ->limit($targetCount)
                    ->get();
                
                $categoryQuestions = $categoryQuestions->merge($difficultyQuestions);
            }
            
            // If we don't have enough questions due to exclusions, fill with random from category
            if ($categoryQuestions->count() < $questionsPerCategory) {
                $usedIds = $categoryQuestions->pluck('question_id')->toArray();
                $allExcludeIds = array_merge($excludeIds, $usedIds);
                
                $fillQuestions = self::active()
                    ->byCategory($category)
                    ->whereNotIn('question_id', $allExcludeIds)
                    ->inRandomOrder()
                    ->limit($questionsPerCategory - $categoryQuestions->count())
                    ->get();
                
                $categoryQuestions = $categoryQuestions->merge($fillQuestions);
            }
            
            $questions = $questions->merge($categoryQuestions);
        }
        
        // Final shuffle and limit to exact count
        return $questions->shuffle()->take($count);
    }
} 
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
     * Get random questions excluding specific IDs with enhanced randomization
     */
    public static function getRandomQuestionsExcluding($count = 10, $excludeIds = [])
    {
        // Use time-based seed for session variety
        $randomSeed = time() + rand(1, 1000) + count($excludeIds);
        srand($randomSeed);
        
        // Get large pool and randomize locally for better cross-session variety
        $poolSize = min($count * 10, 1000); // Get larger pool for better randomization
        
        $candidates = self::active()
            ->when(!empty($excludeIds), function ($query) use ($excludeIds) {
                $query->whereNotIn('question_id', $excludeIds);
            })
            ->inRandomOrder()
            ->limit($poolSize)
            ->get();
        
        // Multiple shuffles for maximum randomness
        for ($i = 0; $i < 3; $i++) {
            $candidates = $candidates->shuffle();
        }
        
        // Add random offset selection for more variety
        $offset = rand(0, max(0, $candidates->count() - $count));
        
        return $candidates->skip($offset)->take($count);
    }

    /**
     * Get enhanced varied questions with exclusions and maximum randomization
     */
    public static function getEnhancedVariedQuestions($count = 10, $excludeIds = [])
    {
        // Use time-based randomization seed for more variety across sessions
        $randomSeed = time() + rand(1, 1000);
        srand($randomSeed);
        
        // Get all available categories dynamically
        $categories = self::active()
            ->select('category')
            ->distinct()
            ->pluck('category')
            ->toArray();
        
        if (empty($categories)) {
            return self::getRandomQuestionsExcluding($count, $excludeIds);
        }
        
        // Shuffle categories for random order
        shuffle($categories);
        
        $difficulties = ['easy', 'medium', 'hard'];
        shuffle($difficulties); // Randomize difficulty order too
        
        // More random distribution - vary weights per session
        $randomDistributions = [
            ['easy' => 0.5, 'medium' => 0.3, 'hard' => 0.2],
            ['easy' => 0.3, 'medium' => 0.5, 'hard' => 0.2],
            ['easy' => 0.4, 'medium' => 0.4, 'hard' => 0.2],
            ['easy' => 0.6, 'medium' => 0.2, 'hard' => 0.2],
            ['easy' => 0.2, 'medium' => 0.6, 'hard' => 0.2],
        ];
        $difficultyWeights = $randomDistributions[array_rand($randomDistributions)];
        
        // Collect questions with maximum randomization
        $allCandidates = self::active()
            ->when(!empty($excludeIds), function ($query) use ($excludeIds) {
                $query->whereNotIn('question_id', $excludeIds);
            })
            ->inRandomOrder()
            ->get();
        
        // Group by category and difficulty for variety
        $groupedQuestions = $allCandidates->groupBy(function ($question) {
            return $question->category . '_' . $question->difficulty;
        });
        
        $selectedQuestions = collect();
        $categoryCounts = array_fill_keys($categories, 0);
        $targetPerCategory = ceil($count / count($categories));
        
        // Multiple passes for maximum randomization
        for ($pass = 0; $pass < 3 && $selectedQuestions->count() < $count; $pass++) {
            foreach ($categories as $category) {
                if ($selectedQuestions->count() >= $count) break;
                if ($categoryCounts[$category] >= $targetPerCategory) continue;
                
                // Randomly select difficulty for this category
                $difficulty = $difficulties[array_rand($difficulties)];
                $groupKey = $category . '_' . $difficulty;
                
                if (isset($groupedQuestions[$groupKey]) && $groupedQuestions[$groupKey]->isNotEmpty()) {
                    // Take random question from this group
                    $question = $groupedQuestions[$groupKey]->random();
                    
                    // Avoid duplicates
                    if (!$selectedQuestions->contains('question_id', $question->question_id)) {
                        $selectedQuestions->push($question);
                        $categoryCounts[$category]++;
                        
                        // Remove from pool to avoid re-selection
                        $groupedQuestions[$groupKey] = $groupedQuestions[$groupKey]
                            ->reject(function ($q) use ($question) {
                                return $q->question_id === $question->question_id;
                            });
                    }
                }
            }
        }
        
        // Fill remaining slots with completely random questions if needed
        while ($selectedQuestions->count() < $count && $allCandidates->isNotEmpty()) {
            $randomQuestion = $allCandidates->random();
            
            if (!$selectedQuestions->contains('question_id', $randomQuestion->question_id)) {
                $selectedQuestions->push($randomQuestion);
            }
            
            $allCandidates = $allCandidates->reject(function ($q) use ($randomQuestion) {
                return $q->question_id === $randomQuestion->question_id;
            });
        }
        
        // Multiple shuffles for maximum randomness
        $result = $selectedQuestions->shuffle();
        for ($i = 0; $i < 3; $i++) {
            $result = $result->shuffle();
        }
        
        return $result->take($count);
    }
} 
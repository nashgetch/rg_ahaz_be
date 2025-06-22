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
     * Get ultra-random questions with massive pool and extreme randomization
     */
    public static function getUltraRandomQuestions($count = 10, $excludeIds = [])
    {
        // EXTREME randomization with multiple entropy sources
        $microtime = (int)(microtime(true) * 1000000);
        $ultraSeed = $microtime + rand(1, 1000000) + count($excludeIds) * 7919;
        srand($ultraSeed);
        mt_srand($ultraSeed + rand(1, 50000));
        
        // Get MASSIVE candidate pool (up to entire database for ultra variety)
        $maxPoolSize = min(6000, self::active()->count()); // Nearly entire database
        
        // Multiple query passes with different ORDER BY strategies
        $queryStrategies = [
            'random' => function ($query) { return $query->inRandomOrder(); },
            'id_desc' => function ($query) { return $query->orderBy('id', 'DESC'); },
            'id_asc' => function ($query) { return $query->orderBy('id', 'ASC'); },
            'category' => function ($query) { return $query->orderBy('category', 'ASC')->inRandomOrder(); },
            'difficulty' => function ($query) { return $query->orderBy('difficulty', 'DESC')->inRandomOrder(); },
        ];
        
        $strategyName = array_rand($queryStrategies);
        $strategy = $queryStrategies[$strategyName];
        
        $candidates = self::active()
            ->when(!empty($excludeIds), function ($query) use ($excludeIds) {
                $query->whereNotIn('question_id', $excludeIds);
            })
            ->when(true, $strategy)
            ->limit($maxPoolSize)
            ->get();
        
        if ($candidates->isEmpty()) {
            return collect();
        }
        
        // EXTREME randomization layers
        for ($layer = 0; $layer < rand(7, 15); $layer++) {
            $candidates = $candidates->shuffle();
            
            // Apply different sorting algorithms randomly
            switch (rand(1, 4)) {
                case 1:
                    $candidates = $candidates->sortBy(function () { return rand(1, 999999); })->values();
                    break;
                case 2:
                    $candidates = $candidates->reverse();
                    break;
                case 3:
                    $candidates = $candidates->chunk(rand(50, 200))->shuffle()->flatten();
                    break;
                default:
                    $candidates = $candidates->shuffle();
            }
        }
        
        // Random selection with multiple offset strategies
        $offsetStrategies = [
            'start' => 0,
            'middle' => max(0, intval($candidates->count() / 2) - intval($count / 2)),
            'end' => max(0, $candidates->count() - $count),
            'random' => rand(0, max(0, $candidates->count() - $count)),
            'quarter' => max(0, intval($candidates->count() / 4)),
            'three_quarter' => max(0, intval($candidates->count() * 3 / 4) - $count),
        ];
        
        $offsetStrategy = array_rand($offsetStrategies);
        $offset = $offsetStrategies[$offsetStrategy];
        
        $selected = $candidates->skip($offset)->take($count);
        
        // Final ultra-randomization
        for ($final = 0; $final < rand(5, 12); $final++) {
            $selected = $selected->shuffle();
        }
        
        return $selected;
    }

    /**
     * Get ultra-randomized questions with maximum variety and deep randomization
     */
    public static function getEnhancedVariedQuestions($count = 10, $excludeIds = [])
    {
        // ULTRA-RANDOMIZATION: Multiple entropy sources
        $microtime = (int)(microtime(true) * 1000000);
        $randomSeed = $microtime + rand(1, 100000) + count($excludeIds) * 1337;
        srand($randomSeed);
        mt_srand($randomSeed);
        
        // Get MASSIVE pool for maximum variety (10x larger than needed)
        $poolMultiplier = max(10, 50 - (count($excludeIds) / 100)); // Larger pool if fewer exclusions
        $poolSize = min($count * $poolMultiplier, 5000); // Up to 5000 questions in pool
        
        // Get ultra-random base pool using multiple ORDER BY RAND() calls
        $basePool = self::active()
            ->when(!empty($excludeIds), function ($query) use ($excludeIds) {
                $query->whereNotIn('question_id', $excludeIds);
            })
            ->inRandomOrder()
            ->limit($poolSize)
            ->get();
        
        if ($basePool->isEmpty()) {
            return collect();
        }
        
        // Apply MULTIPLE layers of randomization
        for ($layer = 0; $layer < 5; $layer++) {
            $basePool = $basePool->shuffle();
        }
        
        // Get all categories and apply session-specific randomization
        $categories = $basePool->pluck('category')->unique()->toArray();
        shuffle($categories);
        
        // ULTRA-RANDOM category selection strategy
        $strategies = [
            'balanced',     // Balanced across categories
            'weighted',     // Random weights per category  
            'clustered',    // Focus on 3-4 categories
            'scattered',    // Maximum variety
            'random'        // Completely random
        ];
        $strategy = $strategies[array_rand($strategies)];
        
        $selectedQuestions = collect();
        
        switch ($strategy) {
            case 'balanced':
                $selectedQuestions = self::selectBalanced($basePool, $count, $categories);
                break;
                
            case 'weighted':
                $selectedQuestions = self::selectWeighted($basePool, $count, $categories);
                break;
                
            case 'clustered':
                $selectedQuestions = self::selectClustered($basePool, $count, $categories);
                break;
                
            case 'scattered':
                $selectedQuestions = self::selectScattered($basePool, $count, $categories);
                break;
                
            default: // 'random'
                $selectedQuestions = self::selectPureRandom($basePool, $count);
        }
        
        // Apply FINAL randomization layers
        $result = $selectedQuestions->shuffle();
        
        // Multiple random sorts with different algorithms
        for ($i = 0; $i < rand(3, 7); $i++) {
            $result = $result->sortBy(function () {
                return rand(1, 1000000);
            })->values();
        }
        
        // Final shuffle with time-based seed
        $result = $result->shuffle();
        
        return $result->take($count);
    }
    
    /**
     * Balanced selection across categories
     */
    private static function selectBalanced($pool, $count, $categories)
    {
        $questionsPerCategory = ceil($count / count($categories));
        $selected = collect();
        
        foreach ($categories as $category) {
            $categoryQuestions = $pool->where('category', $category)
                ->shuffle()
                ->take($questionsPerCategory);
            $selected = $selected->merge($categoryQuestions);
        }
        
        return $selected->shuffle()->take($count);
    }
    
    /**
     * Weighted random selection
     */
    private static function selectWeighted($pool, $count, $categories)
    {
        $weights = [];
        foreach ($categories as $category) {
            $weights[$category] = rand(1, 10);
        }
        
        $selected = collect();
        for ($i = 0; $i < $count; $i++) {
            $randomCategory = self::weightedRandomSelect($weights);
            $categoryPool = $pool->where('category', $randomCategory);
            
            if ($categoryPool->isNotEmpty()) {
                $question = $categoryPool->random();
                $selected->push($question);
                $pool = $pool->reject(function ($q) use ($question) {
                    return $q->id === $question->id;
                });
            }
        }
        
        return $selected;
    }
    
    /**
     * Clustered selection (focus on fewer categories)
     */
    private static function selectClustered($pool, $count, $categories)
    {
        $focusCategories = collect($categories)->shuffle()->take(rand(2, 4))->toArray();
        $filteredPool = $pool->whereIn('category', $focusCategories);
        
        return $filteredPool->shuffle()->take($count);
    }
    
    /**
     * Scattered selection (maximum variety)
     */
    private static function selectScattered($pool, $count, $categories)
    {
        $selected = collect();
        $usedCategories = [];
        
        for ($i = 0; $i < $count; $i++) {
            // Try to use each category only once if possible
            $availableCategories = array_diff($categories, $usedCategories);
            if (empty($availableCategories)) {
                $availableCategories = $categories;
                $usedCategories = [];
            }
            
            $targetCategory = $availableCategories[array_rand($availableCategories)];
            $usedCategories[] = $targetCategory;
            
            $categoryPool = $pool->where('category', $targetCategory);
            if ($categoryPool->isNotEmpty()) {
                $question = $categoryPool->random();
                $selected->push($question);
                $pool = $pool->reject(function ($q) use ($question) {
                    return $q->id === $question->id;
                });
            }
        }
        
        return $selected;
    }
    
    /**
     * Pure random selection
     */
    private static function selectPureRandom($pool, $count)
    {
        return $pool->shuffle()->take($count);
    }
    
    /**
     * Weighted random selection helper
     */
    private static function weightedRandomSelect($weights)
    {
        $totalWeight = array_sum($weights);
        $random = rand(1, $totalWeight);
        $currentWeight = 0;
        
        foreach ($weights as $category => $weight) {
            $currentWeight += $weight;
            if ($random <= $currentWeight) {
                return $category;
            }
        }
        
        return array_keys($weights)[0];
    }
} 
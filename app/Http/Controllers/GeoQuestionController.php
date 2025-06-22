<?php

namespace App\Http\Controllers;

use App\Models\GeoQuestion;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class GeoQuestionController extends Controller
{
    /**
     * Get questions for a GeoSprint game round with ULTRA randomization
     */
    public function getGameQuestions(Request $request): JsonResponse
    {
        try {
            $count = $request->get('count', 10);
            $variety = $request->get('variety', true);
            $excludeIds = $request->get('exclude_ids', '');
            
            // ULTRA-RANDOMIZATION parameters
            $sessionSeed = $request->get('session_seed', time());
            $randomOffset = $request->get('random_offset', 0);
            $varietyBoost = $request->get('variety_boost', 'normal');
            $ultraRandom = $request->get('ultra_random', 0);
            
            // Validate count
            if ($count < 1 || $count > 50) {
                return response()->json([
                    'success' => false,
                    'message' => 'Question count must be between 1 and 50'
                ], 400);
            }
            
            // Apply session-specific randomization seed
            srand(intval($sessionSeed) + intval($randomOffset));
            
            // Parse exclude IDs
            $excludeArray = [];
            if (!empty($excludeIds)) {
                $excludeArray = array_filter(explode(',', $excludeIds));
            }
            
            // Apply ultra-randomization if requested
            if ($ultraRandom) {
                // Increase count for better randomization pool
                $poolCount = $count * (rand(15, 25)); // 15-25x more questions in pool
                $questions = GeoQuestion::getUltraRandomQuestions($poolCount, $excludeArray);
                
                // Apply multiple randomization layers
                for ($i = 0; $i < rand(5, 10); $i++) {
                    $questions = $questions->shuffle();
                }
                
                // Take final selection
                $questions = $questions->take($count);
            } else {
                // Enhanced variety with exclusions
                if ($variety) {
                    $questions = GeoQuestion::getEnhancedVariedQuestions($count, $excludeArray);
                } else {
                    $questions = GeoQuestion::getRandomQuestionsExcluding($count, $excludeArray);
                }
            }
            
            // Apply variety boost if specified
            if ($varietyBoost === 'ultra' || $varietyBoost === 'max') {
                $questions = $questions->shuffle();
                
                // Re-randomize with different seed
                srand(time() + rand(1, 10000));
                $questions = $questions->shuffle();
            }
            
            // Transform questions for frontend
            $formattedQuestions = $questions->map(function ($question) {
                return [
                    'id' => $question->question_id,
                    'question' => $question->question,
                    'options' => $question->options,
                    'correctAnswer' => $question->correct_answer,
                    'category' => $question->category,
                    'difficulty' => $question->difficulty,
                    'explanation' => $question->explanation,
                    'points' => $question->points,
                ];
            });
            
            return response()->json([
                'success' => true,
                'questions' => $formattedQuestions,
                'count' => $formattedQuestions->count(),
                'excluded_count' => count($excludeArray),
                'randomization_level' => $ultraRandom ? 'ultra' : 'enhanced',
                'variety_boost' => $varietyBoost,
                'session_seed' => $sessionSeed
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch questions',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get questions by category
     */
    public function getQuestionsByCategory(Request $request, string $category): JsonResponse
    {
        try {
            $count = $request->get('count', 10);
            
            // Validate category
            if (!in_array($category, ['ethiopia', 'africa', 'world'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid category'
                ], 400);
            }
            
            $questions = GeoQuestion::active()
                ->byCategory($category)
                ->inRandomOrder()
                ->limit($count)
                ->get();
            
            $formattedQuestions = $questions->map(function ($question) {
                return [
                    'id' => $question->question_id,
                    'question' => $question->question,
                    'options' => $question->options,
                    'correctAnswer' => $question->correct_answer,
                    'category' => $question->category,
                    'difficulty' => $question->difficulty,
                    'explanation' => $question->explanation,
                    'points' => $question->points,
                ];
            });
            
            return response()->json([
                'success' => true,
                'questions' => $formattedQuestions,
                'category' => $category,
                'count' => $formattedQuestions->count()
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch questions',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get questions statistics
     */
    public function getStatistics(): JsonResponse
    {
        try {
            $stats = [
                'total_questions' => GeoQuestion::active()->count(),
                'by_category' => [
                    'ethiopia' => GeoQuestion::active()->byCategory('ethiopia')->count(),
                    'africa' => GeoQuestion::active()->byCategory('africa')->count(),
                    'world' => GeoQuestion::active()->byCategory('world')->count(),
                ],
                'by_difficulty' => [
                    'easy' => GeoQuestion::active()->byDifficulty('easy')->count(),
                    'medium' => GeoQuestion::active()->byDifficulty('medium')->count(),
                    'hard' => GeoQuestion::active()->byDifficulty('hard')->count(),
                ]
            ];
            
            return response()->json([
                'success' => true,
                'statistics' => $stats
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update user's GeoSprint streak data
     */
    public function updateStreakData(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'current_streak' => 'required|integer|min:0',
                'total_questions' => 'required|integer|min:0',
                'correct_answers' => 'required|integer|min:0',
                'final_score' => 'required|integer|min:0'
            ]);

            $user = $request->user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }

            // Update longest streak if current streak is better
            if ($request->current_streak > $user->geo_longest_streak) {
                $user->geo_longest_streak = $request->current_streak;
            }

            // Update totals
            $user->geo_total_questions_answered += $request->total_questions;
            $user->geo_correct_answers += $request->correct_answers;
            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Streak data updated successfully',
                'data' => [
                    'longest_streak' => $user->geo_longest_streak,
                    'total_questions_answered' => $user->geo_total_questions_answered,
                    'total_correct_answers' => $user->geo_correct_answers,
                    'overall_accuracy' => $user->geo_total_questions_answered > 0 
                        ? round(($user->geo_correct_answers / $user->geo_total_questions_answered) * 100, 1)
                        : 0
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update streak data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user's GeoSprint statistics
     */
    public function getUserStats(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }

            return response()->json([
                'success' => true,
                'stats' => [
                    'longest_streak' => $user->geo_longest_streak,
                    'total_questions_answered' => $user->geo_total_questions_answered,
                    'total_correct_answers' => $user->geo_correct_answers,
                    'overall_accuracy' => $user->geo_total_questions_answered > 0 
                        ? round(($user->geo_correct_answers / $user->geo_total_questions_answered) * 100, 1)
                        : 0
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch user stats',
                'error' => $e->getMessage()
            ], 500);
        }
    }
} 
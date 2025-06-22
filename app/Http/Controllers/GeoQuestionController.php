<?php

namespace App\Http\Controllers;

use App\Models\GeoQuestion;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class GeoQuestionController extends Controller
{
    /**
     * Get questions for a GeoSprint game round
     */
    public function getGameQuestions(Request $request): JsonResponse
    {
        try {
            $count = $request->get('count', 10);
            $variety = $request->get('variety', true);
            $excludeIds = $request->get('exclude_ids', '');
            
            // Validate count
            if ($count < 1 || $count > 50) {
                return response()->json([
                    'success' => false,
                    'message' => 'Question count must be between 1 and 50'
                ], 400);
            }
            
            // Parse exclude IDs
            $excludeArray = [];
            if (!empty($excludeIds)) {
                $excludeArray = array_filter(explode(',', $excludeIds));
            }
            
            // Get questions with enhanced variety and exclusions
            if ($variety) {
                $questions = GeoQuestion::getEnhancedVariedQuestions($count, $excludeArray);
            } else {
                $questions = GeoQuestion::getRandomQuestionsExcluding($count, $excludeArray);
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
                'excluded_count' => count($excludeArray)
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
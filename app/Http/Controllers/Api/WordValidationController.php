<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class WordValidationController extends Controller
{
    /**
     * Get the list of valid words from the dictionary file
     * 
     * @return array
     */
    private static function getValidWords(): array
    {
        static $words = null;
        
        if ($words === null) {
            $words = require(resource_path('dictionaries/words.php'));
        }
        
        return $words;
    }

    /**
     * Validate if a word is a real English word
     */
    public function validateWord(Request $request): JsonResponse
    {
        $request->validate([
            'word' => 'required|string|min:3|max:15'
        ]);

        $word = strtolower(trim($request->input('word')));
        
        // Check cache first for performance
        $cacheKey = "word_validation_{$word}";
        $isValid = Cache::remember($cacheKey, now()->addHours(24), function() use ($word) {
            return $this->isValidWord($word);
        });

        return response()->json([
            'success' => true,
            'data' => [
                'word' => $word,
                'is_valid' => $isValid
            ]
        ]);
    }

    /**
     * Validate multiple words at once
     */
    public function validateWords(Request $request): JsonResponse
    {
        $request->validate([
            'words' => 'required|array|min:1|max:10',
            'words.*' => 'required|string|min:3|max:15'
        ]);

        $words = array_map(function($word) {
            return strtolower(trim($word));
        }, $request->input('words'));

        $results = [];
        foreach ($words as $word) {
            $cacheKey = "word_validation_{$word}";
            $isValid = Cache::remember($cacheKey, now()->addHours(24), function() use ($word) {
                return $this->isValidWord($word);
            });

            $results[] = [
                'word' => $word,
                'is_valid' => $isValid
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $results
        ]);
    }

    /**
     * Check if a word is valid
     */
    private function isValidWord(string $word): bool
    {
        // Convert to lowercase for comparison
        $word = strtolower($word);
        
        // Check against our word list
        return in_array($word, self::getValidWords());
    }

    /**
     * Get word statistics (optional endpoint for debugging)
     */
    public function getWordStats(): JsonResponse
    {
        $validWords = self::getValidWords();
        $wordsByLength = [];
        foreach ($validWords as $word) {
            $length = strlen($word);
            if (!isset($wordsByLength[$length])) {
                $wordsByLength[$length] = 0;
            }
            $wordsByLength[$length]++;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'total_words' => count($validWords),
                'words_by_length' => $wordsByLength,
                'min_length' => min(array_map('strlen', $validWords)),
                'max_length' => max(array_map('strlen', $validWords))
            ]
        ]);
    }
} 
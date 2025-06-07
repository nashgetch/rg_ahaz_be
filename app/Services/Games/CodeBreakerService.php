<?php

namespace App\Services\Games;

class CodeBreakerService
{
    /**
     * Generate a daily code based on the current date
     */
    public function generateDailyCode(string $seed = null): string
    {
        // Use date + game seed for consistent daily codes
        $dateSeed = $seed ?? date('Y-m-d');
        mt_srand(crc32($dateSeed . 'codebreaker'));
        
        // Generate 4-digit code with unique digits
        $digits = range(0, 9);
        shuffle($digits);
        
        return implode('', array_slice($digits, 0, 4));
    }

    /**
     * Validate a guess against the secret code
     */
    public function validateGuess(string $secretCode, string $guess): array
    {
        if (strlen($guess) !== 4 || !ctype_digit($guess)) {
            return [
                'valid' => false,
                'error' => 'Guess must be exactly 4 digits'
            ];
        }

        $secretDigits = str_split($secretCode);
        $guessDigits = str_split($guess);
        
        $correctPosition = 0; // Black pegs - correct digit in correct position
        $correctDigit = 0;    // White pegs - correct digit in wrong position
        
        $secretUsed = array_fill(0, 4, false);
        $guessUsed = array_fill(0, 4, false);
        
        // First pass: check for correct positions
        for ($i = 0; $i < 4; $i++) {
            if ($secretDigits[$i] === $guessDigits[$i]) {
                $correctPosition++;
                $secretUsed[$i] = true;
                $guessUsed[$i] = true;
            }
        }
        
        // Second pass: check for correct digits in wrong positions
        for ($i = 0; $i < 4; $i++) {
            if (!$guessUsed[$i]) {
                for ($j = 0; $j < 4; $j++) {
                    if (!$secretUsed[$j] && $guessDigits[$i] === $secretDigits[$j]) {
                        $correctDigit++;
                        $secretUsed[$j] = true;
                        break;
                    }
                }
            }
        }
        
        return [
            'valid' => true,
            'guess' => $guess,
            'correct_position' => $correctPosition, // Black pegs
            'correct_digit' => $correctDigit,       // White pegs
            'is_solved' => $correctPosition === 4,
            'feedback' => $this->generateFeedback($correctPosition, $correctDigit)
        ];
    }

    /**
     * Generate human-readable feedback
     */
    private function generateFeedback(int $correctPosition, int $correctDigit): string
    {
        if ($correctPosition === 4) {
            return "🎉 Perfect! You cracked the code!";
        }
        
        $feedback = [];
        
        if ($correctPosition > 0) {
            $feedback[] = "🔴 {$correctPosition} correct digit" . ($correctPosition > 1 ? 's' : '') . " in correct position";
        }
        
        if ($correctDigit > 0) {
            $feedback[] = "⚪ {$correctDigit} correct digit" . ($correctDigit > 1 ? 's' : '') . " in wrong position";
        }
        
        if ($correctPosition === 0 && $correctDigit === 0) {
            $feedback[] = "❌ No correct digits";
        }
        
        return implode(', ', $feedback);
    }

    /**
     * Calculate score based on attempts and time
     */
    public function calculateScore(int $attempts, int $timeElapsed, bool $solved, int $maxAttempts = 15): int
    {
        if (!$solved) {
            return 0;
        }
        
        $baseScore = 1000;
        
        // Penalty for more attempts (exponential)
        $attemptPenalty = pow($attempts - 1, 2) * 50;
        
        // Time bonus (faster = better, up to 60 seconds)
        $timeBonus = max(0, (300 - $timeElapsed) / 300 * 200);
        
        // Perfect game bonus (solved in 1 attempt)
        $perfectBonus = $attempts === 1 ? 500 : 0;
        
        // Efficiency bonus (solved in <= 3 attempts)
        $efficiencyBonus = $attempts <= 3 ? 200 : 0;
        
        $finalScore = $baseScore - $attemptPenalty + $timeBonus + $perfectBonus + $efficiencyBonus;
        
        return max(50, (int) $finalScore); // Minimum 50 points
    }

    /**
     * Generate hint for the current guess
     */
    public function generateHint(string $secretCode, array $previousGuesses, int $hintLevel = 1): string
    {
        $secretDigits = str_split($secretCode);
        
        switch ($hintLevel) {
            case 1:
                // Reveal if any digit is in the code
                $randomDigit = $secretDigits[array_rand($secretDigits)];
                return "💡 Hint: The digit {$randomDigit} is somewhere in the code.";
                
            case 2:
                // Reveal position of one digit
                $randomIndex = array_rand($secretDigits);
                return "💡 Hint: Position " . ($randomIndex + 1) . " contains the digit {$secretDigits[$randomIndex]}.";
                
            case 3:
                // Reveal range information
                $min = min($secretDigits);
                $max = max($secretDigits);
                return "💡 Hint: The code contains digits between {$min} and {$max}.";
                
            default:
                return "💡 Think logically about the feedback from your previous guesses!";
        }
    }

    /**
     * Get difficulty settings
     */
    public function getDifficultySettings(string $difficulty = 'medium'): array
    {
        $settings = [
            'easy' => [
                'code_length' => 3,
                'max_attempts' => 12,
                'allow_duplicates' => false,
                'hints_available' => 3,
                'time_limit' => 600 // 10 minutes
            ],
            'medium' => [
                'code_length' => 4,
                'max_attempts' => 10,
                'allow_duplicates' => false,
                'hints_available' => 2,
                'time_limit' => 300 // 5 minutes
            ],
            'hard' => [
                'code_length' => 4,
                'max_attempts' => 8,
                'allow_duplicates' => true,
                'hints_available' => 1,
                'time_limit' => 240 // 4 minutes
            ]
        ];
        
        return $settings[$difficulty] ?? $settings['medium'];
    }

    /**
     * Validate game completion and check for cheating
     */
    public function validateCompletion(array $gameData, int $finalScore, int $timeElapsed, int $maxAttempts = 15): array
    {
        $attempts = count($gameData['guesses'] ?? []);
        $solved = $gameData['solved'] ?? false;
        
        // Basic validation
        if ($attempts === 0) {
            return ['valid' => false, 'reason' => 'No attempts made'];
        }
        
        if ($timeElapsed < 10) {
            return ['valid' => false, 'reason' => 'Completion time too fast'];
        }
        
        if ($solved && $attempts > $maxAttempts) {
            return ['valid' => false, 'reason' => 'Too many attempts for solved game'];
        }
        
        // Validate score calculation
        $expectedScore = $this->calculateScore($attempts, $timeElapsed, $solved, $maxAttempts);
        $scoreDifference = abs($finalScore - $expectedScore);
        
        if ($scoreDifference > 50) {
            return ['valid' => false, 'reason' => 'Score mismatch'];
        }
        
        return ['valid' => true];
    }
} 
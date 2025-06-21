<?php

namespace App\Services\Games;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class HangmanService
{
    // Dictionary of words organized by length for better scoring
    private array $dictionary = [
        // 4 letter words
        'ABLE', 'ACID', 'AGED', 'ALSO', 'AREA', 'ARMY', 'AWAY', 'BABY', 'BACK', 'BALL',
        'BAND', 'BANK', 'BASE', 'BEAT', 'BEEN', 'BELL', 'BEST', 'BILL', 'BIRD', 'BLOW',
        'BLUE', 'BOAT', 'BODY', 'BONE', 'BOOK', 'BORN', 'BOTH', 'BOWL', 'BULK', 'BURN',
        'BUSH', 'BUSY', 'CALL', 'CALM', 'CAME', 'CAMP', 'CARD', 'CARE', 'CASE', 'CASH',
        'CAST', 'CELL', 'CHAT', 'CHIP', 'CITY', 'CLUB', 'COAL', 'COAT', 'CODE', 'COLD',
        'COME', 'COOK', 'COOL', 'COPE', 'COPY', 'CORN', 'COST', 'CREW', 'CROP', 'DARK',
        'DATA', 'DATE', 'DAWN', 'DAYS', 'DEAD', 'DEAL', 'DEAR', 'DEBT', 'DEEP', 'DENY',
        'DESK', 'DIAL', 'DIET', 'DISK', 'DONE', 'DOOR', 'DOSE', 'DOWN', 'DRAW', 'DREW',
        'DROP', 'DRUG', 'DUAL', 'DUTY', 'EACH', 'EARN', 'EASE', 'EAST', 'EASY', 'EDGE',
        'ELSE', 'EVEN', 'EVER', 'EVIL', 'EXIT', 'FACE', 'FACT', 'FAIL', 'FAIR', 'FALL',
        
        // 5 letter words
        'APPLE', 'ABOUT', 'ABOVE', 'ABUSE', 'ACTOR', 'ACUTE', 'ADMIT', 'ADOPT', 'ADULT', 'AFTER',
        'AGAIN', 'AGENT', 'AGREE', 'AHEAD', 'ALARM', 'ALBUM', 'ALERT', 'ALIKE', 'ALIVE', 'ALLOW',
        'ALONE', 'ALONG', 'ALTER', 'AMONG', 'ANGER', 'ANGLE', 'ANGRY', 'APART', 'APPLY', 'ARENA',
        'ARGUE', 'ARISE', 'ARRAY', 'ARROW', 'ASIDE', 'ASSET', 'AVOID', 'AWAKE', 'AWARD', 'AWARE',
        'BADLY', 'BAKER', 'BASES', 'BASIC', 'BEACH', 'BEGAN', 'BEGIN', 'BEING', 'BELOW', 'BENCH',
        'BIRTH', 'BLACK', 'BLAME', 'BLANK', 'BLIND', 'BLOCK', 'BLOOD', 'BOARD', 'BOAST', 'BOOST',
        'BOOTH', 'BOUND', 'BRAIN', 'BRAND', 'BRASS', 'BRAVE', 'BREAD', 'BREAK', 'BREED', 'BRIEF',
        'BRING', 'BROAD', 'BROKE', 'BROWN', 'BUILD', 'BUILT', 'BUYER', 'CABLE', 'CARRY', 'CATCH',
        'CAUSE', 'CHAIN', 'CHAIR', 'CHAOS', 'CHARM', 'CHART', 'CHASE', 'CHEAP', 'CHECK', 'CHEST',
        'CHIEF', 'CHILD', 'CHINA', 'CHOSE', 'CIVIL', 'CLAIM', 'CLASS', 'CLEAN', 'CLEAR', 'CLICK',
        
        // 6 letter words
        'ACCEPT', 'ACCESS', 'ACCORD', 'ACROSS', 'ACTION', 'ACTIVE', 'ACTUAL', 'ADVICE', 'ADVISE', 'AFFECT',
        'AFFORD', 'AFRAID', 'AFRICA', 'AGENCY', 'AGENDA', 'ALMOST', 'ALWAYS', 'AMOUNT', 'ANIMAL', 'ANNUAL',
        'ANSWER', 'ANYONE', 'ANYWAY', 'APPEAL', 'APPEAR', 'AROUND', 'ARRIVE', 'ARTIST', 'ASPECT', 'ASSESS',
        'ASSIST', 'ASSUME', 'ATTACH', 'ATTACK', 'ATTEND', 'AUGUST', 'AUTHOR', 'AUTUMN', 'AVENUE', 'BACKED',
        'BACKUP', 'BARELY', 'BATTLE', 'BEAUTY', 'BECOME', 'BEFORE', 'BEHALF', 'BEHAVE', 'BEHIND', 'BELIEF',
        'BELONG', 'BERLIN', 'BETTER', 'BEYOND', 'BINARY', 'BORDER', 'BOTTLE', 'BOTTOM', 'BOUGHT', 'BRANCH',
        'BREATH', 'BRIDGE', 'BRIGHT', 'BRINGS', 'BRITAIN', 'BROKEN', 'BUDGET', 'BUNDLE', 'BURDEN', 'BUREAU',
        'BUTTON', 'BUYERS', 'CAMERA', 'CANCER', 'CANNOT', 'CANVAS', 'CAREER', 'CASTLE', 'CASUAL', 'CAUGHT',
        'CENTRE', 'CHANGE', 'CHARGE', 'CHOICE', 'CHOOSE', 'CHOSEN', 'CHURCH', 'CIRCLE', 'CLIENT', 'CLOSED',
        'CLOSER', 'COFFEE', 'COLUMN', 'COMBAT', 'COMING', 'COMMIT', 'COMMON', 'COMMUNITY', 'COMPANY', 'COMPARE',
        
        // 7 letter words
        'ABILITY', 'ABSENCE', 'ACADEMY', 'ACCOUNT', 'ACCUSED', 'ACHIEVE', 'ACQUIRE', 'ADDRESS', 'ADVANCE', 'ADVISER',
        'AGAINST', 'AIRLINE', 'AIRPORT', 'ALGEBRA', 'ALREADY', 'AMAZING', 'AMONGST', 'ANALYSE', 'ANCIENT', 'ANOTHER',
        'ANXIETY', 'ANXIOUS', 'ANYWHERE', 'APPLIED', 'ARRANGE', 'ARTICLE', 'ASSAULT', 'ATTEMPT', 'ATTRACT', 'AUCTION',
        'AVERAGE', 'BACKING', 'BALANCE', 'BANKING', 'BATTERY', 'BEARING', 'BEATING', 'BECAUSE', 'BEDROOM', 'BENEFIT',
        'BETWEEN', 'BICYCLE', 'BIGGEST', 'BIOLOGY', 'BROTHER', 'BROUGHT', 'BURNING', 'CABINET', 'CALLING', 'CAPABLE',
        'CAPITAL', 'CAPTAIN', 'CAPTURE', 'CAREFUL', 'CARRIER', 'CASTING', 'CATALOG', 'CEILING', 'CENTRAL', 'CENTURY',
        'CERTAIN', 'CHAMBER', 'CHANNEL', 'CHAPTER', 'CHARITY', 'CHEMICAL', 'CHICKEN', 'CIRCUIT', 'CITIZEN', 'CLASSIC',
        'CLIMATE', 'CLOSING', 'CLOTHES', 'COATING', 'COLLEGE', 'COMBINE', 'COMFORT', 'COMMAND', 'COMMENT', 'COMPACT',
        'COMPANY', 'COMPARE', 'COMPETE', 'COMPLEX', 'CONCEPT', 'CONCERN', 'CONCERT', 'CONDUCT', 'CONFIRM', 'CONNECT',
        'CONSENT', 'CONSIST', 'CONTACT', 'CONTAIN', 'CONTENT', 'CONTEST', 'CONTEXT', 'CONTROL', 'CONVERT', 'COOKING',
        
        // 8 letter words
        'ABSOLUTE', 'ABSTRACT', 'ACADEMIC', 'ACCEPTED', 'ACCIDENT', 'ACCURATE', 'ACHIEVED', 'ACQUIRED', 'ACTIVITY', 'ACTUALLY',
        'ADDITION', 'ADEQUATE', 'ADJACENT', 'ADJUSTED', 'ADVANCED', 'ADVISORY', 'ADVOCATE', 'AFFECTED', 'AIRCRAFT', 'ALLIANCE',
        'ALTHOUGH', 'ANALYSIS', 'ANNOUNCE', 'ANNUALLY', 'ANYTHING', 'ANYWHERE', 'APPARENT', 'APPROACH', 'APPROVAL', 'ARGUMENT',
        'ARRANGED', 'ARTICLE', 'ASSEMBLY', 'ATTACHED', 'ATTORNEY', 'AUDIENCE', 'AUTHORITY', 'AVAILABLE', 'AVIATION', 'AWARENESS',
        'BACHELOR', 'BALANCED', 'BATHROOM', 'BEAUTIFUL', 'BEHAVIOR', 'BIRTHDAY', 'BOUNDARY', 'BUILDING', 'BUSINESS', 'CALENDAR',
        'CAMPAIGN', 'CAPACITY', 'CAPTURED', 'CATEGORY', 'CHAIRMAN', 'CHAMPION', 'CHARACTER', 'CHEMICAL', 'CHILDREN', 'CHOCOLATE',
        'CIRCUIT', 'CITIZENS', 'CLEANING', 'CLINICAL', 'CLOTHING', 'COACHING', 'COCKTAIL', 'COLLAPSE', 'COLONIAL', 'COLUMN',
        'COMBINED', 'COMMENCE', 'COMMERCE', 'COMMISSION', 'COMMITTEE', 'COMMONLY', 'COMMUNICATE', 'COMMUNITY', 'COMPLETE', 'COMPUTER',
        'CONCERNED', 'CONCRETE', 'CONFLICT', 'CONFUSED', 'CONGRESS', 'CONSIDER', 'CONSTANT', 'CONSTRUCT', 'CONSUMER', 'CONTACT',
        'CONTINUE', 'CONTRACT', 'CONTRARY', 'CONTRAST', 'CONVINCE', 'COOKBOOK', 'CORRIDOR', 'COVERAGE', 'CREATIVE', 'CRIMINAL'
    ];

    /**
     * Generate a word for the game based on the seed
     */
    public function generateWord(string $seed, int $minLength = 4, int $maxLength = 12): string
    {
        // Filter words by length
        $filteredWords = array_filter($this->dictionary, function($word) use ($minLength, $maxLength) {
            $length = strlen($word);
            return $length >= $minLength && $length <= $maxLength;
        });

        // Use seed to get consistent word selection
        $seedNumber = abs(crc32($seed));
        $wordIndex = $seedNumber % count($filteredWords);
        $words = array_values($filteredWords);

        return $words[$wordIndex];
    }

    /**
     * Initialize a new hangman game
     */
    public function initializeGame(string $seed, array $config = []): array
    {
        $minLength = $config['min_word_length'] ?? 4;
        $maxLength = $config['max_word_length'] ?? 12;
        $maxWrongGuesses = $config['max_wrong_guesses'] ?? 7;

        $word = $this->generateWord($seed, $minLength, $maxLength);

        return [
            'word' => $word,
            'word_length' => strlen($word),
            'guessed_letters' => [],
            'correct_letters' => [],
            'wrong_letters' => [],
            'wrong_guesses' => 0,
            'max_wrong_guesses' => $maxWrongGuesses,
            'is_complete' => false,
            'is_won' => false,
            'score' => 0,
            'start_time' => now()->timestamp
        ];
    }

    /**
     * Process a letter guess
     */
    public function processGuess(array $gameData, string $letter): array
    {
        $letter = strtoupper(trim($letter));

        // Validate letter
        if (!preg_match('/^[A-Z]$/', $letter)) {
            throw new \InvalidArgumentException('Invalid letter provided');
        }

        // Check if letter already guessed
        if (in_array($letter, $gameData['guessed_letters'])) {
            throw new \InvalidArgumentException('Letter already guessed');
        }

        // Check if game is already complete
        if ($gameData['is_complete']) {
            throw new \InvalidArgumentException('Game is already complete');
        }

        // Add letter to guessed letters
        $gameData['guessed_letters'][] = $letter;

        // Check if letter is in the word
        if (strpos($gameData['word'], $letter) !== false) {
            // Correct guess
            $gameData['correct_letters'][] = $letter;
        } else {
            // Wrong guess
            $gameData['wrong_letters'][] = $letter;
            $gameData['wrong_guesses']++;
        }

        // Check win condition
        $gameData['is_won'] = $this->isWordComplete($gameData['word'], $gameData['correct_letters']);

        // Check if game is complete (won or lost)
        $gameData['is_complete'] = $gameData['is_won'] || $gameData['wrong_guesses'] >= $gameData['max_wrong_guesses'];

        return $gameData;
    }

    /**
     * Check if the word is completely guessed
     */
    private function isWordComplete(string $word, array $correctLetters): bool
    {
        $wordLetters = str_split($word);
        foreach ($wordLetters as $letter) {
            if (!in_array($letter, $correctLetters)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Calculate the score for a completed game
     */
    public function calculateScore(array $gameData, int $completionTimeSeconds, array $config = []): int
    {
        if (!$gameData['is_complete']) {
            return 0;
        }

        if (!$gameData['is_won']) {
            return 0; // No score for lost games
        }

        $scoring = $config['scoring'] ?? [
            'base_points' => 100,
            'letter_bonus' => 10,
            'wrong_guess_penalty' => 20,
            'time_bonus_max' => 50,
            'perfect_game_bonus' => 200
        ];

        $wordLength = strlen($gameData['word']);
        
        // Word length multiplier - longer words give significantly more points
        $lengthMultiplier = match($wordLength) {
            4 => 1.0,   // 4 letter words - base score
            5 => 1.5,   // 5 letter words - 50% bonus
            6 => 2.0,   // 6 letter words - 100% bonus
            7 => 2.8,   // 7 letter words - 180% bonus
            8 => 4.0,   // 8 letter words - 300% bonus
            default => 1.0
        };

        // Apply length multiplier to base score
        $score = $scoring['base_points'] * $lengthMultiplier;

        // Letter bonus (points per letter in word) - also scaled by length
        $score += $wordLength * $scoring['letter_bonus'] * $lengthMultiplier;

        // Wrong guess penalty (not multiplied - penalty stays consistent)
        $score -= $gameData['wrong_guesses'] * $scoring['wrong_guess_penalty'];

        // Perfect game bonus (no wrong guesses) - scaled by word length
        if ($gameData['wrong_guesses'] === 0) {
            $score += $scoring['perfect_game_bonus'] * $lengthMultiplier;
        }

        // Time bonus (faster completion = more points) - scaled by word length
        $timeLimit = $config['time_limit'] ?? 300;
        if ($completionTimeSeconds < $timeLimit) {
            $timeRemainingRatio = 1 - ($completionTimeSeconds / $timeLimit);
            $timeBonus = intval($scoring['time_bonus_max'] * $timeRemainingRatio * $lengthMultiplier);
            $score += $timeBonus;
        }

        return max(0, round($score)); // Ensure score is never negative and is an integer
    }

    /**
     * Get the current word state (revealed letters)
     */
    public function getWordState(string $word, array $correctLetters): array
    {
        $wordLetters = str_split($word);
        $revealed = [];

        foreach ($wordLetters as $index => $letter) {
            $revealed[] = [
                'position' => $index,
                'letter' => in_array($letter, $correctLetters) ? $letter : '_',
                'is_revealed' => in_array($letter, $correctLetters)
            ];
        }

        return $revealed;
    }

    /**
     * Validate a game completion for anti-cheat
     */
    public function validateCompletion(array $gameData, int $submittedScore, int $completionTime): array
    {
        try {
            // Basic validation
            if (!$gameData['is_complete']) {
                return [
                    'valid' => false,
                    'reason' => 'Game is not marked as complete'
                ];
            }

            // Check if wrong guesses count is valid
            if ($gameData['wrong_guesses'] < 0 || $gameData['wrong_guesses'] > 7) {
                return [
                    'valid' => false,
                    'reason' => 'Invalid wrong guesses count'
                ];
            }

            // For lost games, score should be 0
            if (!$gameData['is_won'] && $submittedScore > 0) {
                return [
                    'valid' => false,
                    'reason' => 'Score should be 0 for lost games'
                ];
            }

            // For won games, validate the word completion
            if ($gameData['is_won']) {
                $isActuallyComplete = $this->isWordComplete($gameData['word'], $gameData['correct_letters']);
                if (!$isActuallyComplete) {
                    return [
                        'valid' => false,
                        'reason' => 'Word is not actually complete'
                    ];
                }
            }

            // Validate completion time (minimum 10 seconds for a legitimate game)
            if ($completionTime < 10) {
                return [
                    'valid' => false,
                    'reason' => 'Completion time too fast'
                ];
            }

            // Calculate expected score and compare
            $expectedScore = $this->calculateScore($gameData, $completionTime);
            $scoreDifference = abs($submittedScore - $expectedScore);
            
            // Allow for small differences due to timing variations
            if ($scoreDifference > 10) {
                Log::warning('Hangman score mismatch', [
                    'expected' => $expectedScore,
                    'submitted' => $submittedScore,
                    'difference' => $scoreDifference,
                    'game_data' => $gameData
                ]);
                
                return [
                    'valid' => false,
                    'reason' => 'Score calculation mismatch'
                ];
            }

            return [
                'valid' => true,
                'expected_score' => $expectedScore
            ];

        } catch (\Exception $e) {
            Log::error('Hangman validation error', [
                'error' => $e->getMessage(),
                'game_data' => $gameData
            ]);

            return [
                'valid' => false,
                'reason' => 'Validation error'
            ];
        }
    }

    /**
     * Get hint for the current word (reveal a random unguessed letter)
     */
    public function getHint(array $gameData): ?string
    {
        $word = $gameData['word'];
        $correctLetters = $gameData['correct_letters'];
        
        // Get all unique letters in the word that haven't been guessed
        $wordLetters = array_unique(str_split($word));
        $unguessedLetters = array_diff($wordLetters, $correctLetters);
        
        if (empty($unguessedLetters)) {
            return null; // No hints available
        }
        
        // Return a random unguessed letter
        return $unguessedLetters[array_rand($unguessedLetters)];
    }

    /**
     * Get game statistics
     */
    public function getGameStats(array $gameData): array
    {
        return [
            'word_length' => strlen($gameData['word']),
            'total_guesses' => count($gameData['guessed_letters']),
            'correct_guesses' => count($gameData['correct_letters']),
            'wrong_guesses' => $gameData['wrong_guesses'],
            'letters_remaining' => strlen($gameData['word']) - count($gameData['correct_letters']),
            'is_complete' => $gameData['is_complete'],
            'is_won' => $gameData['is_won']
        ];
    }
} 
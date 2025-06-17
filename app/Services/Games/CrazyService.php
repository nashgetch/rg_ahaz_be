<?php

namespace App\Services\Games;

class CrazyService
{
    // Card constants
    const SUITS = ['hearts', 'diamonds', 'clubs', 'spades'];
    const SUIT_SYMBOLS = ['hearts' => '♥', 'diamonds' => '♦', 'clubs' => '♣', 'spades' => '♠'];
    const NUMBERS = ['A', '2', '3', '4', '5', '6', '7', '8', '9', '10', 'J', 'Q', 'K'];
    const SPECIAL_CARDS = ['2', '5', '8', 'J'];
    const PENALTY_CARDS = ['2', 'A♠'];
    const ACE_SPADES_PENALTY = 5;
    const DRAW_TWO_PENALTY = 2;
    
    /**
     * Generate two standard 52-card decks (no jokers)
     */
    public function generateDecks(): array
    {
        $deck = [];
        
        // Create two identical decks
        for ($deckNum = 1; $deckNum <= 2; $deckNum++) {
            foreach (self::SUITS as $suit) {
                foreach (self::NUMBERS as $number) {
                    $deck[] = [
                        'suit' => $suit,
                        'number' => $number,
                        'id' => $suit . '_' . $number . '_deck' . $deckNum
                    ];
                }
            }
        }
        
        // Shuffle the combined deck
        shuffle($deck);
        return $deck;
    }
    
    /**
     * Deal cards to players
     */
    public function dealCards(array $deck, int $playerCount, int $cardsPerPlayer): array
    {
        $hands = [];
        $deckIndex = 0;
        
        // Deal cards to each player
        for ($player = 0; $player < $playerCount; $player++) {
            $hands[$player] = [];
            $cardsToGet = $cardsPerPlayer;
            
            // Starter (first player) gets one extra card
            if ($player === 0) {
                $cardsToGet++;
            }
            
            for ($card = 0; $card < $cardsToGet; $card++) {
                if ($deckIndex < count($deck)) {
                    $hands[$player][] = $deck[$deckIndex];
                    $deckIndex++;
                }
            }
        }
        
        // Return hands and remaining deck
        return [
            'hands' => $hands,
            'remaining_deck' => array_slice($deck, $deckIndex)
        ];
    }
    
    /**
     * Check if a card can be played on the current discard pile
     */
    public function canPlayCard(array $card, array $currentCard, ?string $shapeOverride = null): bool
    {
        $currentSuit = $shapeOverride ?? $currentCard['suit'];
        
        // Special cards (8, J) can always be played without penalty
        if (in_array($card['number'], ['8', 'J'])) {
            return true;
        }
        
        // Same suit or same number
        return $card['suit'] === $currentSuit || $card['number'] === $currentCard['number'];
    }
    
    /**
     * Check if a card is a special action card
     */
    public function isSpecialCard(array $card): array
    {
        $effects = [];
        
        switch ($card['number']) {
            case '2':
                $effects[] = 'penalty_2';
                break;
            case '5':
                $effects[] = 'reverse_direction';
                break;
            case '7':
                $effects[] = 'skip_next';
                break;
            case '8':
            case 'J':
                $effects[] = 'change_suit';
                break;
        }
        
        // Ace of Spades special penalty - ALWAYS applies under any circumstances
        if ($card['number'] === 'A' && $card['suit'] === 'spades') {
            $effects[] = 'penalty_5';
            
            // Critical logging for A♠ detection
            \Log::info('ACE OF SPADES DETECTED', [
                'card' => $card,
                'effects_detected' => $effects,
                'penalty_value' => self::ACE_SPADES_PENALTY,
                'rule' => 'ALWAYS applies 5 card penalty under ANY circumstances'
            ]);
        }
        
        return $effects;
    }
    
    /**
     * Calculate penalty for dropping wrong card
     */
    public function calculatePenalty(array $invalidCard, array $currentCard, string $gameContext): array
    {
        $penalty = [
            'cards_to_draw' => 2,
            'reason' => 'wrong_suit_number',
            'disqualify' => false
        ];
        
        // Check for false joker (red on black or vice versa)
        if ($this->isFalseJoker($invalidCard, $currentCard)) {
            $penalty['cards_to_draw'] = 5;
            $penalty['reason'] = 'false_joker';
        }
        
        return $penalty;
    }
    
    /**
     * Check if it's a false joker drop
     */
    private function isFalseJoker(array $card, array $currentCard): bool
    {
        $redSuits = ['hearts', 'diamonds'];
        $blackSuits = ['clubs', 'spades'];
        
        $cardIsRed = in_array($card['suit'], $redSuits);
        $currentIsRed = in_array($currentCard['suit'], $redSuits);
        
        // False joker: red on black or black on red when suits don't match
        return ($cardIsRed !== $currentIsRed) && 
               ($card['suit'] !== $currentCard['suit']) && 
               ($card['number'] !== $currentCard['number']);
    }
    
    /**
     * Process 7-card combo drop
     */
    public function process7Combo(array $cards, array $playerHand): array
    {
        // Validate that first card is a 7
        if (empty($cards) || $cards[0]['number'] !== '7') {
            return ['valid' => false, 'reason' => 'must_start_with_7'];
        }
        
        $baseSuit = $cards[0]['suit'];
        
        // Check all cards are same suit as the 7
        foreach ($cards as $card) {
            if ($card['suit'] !== $baseSuit) {
                return ['valid' => false, 'reason' => 'all_cards_must_match_suit'];
            }
        }
        
        // Check player actually has all these cards
        foreach ($cards as $card) {
            if (!$this->playerHasCard($card, $playerHand)) {
                return ['valid' => false, 'reason' => 'player_doesnt_have_card'];
            }
        }
        
        return ['valid' => true, 'suit' => $baseSuit];
    }
    
    /**
     * Check if player has a specific card
     */
    private function playerHasCard(array $card, array $playerHand): bool
    {
        foreach ($playerHand as $handCard) {
            if ($handCard['id'] === $card['id']) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Check for penalty chain (2s and A♠)
     */
    public function isPenaltyChainCard(array $card): bool
    {
        return $card['number'] === '2' || 
               ($card['number'] === 'A' && $card['suit'] === 'spades');
    }
    
    /**
     * Calculate penalty chain value
     */
    public function getPenaltyChainValue(array $card): int
    {
        if ($card['number'] === '2') {
            return self::DRAW_TWO_PENALTY;
        }
        if ($card['number'] === 'A' && $card['suit'] === 'spades') {
            return self::ACE_SPADES_PENALTY;
        }
        return 0;
    }
    
    /**
     * Check if a penalty card can counter another penalty card
     * Rules:
     * - Ace of Spades can ONLY be countered by another Ace of Spades (strict rule)
     * - 2 can be countered by any other 2, or by A♠
     * - Different penalty types cannot counter each other (2♣ cannot counter A♠)
     */
    public function canCounterPenaltyCard(array $counterCard, array $currentCard): bool
    {
        // Both cards must be penalty cards
        if (!$this->isPenaltyChainCard($counterCard) || !$this->isPenaltyChainCard($currentCard)) {
            return false;
        }
        
        // A♠ can ONLY be countered by another A♠ - NO EXCEPTIONS
        if ($currentCard['number'] === 'A' && $currentCard['suit'] === 'spades') {
            return $counterCard['number'] === 'A' && $counterCard['suit'] === 'spades';
        }
        
        // 2 can be countered by any other 2, or by A♠
        if ($currentCard['number'] === '2') {
            return $counterCard['number'] === '2' || 
                   ($counterCard['number'] === 'A' && $counterCard['suit'] === 'spades');
        }
        
        return false;
    }
    
    /**
     * Get next player index based on direction
     */
    public function getNextPlayer(int $currentPlayer, int $totalPlayers, bool $clockwise, int $skipCount = 0): int
    {
        $direction = $clockwise ? 1 : -1;
        $nextPlayer = ($currentPlayer + $direction * (1 + $skipCount)) % $totalPlayers;
        
        // Handle negative modulo for counter-clockwise
        if ($nextPlayer < 0) {
            $nextPlayer += $totalPlayers;
        }
        
        return $nextPlayer;
    }
    
    /**
     * Calculate final score based on remaining cards and game performance  
     */
    public function calculateScore(array $playerHand, int $turnsPlayed, int $penaltiesIncurred, bool $won = false): int
    {
        $score = 0;
        
        if ($won) {
            // Base score for winning
            $score = 1000;
            
            // Bonus for fewer turns (faster win)
            $score += max(0, (50 - $turnsPlayed) * 10);
            
            // Penalty reduction bonus
            $score += max(0, (10 - $penaltiesIncurred) * 50);
            
        } else {
            // Score based on cards played (fewer remaining = higher score)
            $cardsRemaining = count($playerHand);
            $score = max(0, (6 - $cardsRemaining) * 50); // Max 6 cards (for host), give more points per card played
            
            // Small bonus for turns played (participation)
            $score += $turnsPlayed * 3;
            
            // Penalty for penalties incurred
            $score -= $penaltiesIncurred * 15;
        }
        
        return max(0, $score);
    }
    
    /**
     * Validate game state consistency
     */
    public function validateGameState(array $gameState): array
    {
        $errors = [];
        
        // Check required fields
        $required = ['players', 'current_player', 'direction', 'discard_pile', 'current_suit'];
        foreach ($required as $field) {
            if (!isset($gameState[$field])) {
                $errors[] = "Missing required field: {$field}";
            }
        }
        
        // Validate player count
        if (isset($gameState['players'])) {
            $playerCount = count($gameState['players']);
            if ($playerCount < 2 || $playerCount > 8) {
                $errors[] = "Invalid player count: {$playerCount}";
            }
        }
        
        // Validate current player index
        if (isset($gameState['current_player']) && isset($gameState['players'])) {
            $currentPlayer = $gameState['current_player'];
            $maxPlayer = count($gameState['players']) - 1;
            if ($currentPlayer < 0 || $currentPlayer > $maxPlayer) {
                $errors[] = "Invalid current player: {$currentPlayer}";
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * Initialize game state for a new room
     */
    public function initializeGame(array $participants, int $cardsPerPlayer = 5): array
    {
        $playerCount = count($participants);
        $deck = $this->generateDecks();
        $dealResult = $this->dealCards($deck, $playerCount, $cardsPerPlayer);
        
        // Initialize game state
        $gameState = [
            'deck' => $dealResult['remaining_deck'],
            'players' => [],
            'discard_pile' => [],
            'current_player' => 0, // Starter
            'direction' => false, // Counter-clockwise (Ethiopian style)
            'current_suit' => null,
            'penalty_chain' => 0,
            'penalty_target' => null,
            'turn_count' => 0,
            'phase' => 'playing' // playing, finished
        ];
        
        // Set up player data
        foreach ($participants as $index => $participant) {
            $gameState['players'][$index] = [
                'user_id' => $participant['user_id'],
                'username' => $participant['username'],
                'hand' => $dealResult['hands'][$index],
                'penalties' => 0,
                'mistakes' => 0,
                'said_qeregn' => false,
                'is_starter' => $index === 0
            ];
        }
        
        return $gameState;
    }
    
    /**
     * Check if suit change is allowed for 8/J
     */
    public function canChangeSuit(array $gameState, int $playerIndex): bool
    {
        // If this is the player's last card, they can't change suit (auto-win)
        if (count($gameState['players'][$playerIndex]['hand']) === 1) {
            \Log::info('Suit change blocked - last card auto-win', ['player_index' => $playerIndex]);
            return false;
        }
        
        // If no previous suit change, can always change suit
        if (!isset($gameState['last_suit_change'])) {
            \Log::info('Suit change allowed - no previous suit change', ['player_index' => $playerIndex]);
            return true;
        }
        
        $playerCount = count($gameState['players']);
        $lastChange = $gameState['last_suit_change'];
        
        // CRITICAL FIX: Only block suit changes if the previous change was caused by 8/J
        // If suit changed due to normal card play (same number), allow 8/J suit changes
        $changeType = $lastChange['change_type'] ?? 'unknown';
        
        // Backwards compatibility: if change_type is missing, assume it was 8/J (old behavior)
        if ($changeType !== '8_or_J' && $changeType !== 'unknown') {
            \Log::info('Suit change allowed - previous change was not caused by 8/J', [
                'player_index' => $playerIndex,
                'previous_change_type' => $changeType,
                'rule' => 'only_block_after_8_or_J_changes'
            ]);
            return true;
        }
        
        // Rule: The immediate next player after an 8/J suit change cannot change suits
        // Calculate who should be the next player after the suit changer (taking direction into account)
        $nextPlayerAfterSuitChange = $this->getNextPlayer(
            $lastChange['player_index'], 
            $playerCount, 
            !$gameState['direction'] // Game direction is inverted for getNextPlayer
        );
        
        // If current player is the immediate next player after 8/J suit change, block them
        if ($playerIndex === $nextPlayerAfterSuitChange) {
            \Log::info('Suit change BLOCKED - immediate next player after 8/J suit change', [
                'player_index' => $playerIndex,
                'suit_changer_index' => $lastChange['player_index'],
                'next_player_should_be' => $nextPlayerAfterSuitChange,
                'previous_change_type' => $changeType,
                'game_direction' => $gameState['direction'] ? 'clockwise' : 'counter-clockwise'
            ]);
            return false;
        }
        
        \Log::info('Suit change allowed - not immediate next player after 8/J change', [
            'player_index' => $playerIndex,
            'suit_changer_index' => $lastChange['player_index'],
            'next_player_should_be' => $nextPlayerAfterSuitChange,
            'previous_change_type' => $changeType,
            'game_direction' => $gameState['direction'] ? 'clockwise' : 'counter-clockwise'
        ]);
        return true;
    }

    /**
     * COMPREHENSIVE RULE VALIDATION SYSTEM
     * Validates that all critical game rules are being enforced correctly
     */
    public function validateGameRules(array $gameState, array $cardPlayed, int $playerIndex): array
    {
        $violations = [];
        $warnings = [];
        
        // Rule 1: Ace of Spades ALWAYS applies 5-card penalty
        if ($cardPlayed['number'] === 'A' && $cardPlayed['suit'] === 'spades') {
            if (!isset($gameState['penalty_chain']) || $gameState['penalty_chain'] < 5) {
                $violations[] = "CRITICAL: Ace of Spades penalty not applied! Expected penalty_chain >= 5, got: " . ($gameState['penalty_chain'] ?? 0);
            }
            if (!isset($gameState['penalty_target'])) {
                $violations[] = "CRITICAL: Ace of Spades penalty target not set!";
            }
        }
        
        // Rule 2: 15 XP token entry fee enforcement
        // (This would be checked in the ready/start logic, not here)
        
        // Rule 3: J/8 can be played without penalty
        if (in_array($cardPlayed['number'], ['8', 'J'])) {
            $effects = $this->isSpecialCard($cardPlayed);
            if (!in_array('change_suit', $effects)) {
                $violations[] = "CRITICAL: J/8 card not detected as change_suit effect!";
            }
        }
        
        // Rule 4: Immediate next player after 8/J suit change cannot change suits
        if (isset($gameState['last_suit_change']) && in_array($cardPlayed['number'], ['8', 'J'])) {
            $lastChange = $gameState['last_suit_change'];
            $changeType = $lastChange['change_type'] ?? 'unknown';
            
            // Only check this rule if the previous change was caused by 8/J
            // Backwards compatibility: if change_type is missing, assume it was 8/J (old behavior)
            if ($changeType === '8_or_J' || $changeType === 'unknown') {
                $playerCount = count($gameState['players']);
                $nextPlayerAfterSuitChange = $this->getNextPlayer(
                    $lastChange['player_index'], 
                    $playerCount, 
                    !$gameState['direction']
                );
                
                if ($playerIndex === $nextPlayerAfterSuitChange) {
                    // Check if they actually changed suits when they shouldn't have
                    $suitChangeOccurred = $gameState['_last_suit_change_occurred'] ?? false;
                    if ($suitChangeOccurred) {
                        $violations[] = "CRITICAL: Immediate next player after 8/J suit change was incorrectly allowed to change suits!";
                    }
                    // If no suit change occurred, that's CORRECT (suit change was properly blocked)
                }
            }
        }
        
        // Rule 5: 7 in two-player mode brings turn back to same player
        if ($cardPlayed['number'] === '7' && count($gameState['players']) === 2) {
            if ($gameState['current_player'] !== $playerIndex) {
                $violations[] = "CRITICAL: 7 in two-player mode should bring turn back to same player!";
            }
        }
        
        // Rule 6: 5 reverses direction and brings turn back in two-player mode
        if ($cardPlayed['number'] === '5' && count($gameState['players']) === 2) {
            if ($gameState['current_player'] !== $playerIndex) {
                $violations[] = "CRITICAL: 5 in two-player mode should bring turn back to same player!";
            }
        }
        
        // Rule 7: Penalty chain consistency
        if (isset($gameState['penalty_chain']) && $gameState['penalty_chain'] > 0) {
            if (!isset($gameState['penalty_target'])) {
                $violations[] = "CRITICAL: Penalty chain exists but no penalty target set!";
            }
        }
        
        return [
            'valid' => empty($violations),
            'violations' => $violations,
            'warnings' => $warnings,
            'card_analyzed' => $cardPlayed,
            'effects_detected' => $this->isSpecialCard($cardPlayed)
        ];
    }
} 
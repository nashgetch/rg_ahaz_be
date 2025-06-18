<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MultiplayerRoom;
use App\Models\MultiplayerParticipant;
use App\Services\Games\CrazyService;
use App\Services\StrictBettingService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class MultiplayerCrazyController extends Controller
{
    protected CrazyService $crazyService;
    protected StrictBettingService $bettingService;

    public function __construct(CrazyService $crazyService, StrictBettingService $bettingService)
    {
        $this->crazyService = $crazyService;
        $this->bettingService = $bettingService;
    }

    /**
     * Start multiplayer Crazy card game for a room
     */
    public function start(string $roomCode): JsonResponse
    {
        $room = MultiplayerRoom::with('game')->where('room_code', $roomCode)->first();

        if (!$room) {
            return response()->json([
                'success' => false,
                'message' => 'Room not found'
            ], 404);
        }

        if ($room->game->slug !== 'crazy') {
            return response()->json([
                'success' => false,
                'message' => 'This room is not for Crazy card game'
            ], 400);
        }

        $participant = $room->participants()->where('user_id', Auth::id())->first();

        if (!$participant) {
            return response()->json([
                'success' => false,
                'message' => 'You are not in this room'
            ], 400);
        }

        if ($room->status !== 'starting' && $room->status !== 'in_progress') {
            return response()->json([
                'success' => false,
                'message' => 'Game is not ready to start'
            ], 400);
        }

        // Initialize the game if not already started
        if (!isset($room->game_state['phase'])) {
            $participants = $room->participants()
                ->with('user:id,name')
                ->get()
                ->map(function ($p) {
                    return [
                        'user_id' => $p->user_id,
                        'username' => $p->user->name
                    ];
                })
                ->toArray();

            $gameState = $this->crazyService->initializeGame($participants);
            
            // Initialize empty discard pile - starter will play first card
            $gameState['discard_pile'] = [];
            $gameState['current_suit'] = null;
            
            $room->update([
                'status' => 'in_progress',
                'game_state' => $gameState
            ]);
        }

        // Initialize participant's game progress
        $gameState = $room->game_state;
        $playerIndex = $this->getPlayerIndex($gameState, Auth::id());
        
        if ($playerIndex !== -1) {
            $participant->updateProgress([
                'player_index' => $playerIndex,
                'cards_in_hand' => count($gameState['players'][$playerIndex]['hand']),
                'penalties' => $gameState['players'][$playerIndex]['penalties'],
                'started_at' => now()->toISOString()
            ]);
        }

        $totalPot = $room->participants()->sum('bet_amount');

        return response()->json([
            'success' => true,
            'message' => 'Game started successfully',
            'data' => [
                'room_code' => $room->room_code,
                'game_state' => $this->getPlayerGameState($room->game_state, Auth::id()),
                'total_pot' => $totalPot,
                'participants' => $room->participants()
                    ->with('user:id,name')
                    ->get()
                    ->map(function ($p) use ($room) {
                        $playerIndex = $this->getPlayerIndex($room->game_state, $p->user_id);
                        $playerData = $playerIndex !== -1 ? $room->game_state['players'][$playerIndex] : [];
                        
                        return [
                            'username' => $p->user->name,
                            'cards_count' => count($playerData['hand'] ?? []),
                            'penalties' => $playerData['penalties'] ?? 0,
                            'status' => $p->status,
                            'score' => $p->score,
                            'bet_amount' => $p->bet_amount
                        ];
                    })
            ]
        ]);
    }

    /**
     * Play a card or multiple cards
     */
    public function playCard(Request $request, string $roomCode): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'cards' => 'required|array|min:1',
            'cards.*.id' => 'required|string',
            'new_suit' => 'nullable|string|in:hearts,diamonds,clubs,spades'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid card data',
                'errors' => $validator->errors()
            ], 422);
        }

        $room = MultiplayerRoom::where('room_code', $roomCode)->first();

        if (!$room || $room->status !== 'in_progress') {
            return response()->json([
                'success' => false,
                'message' => 'Game is not active'
            ], 400);
        }

        $participant = $room->participants()->where('user_id', Auth::id())->first();

        if (!$participant || $participant->status === 'finished') {
            return response()->json([
                'success' => false,
                'message' => 'You cannot play cards at this time'
            ], 400);
        }

        $gameState = $room->game_state;
        $playerIndex = $this->getPlayerIndex($gameState, Auth::id());

        if ($playerIndex === -1) {
            return response()->json([
                'success' => false,
                'message' => 'Player not found in game'
            ], 400);
        }

        // Check if it's player's turn
        if ($gameState['current_player'] !== $playerIndex) {
            return response()->json([
                'success' => false,
                'message' => 'Not your turn'
            ], 400);
        }

        $cardsToPlay = $request->cards;
        $newSuit = $request->new_suit;

        // Process the card play
        $result = $this->processCardPlay($gameState, $playerIndex, $cardsToPlay, $newSuit);

        if (!$result['success']) {
            // Even if the play failed, we need to save the game state if it was modified (penalty cards added)
            if (isset($result['game_state'])) {
                $gameState = $result['game_state'];
                $room->update(['game_state' => $gameState]);
                
                // Update participant progress to reflect penalty cards
                $participant->updateProgress([
                    'cards_in_hand' => count($gameState['players'][$playerIndex]['hand']),
                    'penalties' => $gameState['players'][$playerIndex]['penalties'],
                    'last_action' => 'invalid_card_penalty',
                    'last_action_at' => now()->toISOString()
                ]);
            }
            
            return response()->json([
                'success' => false,
                'message' => $result['message'],
                'data' => [
                    'game_state' => $this->getPlayerGameState($gameState, Auth::id()),
                    'leaderboard' => $this->getRoomLeaderboard($room)
                ]
            ], 400);
        }

        // Update the game state
        $gameState = $result['game_state'];
        $room->update(['game_state' => $gameState]);

        // Update participant progress
        $participant->updateProgress([
            'cards_in_hand' => count($gameState['players'][$playerIndex]['hand']),
            'penalties' => $gameState['players'][$playerIndex]['penalties'],
            'last_action' => 'played_card',
            'last_action_at' => now()->toISOString()
        ]);

        // Check for game completion
        $this->checkGameCompletion($room);

        return response()->json([
            'success' => true,
            'data' => [
                'game_state' => $this->getPlayerGameState($gameState, Auth::id()),
                'message' => $result['message'],
                'leaderboard' => $this->getRoomLeaderboard($room)
            ]
        ]);
    }

    /**
     * Draw a card from the deck
     */
    public function drawCard(string $roomCode): JsonResponse
    {
        $room = MultiplayerRoom::where('room_code', $roomCode)->first();

        if (!$room || $room->status !== 'in_progress') {
            return response()->json([
                'success' => false,
                'message' => 'Game is not active'
            ], 400);
        }

        $participant = $room->participants()->where('user_id', Auth::id())->first();

        if (!$participant || $participant->status === 'finished') {
            return response()->json([
                'success' => false,
                'message' => 'You cannot draw cards at this time'
            ], 400);
        }

        $gameState = $room->game_state;
        $playerIndex = $this->getPlayerIndex($gameState, Auth::id());

        if ($playerIndex === -1 || $gameState['current_player'] !== $playerIndex) {
            return response()->json([
                'success' => false,
                'message' => 'Not your turn or player not found'
            ], 400);
        }

        // Check if player must face penalty chain
        if (isset($gameState['penalty_chain']) && $gameState['penalty_chain'] > 0 && 
            isset($gameState['penalty_target']) && $gameState['penalty_target'] === $playerIndex) {
            
            // Player cannot just draw - must handle penalty first
            return response()->json([
                'success' => false,
                'message' => 'You must handle the penalty first (play a 2, A♠, or face the penalty)'
            ], 400);
        }
        
        // Check if player has already drawn this turn
        if (isset($gameState['players'][$playerIndex]['has_drawn']) && $gameState['players'][$playerIndex]['has_drawn']) {
            return response()->json([
                'success' => false,
                'message' => 'You can only draw one card per turn'
            ], 400);
        }

        // Refill deck if empty
        $this->refillDeckIfEmpty($gameState);

        // Validate deck is not empty after refill
        if (empty($gameState['deck'])) {
            return response()->json([
                'success' => false,
                'message' => 'No more cards available'
            ], 400);
        }

        $drawnCard = array_shift($gameState['deck']);
        $gameState['players'][$playerIndex]['hand'][] = $drawnCard;
        
        // Mark that player has drawn (allows Yelegnm)
        $gameState['players'][$playerIndex]['has_drawn'] = true;

        // Don't automatically move to next player - let them play or pass
        $room->update(['game_state' => $gameState]);

        // Update participant progress
        $participant->updateProgress([
            'cards_in_hand' => count($gameState['players'][$playerIndex]['hand']),
            'last_action' => 'drew_card',
            'last_action_at' => now()->toISOString()
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'game_state' => $this->getPlayerGameState($gameState, Auth::id()),
                'message' => 'Card drawn',
                'leaderboard' => $this->getRoomLeaderboard($room)
            ]
        ]);
    }

    /**
     * Say "Qeregn" when down to 1 card (can be called anytime, not just on turn)
     */
    public function sayQeregn(string $roomCode): JsonResponse
    {
        $room = MultiplayerRoom::where('room_code', $roomCode)->first();

        if (!$room || $room->status !== 'in_progress') {
            return response()->json([
                'success' => false,
                'message' => 'Game is not active'
            ], 400);
        }

        $gameState = $room->game_state;
        $playerIndex = $this->getPlayerIndex($gameState, Auth::id());

        if ($playerIndex === -1) {
            return response()->json([
                'success' => false,
                'message' => 'Player not found in game'
            ], 400);
        }

        $playerHand = $gameState['players'][$playerIndex]['hand'];

        if (count($playerHand) !== 1) {
            return response()->json([
                'success' => false,
                'message' => 'Can only say Qeregn when you have exactly 1 card'
            ], 400);
        }

        if ($gameState['players'][$playerIndex]['said_qeregn']) {
            return response()->json([
                'success' => false,
                'message' => 'You have already said Qeregn'
            ], 400);
        }

        $gameState['players'][$playerIndex]['said_qeregn'] = true;
        
        // CRITICAL: Reset suit change restriction when Qeregn is declared
        // Qeregn is a significant game event that should clear suit change blocking
        if (isset($gameState['last_suit_change'])) {
            unset($gameState['last_suit_change']);
            \Log::info('Qeregn declared - suit change restriction reset', [
                'player_index' => $playerIndex,
                'player_name' => $gameState['players'][$playerIndex]['username'],
                'reason' => 'qeregn_declaration_resets_suit_change_restriction'
            ]);
        }
        
        // Add notification for all players
        $playerName = $gameState['players'][$playerIndex]['username'];
        $gameState['last_notification'] = [
            'type' => 'qeregn',
            'message' => "{$playerName} said Qeregn!",
            'player_index' => $playerIndex,
            'timestamp' => now()->toISOString()
        ];
        
        $room->update(['game_state' => $gameState]);

        return response()->json([
            'success' => true,
            'message' => 'Qeregn declared!',
            'data' => [
                'game_state' => $this->getPlayerGameState($gameState, Auth::id()),
                'notification' => $gameState['last_notification']
            ]
        ]);
    }

    /**
     * Call "Crazy" on a player who has 1 card but didn't say Qeregn
     */
    public function sayCrazy(Request $request, string $roomCode): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'target_player_index' => 'required|integer|min:0'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid target player',
                'errors' => $validator->errors()
            ], 422);
        }

        $room = MultiplayerRoom::where('room_code', $roomCode)->first();

        if (!$room || $room->status !== 'in_progress') {
            return response()->json([
                'success' => false,
                'message' => 'Game is not active'
            ], 400);
        }

        $gameState = $room->game_state;
        $callerIndex = $this->getPlayerIndex($gameState, Auth::id());
        $targetIndex = $request->target_player_index;

        if ($callerIndex === -1) {
            return response()->json([
                'success' => false,
                'message' => 'Player not found in game'
            ], 400);
        }

        if ($targetIndex >= count($gameState['players']) || $targetIndex < 0) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid target player'
            ], 400);
        }

        $targetPlayer = $gameState['players'][$targetIndex];

        // Check if target player has exactly 1 card and hasn't said Qeregn
        if (count($targetPlayer['hand']) !== 1) {
            return response()->json([
                'success' => false,
                'message' => 'Target player does not have exactly 1 card'
            ], 400);
        }

        if ($targetPlayer['said_qeregn']) {
            return response()->json([
                'success' => false,
                'message' => 'Target player already said Qeregn'
            ], 400);
        }

        // Apply penalty to target player
        for ($i = 0; $i < 2; $i++) {
            if (!empty($gameState['deck'])) {
                $gameState['players'][$targetIndex]['hand'][] = array_shift($gameState['deck']);
            }
        }
        $gameState['players'][$targetIndex]['penalties'] += 2;

        // Add notification
        $callerName = $gameState['players'][$callerIndex]['username'];
        $targetName = $gameState['players'][$targetIndex]['username'];
        $gameState['last_notification'] = [
            'type' => 'crazy',
            'message' => "{$callerName} called Crazy on {$targetName}! {$targetName} draws 2 cards.",
            'caller_index' => $callerIndex,
            'target_index' => $targetIndex,
            'timestamp' => now()->toISOString()
        ];

        $room->update(['game_state' => $gameState]);

        return response()->json([
            'success' => true,
            'message' => 'Crazy called successfully!',
            'data' => [
                'game_state' => $this->getPlayerGameState($gameState, Auth::id()),
                'notification' => $gameState['last_notification']
            ]
        ]);
    }

    /**
     * Face penalty when player cannot counter
     */
    public function facePenalty(string $roomCode): JsonResponse
    {
        $room = MultiplayerRoom::where('room_code', $roomCode)->first();

        if (!$room || $room->status !== 'in_progress') {
            return response()->json([
                'success' => false,
                'message' => 'Game is not active'
            ], 400);
        }

        $gameState = $room->game_state;
        $playerIndex = $this->getPlayerIndex($gameState, Auth::id());

        if ($playerIndex === -1) {
            return response()->json([
                'success' => false,
                'message' => 'Player not found in game'
            ], 400);
        }

        // Check if player is penalty target
        if (!isset($gameState['penalty_chain']) || $gameState['penalty_chain'] <= 0 || 
            !isset($gameState['penalty_target']) || $gameState['penalty_target'] !== $playerIndex) {
            return response()->json([
                'success' => false,
                'message' => 'You are not facing any penalty'
            ], 400);
        }

        // Refill deck if empty
        $this->refillDeckIfEmpty($gameState);
        
        // Apply penalty cards
        for ($i = 0; $i < $gameState['penalty_chain']; $i++) {
            if (!empty($gameState['deck'])) {
                $gameState['players'][$playerIndex]['hand'][] = array_shift($gameState['deck']);
            }
        }
        
        $gameState['players'][$playerIndex]['penalties'] += $gameState['penalty_chain'];
        $penaltyCount = $gameState['penalty_chain'];
        
        // Reset penalty chain
        $gameState['penalty_chain'] = 0;
        $gameState['penalty_target'] = null;
        
        // CRITICAL: Clear suit change restriction when blocked player faces penalty
        // Rule: If the immediate next player after an 8/J suit change faces penalty,
        // the suit change restriction should be lifted for subsequent players
        if (isset($gameState['last_suit_change'])) {
            $lastChange = $gameState['last_suit_change'];
            $changeType = $lastChange['change_type'] ?? 'unknown';
            
            // Only check this rule if the previous change was caused by 8/J
            if ($changeType === '8_or_J' || $changeType === 'unknown') {
                $playerCount = count($gameState['players']);
                $nextPlayerAfterSuitChange = $this->crazyService->getNextPlayer(
                    $lastChange['player_index'], 
                    $playerCount, 
                    !$gameState['direction']
                );
                
                // If the penalized player is the one who was blocked from changing suits, clear the restriction
                if ($playerIndex === $nextPlayerAfterSuitChange) {
                    unset($gameState['last_suit_change']);
                    \Log::info('Suit change restriction cleared - blocked player faced penalty', [
                        'player_index' => $playerIndex,
                        'player_name' => $gameState['players'][$playerIndex]['username'],
                        'previous_suit_changer' => $lastChange['player_index'],
                        'penalty_count' => $penaltyCount,
                        'rule' => 'When immediate next player after 8/J suit change faces penalty, restriction is lifted'
                    ]);
                }
            }
        }
        
        // DON'T move to next player - let them continue playing until turn ends naturally
        
        $room->update(['game_state' => $gameState]);

        return response()->json([
            'success' => true,
            'message' => 'Faced penalty and drew ' . $penaltyCount . ' cards. You can now play a card or pass.',
            'data' => [
                'game_state' => $this->getPlayerGameState($gameState, Auth::id()),
                'leaderboard' => $this->getRoomLeaderboard($room)
            ]
        ]);
    }

    /**
     * Say "Yelegnm" to pass turn (must draw first)
     */
    public function sayYelegnm(string $roomCode): JsonResponse
    {
        $room = MultiplayerRoom::where('room_code', $roomCode)->first();

        if (!$room || $room->status !== 'in_progress') {
            return response()->json([
                'success' => false,
                'message' => 'Game is not active'
            ], 400);
        }

        $gameState = $room->game_state;
        $playerIndex = $this->getPlayerIndex($gameState, Auth::id());

        if ($playerIndex === -1 || $gameState['current_player'] !== $playerIndex) {
            return response()->json([
                'success' => false,
                'message' => 'Not your turn or player not found'
            ], 400);
        }

        // Check if player must face penalty chain
        if (isset($gameState['penalty_chain']) && $gameState['penalty_chain'] > 0 && 
            isset($gameState['penalty_target']) && $gameState['penalty_target'] === $playerIndex) {
            
            // Player must face penalty - draw cards
            for ($i = 0; $i < $gameState['penalty_chain']; $i++) {
                if (!empty($gameState['deck'])) {
                    $gameState['players'][$playerIndex]['hand'][] = array_shift($gameState['deck']);
                }
            }
            
            $gameState['players'][$playerIndex]['penalties'] += $gameState['penalty_chain'];
            $penaltyCount = $gameState['penalty_chain'];
            
            // Reset penalty chain
            $gameState['penalty_chain'] = 0;
            $gameState['penalty_target'] = null;
            
            $room->update(['game_state' => $gameState]);
            
            return response()->json([
                'success' => true,
                'message' => 'Drew ' . $penaltyCount . ' penalty cards and passed turn',
                'data' => [
                    'game_state' => $this->getPlayerGameState($gameState, Auth::id()),
                    'leaderboard' => $this->getRoomLeaderboard($room)
                ]
            ]);
        }

        // Check if player has drawn a card first
        if (!isset($gameState['players'][$playerIndex]['has_drawn']) || 
            !$gameState['players'][$playerIndex]['has_drawn']) {
            return response()->json([
                'success' => false,
                'message' => 'You must draw a card before passing your turn'
            ], 400);
        }

        // CRITICAL: Clear suit change restriction when blocked player passes turn
        // Rule: If the immediate next player after an 8/J suit change passes their turn,
        // the suit change restriction should be lifted for subsequent players
        if (isset($gameState['last_suit_change'])) {
            $lastChange = $gameState['last_suit_change'];
            $changeType = $lastChange['change_type'] ?? 'unknown';
            
            // Only check this rule if the previous change was caused by 8/J
            if ($changeType === '8_or_J' || $changeType === 'unknown') {
                $playerCount = count($gameState['players']);
                $nextPlayerAfterSuitChange = $this->crazyService->getNextPlayer(
                    $lastChange['player_index'], 
                    $playerCount, 
                    !$gameState['direction']
                );
                
                // If the passing player is the one who was blocked from changing suits, clear the restriction
                if ($playerIndex === $nextPlayerAfterSuitChange) {
                    unset($gameState['last_suit_change']);
                    \Log::info('Suit change restriction cleared - blocked player passed turn', [
                        'player_index' => $playerIndex,
                        'player_name' => $gameState['players'][$playerIndex]['username'],
                        'previous_suit_changer' => $lastChange['player_index'],
                        'rule' => 'When immediate next player after 8/J suit change passes turn, restriction is lifted'
                    ]);
                }
            }
        }

        // Reset the has_drawn flag and move to next player
        $gameState['players'][$playerIndex]['has_drawn'] = false;
        $gameState['current_player'] = $this->crazyService->getNextPlayer(
            $gameState['current_player'],
            count($gameState['players']),
            !$gameState['direction']
        );

        $room->update(['game_state' => $gameState]);

        return response()->json([
            'success' => true,
            'message' => 'Turn passed',
            'data' => [
                'game_state' => $this->getPlayerGameState($gameState, Auth::id()),
                'leaderboard' => $this->getRoomLeaderboard($room)
            ]
        ]);
    }

    /**
     * Get current game status
     */
    public function status(string $roomCode): JsonResponse
    {
        $room = MultiplayerRoom::with('participants.user:id,name')
            ->where('room_code', $roomCode)
            ->first();

        if (!$room) {
            return response()->json([
                'success' => false,
                'message' => 'Room not found'
            ], 404);
        }

        // Check for game completion in case it was missed
        if ($room->game_state && isset($room->game_state['phase']) && $room->game_state['phase'] === 'finished' && $room->status !== 'completed') {
            $this->checkGameCompletion($room);
            $room->refresh(); // Reload room data after potential completion
        }

        // Calculate total pot - preserve original bet amount even after completion
        $totalPot = $room->total_bet_pool ?? $room->participants()->sum('bet_amount');
        // If total_bet_pool is 0 but there were bets, recalculate from participants
        if ($totalPot == 0 && $room->has_active_bets) {
            $totalPot = $room->participants()->sum('bet_amount');
        }

        // Get game state safely - handle null or invalid game state
        $gameState = null;
        if ($room->game_state && isset($room->game_state['players']) && is_array($room->game_state['players'])) {
            $gameState = $this->getPlayerGameState($room->game_state, Auth::id());
        }

        return response()->json([
            'success' => true,
            'data' => [
                'room_status' => $room->status,
                'game_state' => $gameState,
                'leaderboard' => $this->getRoomLeaderboard($room),
                'your_progress' => $this->getParticipantProgress($room, Auth::id()),
                'total_pot' => $totalPot,
                'betting_results' => $room->status === 'completed' && $room->final_scores ? [
                    'total_pot' => $totalPot,
                    'winner' => $room->final_scores[0] ?? null,
                    'has_betting' => $room->has_active_bets
                ] : null
            ]
        ]);
    }

    /**
     * Initiate a replay of the game (only host can do this)
     */
    public function initiateReplay(string $roomCode): JsonResponse
    {
        $room = MultiplayerRoom::where('room_code', $roomCode)->first();

        if (!$room) {
            return response()->json([
                'success' => false,
                'message' => 'Room not found'
            ], 404);
        }

        // Only the host can initiate a replay
        if ($room->host_user_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Only the host can initiate a replay'
            ], 403);
        }

        // Room must be completed to replay
        if ($room->status !== 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'Can only replay completed games'
            ], 400);
        }

        // Check if host has enough tokens for replay entry fee
        $user = Auth::user();
        $tokenCost = (int) $room->game->token_cost;
        
        if ($user->tokens_balance < $tokenCost) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient tokens to initiate replay. You need ' . $tokenCost . ' tokens to play.'
            ], 400);
        }

        // Get all non-host participants
        $participants = $room->participants()->where('user_id', '!=', Auth::id())->get();
        
        if ($participants->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No other players to invite for replay'
            ], 400);
        }

        // Reset room for replay
        $room->update([
            'status' => 'waiting',
            'game_state' => null,
            'started_at' => null,
            'completed_at' => null,
            'winner_user_id' => null,
            'final_scores' => null,
            'total_bet_pool' => 0,
            'current_players' => 1
        ]);

        // Remove all non-host participants
        $room->participants()->where('user_id', '!=', Auth::id())->delete();

        // Reset host participant
        $room->participants()->where('user_id', Auth::id())->update([
            'status' => 'joined',
            'score' => 0,
            'bet_amount' => 0,
            'locked_tokens' => 0,
            'game_progress' => null,
            'ready_at' => null,
            'finished_at' => null,
            'final_rank' => null
        ]);

        // Create new invitation records for all previous participants
        foreach ($participants as $participant) {
            \App\Models\MultiplayerParticipant::create([
                'room_id' => $room->id,
                'user_id' => $participant->user_id,
                'status' => 'invited',
                'joined_at' => now(),
                'is_host' => false,
                'bet_amount' => 0,
                'locked_tokens' => 0,
                'score' => 0
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Replay initiated. Invitations sent to all players. Mark yourself as ready to pay entry fee and start playing.',
            'data' => [
                'room_code' => $room->room_code,
                'status' => 'waiting',
                'invitations_sent' => $participants->count()
            ]
        ]);
    }

    /**
     * Get player index by user ID
     */
    private function getPlayerIndex(array $gameState, int $userId): int
    {
        if (!isset($gameState['players']) || !is_array($gameState['players'])) {
            return -1;
        }
        
        foreach ($gameState['players'] as $index => $player) {
            if ($player['user_id'] === $userId) {
                return $index;
            }
        }
        return -1;
    }

    /**
     * Get game state for a specific player (hide other players' cards)
     */
    private function getPlayerGameState(array $gameState, int $userId): array
    {
        $playerIndex = $this->getPlayerIndex($gameState, $userId);
        $playerGameState = $gameState;

        // Hide other players' cards
        foreach ($playerGameState['players'] as $index => &$player) {
            if ($index !== $playerIndex) {
                $cardCount = count($player['hand']);
                $player['hand'] = array_fill(0, $cardCount, ['hidden' => true]);
            }
        }

        return $playerGameState;
    }

    /**
     * Get room leaderboard
     */
    private function getRoomLeaderboard(MultiplayerRoom $room): array
    {
        if ($room->status === 'completed' && $room->final_scores) {
            return $room->final_scores;
        }

        return $room->participants()
            ->with('user:id,name')
            ->get()
            ->sortByDesc('score')
            ->values()
            ->map(function ($participant, $index) use ($room) {
                $playerIndex = -1;
                $playerData = [];
                
                if ($room->game_state && isset($room->game_state['players']) && is_array($room->game_state['players'])) {
                    $playerIndex = $this->getPlayerIndex($room->game_state, $participant->user_id);
                    $playerData = $playerIndex !== -1 ? $room->game_state['players'][$playerIndex] : [];
                }
                
                return [
                    'rank' => $index + 1,
                    'username' => $participant->user->name,
                    'score' => $participant->score,
                    'cards_remaining' => count($playerData['hand'] ?? []),
                    'penalties' => $playerData['penalties'] ?? 0,
                    'status' => $participant->status
                ];
            })
            ->toArray();
    }

    /**
     * Get participant progress
     */
    private function getParticipantProgress(MultiplayerRoom $room, int $userId): ?array
    {
        $participant = $room->participants()->where('user_id', $userId)->first();
        if (!$participant) {
            return null;
        }

        $progress = $participant->game_progress ?? [];
        
        if ($room->game_state && isset($room->game_state['players']) && is_array($room->game_state['players'])) {
            $playerIndex = $this->getPlayerIndex($room->game_state, $userId);
            
            if ($playerIndex !== -1) {
                $playerData = $room->game_state['players'][$playerIndex];
                $progress['cards_in_hand'] = count($playerData['hand'] ?? []);
                $progress['penalties'] = $playerData['penalties'] ?? 0;
                $progress['mistakes'] = $playerData['mistakes'] ?? 0;
            }
        }

        return $progress;
    }

    /**
     * Check if game is completed
     */
    private function checkGameCompletion(MultiplayerRoom $room): void
    {
        $gameState = $room->game_state;
        
        Log::info("checkGameCompletion called", [
            'room_code' => $room->room_code,
            'phase' => $gameState['phase'] ?? 'unknown',
            'room_status' => $room->status
        ]);
        
        if ($gameState['phase'] === 'finished') {
            // Prevent double execution - check if room is already completed
            if ($room->status === 'completed') {
                Log::info("Game already completed, skipping token distribution", [
                    'room_code' => $room->room_code
                ]);
                return;
            }
            
            DB::transaction(function () use ($room, $gameState) {
                $participants = $room->participants()->with('user')->get();
                
                // Find winner participant
                $winnerParticipant = null;
                $winnerPlayerIndex = $gameState['winner'] ?? -1;
                
                // Calculate final scores and mark ALL participants as finished
                foreach ($participants as $participant) {
                    $playerIndex = $this->getPlayerIndex($gameState, $participant->user_id);
                    if ($playerIndex !== -1) {
                        $playerData = $gameState['players'][$playerIndex];
                        $won = $playerIndex === $winnerPlayerIndex;
                        
                        $score = $this->crazyService->calculateScore(
                            $playerData['hand'],
                            $gameState['turn_count'],
                            $playerData['penalties'],
                            $won
                        );
                        
                        // Mark ALL participants as finished (not just winner)
                        $participant->update([
                            'completed_game' => true,
                            'score' => $score,
                            'finished_at' => now()
                        ]);
                        
                        if ($won) {
                            $winnerParticipant = $participant;
                        }
                    }
                }
                
                // Handle betting correctly (fixed implementation)
                // IMPORTANT: Token locking works as follows:
                // 1. When bet is placed: tokens stay in balance, but amount goes to locked_bet_tokens
                // 2. When game ends: 
                //    - Winner: gets OTHER players' bets added to balance, locked tokens cleared
                //    - Loser: gets their bet deducted from balance, locked tokens cleared
                // This ensures winner gets net +other_bets, loser gets net -their_bet
                $originalBetAmounts = [];
                $totalWinnings = 0;
                $isBettingGame = false;
                
                // Check if this is a betting game and collect bet amounts
                foreach ($participants as $p) {
                    // Check both bet_amount and user's locked_bet_tokens to determine betting
                    $betAmount = $p->bet_amount ?? 0;
                    $lockedTokens = $p->user->locked_bet_tokens ?? 0;
                    
                    // Use the higher of the two as the actual bet amount
                    $actualBetAmount = max($betAmount, $lockedTokens);
                    
                    if ($actualBetAmount > 0) {
                        $isBettingGame = true;
                    }
                    $originalBetAmounts[$p->user_id] = $actualBetAmount;
                }
                
                if ($isBettingGame) {
                    // Calculate total winnings from all bets
                    $totalWinnings = array_sum($originalBetAmounts);
                    $winnerBetAmount = $originalBetAmounts[$winnerParticipant->user_id] ?? 0;
                    
                    // Winner gets the FULL pot (winner-takes-all system)
                    $winnerWinnings = $totalWinnings;

                    Log::info("Crazy betting calculation", [
                        'room' => $room->room_code,
                        'total_pot' => $totalWinnings,
                        'winner_bet' => $winnerBetAmount,
                        'winner_winnings' => $winnerWinnings,
                        'all_bets' => $originalBetAmounts,
                        'note' => 'Winner takes all - gets full pot'
                    ]);

                    // 1. Award winner the FULL pot (winner-takes-all)
                    if ($winnerWinnings > 0 && $winnerParticipant) {
                        $balanceBefore = $winnerParticipant->user->tokens_balance;
                        
                        $winnerParticipant->user->awardTokens(
                            $winnerWinnings,
                            'prize',
                            "Multiplayer Crazy winner from room {$room->room_code}"
                        );
                        
                        Log::info("Awarded Crazy game winnings", [
                            'user_id' => $winnerParticipant->user_id, 
                            'amount' => $winnerWinnings, 
                            'room' => $room->room_code,
                            'balance_before' => $balanceBefore,
                            'balance_after' => $winnerParticipant->user->fresh()->tokens_balance,
                            'total_pot' => $totalWinnings,
                            'winner_bet' => $winnerBetAmount,
                            'note' => 'Winner takes all - gets full pot'
                        ]);
                    }

                    // 2. Process all participants - deduct losses and clear locks
                    foreach ($participants as $p) {
                        $user = $p->user;
                        $betAmount = $originalBetAmounts[$user->id] ?? 0;

                        if ($user->id !== $winnerParticipant->user_id && $betAmount > 0) {
                            $balanceBefore = $user->tokens_balance;
                            
                            // Deduct tokens from loser's balance and create transaction log
                            $user->decrement('tokens_balance', $betAmount);
                            $user->transactions()->create([
                                'amount' => -$betAmount,
                                'type' => 'powerup',
                                'description' => "Multiplayer Crazy loss in room {$room->room_code}",
                                'meta' => ['room_code' => $room->room_code, 'bet_loss' => true],
                                'status' => 'completed',
                            ]);
                            Log::info("Deducted tokens from loser", [
                                'user_id' => $user->id, 
                                'amount' => $betAmount, 
                                'room' => $room->room_code,
                                'balance_before' => $balanceBefore,
                                'balance_after' => $user->fresh()->tokens_balance
                            ]);
                        } else if ($user->id === $winnerParticipant->user_id) {
                            Log::info("Winner's bet NOT deducted (winner-takes-all)", [
                                'user_id' => $user->id, 
                                'bet_amount' => $betAmount, 
                                'room' => $room->room_code,
                                'note' => 'Winner keeps their bet + gets full pot'
                            ]);
                        }
                        
                        // Clear locked tokens for ALL participants (including winner)
                        if ($user->locked_bet_tokens > 0) {
                            $lockedAmount = $user->locked_bet_tokens;
                            $user->update(['locked_bet_tokens' => 0]);
                            Log::info("Cleared locked tokens", [
                                'user_id' => $user->id,
                                'amount_cleared' => $lockedAmount,
                                'is_winner' => $user->id === $winnerParticipant->user_id
                            ]);
                        }
                        if ($p->locked_tokens > 0) {
                            $p->update(['locked_tokens' => 0]);
                        }
                    }
                }
                
                // Calculate tokens awarded to winner (before using in closure)
                $tokensAwarded = 0;
                if ($winnerParticipant) {
                    // Remove score-based token rewards completely
                    // We only want to award tokens in betting games
                    Log::info("No score-based tokens awarded - tokens only awarded in betting games", [
                        'user_id' => $winnerParticipant->user_id,
                        'room' => $room->room_code,
                        'is_betting_game' => $isBettingGame
                    ]);
                }
                
                // Create final scores array with token changes (corrected calculation)
                $finalScores = $participants->sortByDesc('score')->values()->map(function ($participant, $index) use ($winnerParticipant, $originalBetAmounts, $totalWinnings, $isBettingGame, $gameState) {
                    $userId = $participant->user_id;
                    $betAmount = $originalBetAmounts[$userId] ?? 0;
                    $tokensChange = 0;
                    
                    if ($isBettingGame) {
                        if ($winnerParticipant && $userId === $winnerParticipant->user_id) {
                            // Winner gets full pot and keeps their own bet (winner-takes-all)
                            $tokensChange = $totalWinnings; // Full pot gained, no deduction
                        } else {
                            $tokensChange = -$betAmount; // Losers lose their bet amount
                        }
                    } else {
                        // No tokens awarded in non-betting games
                        $tokensChange = 0;
                    }
                    
                    $playerIndex = $this->getPlayerIndex($gameState, $userId);
                    $playerData = $playerIndex !== -1 ? $gameState['players'][$playerIndex] : [];
                    
                    return [
                        'user_id' => $userId,
                        'username' => $participant->user->name,
                        'score' => $participant->score,
                        'rank' => $index + 1,
                        'cards_remaining' => count($playerData['hand'] ?? []),
                        'penalties' => $playerData['penalties'] ?? 0,
                        'bet_amount' => $betAmount,
                        'tokens_change' => $tokensChange,
                        'finished_at' => $participant->finished_at?->toISOString(),
                        'status' => $participant->status
                    ];
                })->toArray();
                
                // Store final scores in the room
                $room->update([
                    'final_scores' => $finalScores,
                    'status' => 'completed',
                    'completed_at' => now(),
                    'winner_user_id' => $winnerParticipant ? $winnerParticipant->user_id : null,
                    'has_active_bets' => false, // Clear bet status
                    'total_bet_pool' => $isBettingGame ? $totalWinnings : 0 // Store final pot amount
                ]);
            });
            
            Log::info("Crazy card game completed", [
                'room_code' => $room->room_code,
                'winner' => $gameState['winner_name'] ?? 'none',
                'has_betting' => $room->has_active_bets,
                'total_pot' => $room->total_bet_pool
            ]);
        }
    }

    /**
     * Process card play logic
     */
    private function processCardPlay(array $gameState, int $playerIndex, array $cardsToPlay, ?string $newSuit): array
    {
        $player = $gameState['players'][$playerIndex];
        $currentCard = end($gameState['discard_pile']);

        // Check if this player is the penalty target
        if (isset($gameState['penalty_chain']) && $gameState['penalty_chain'] > 0 && 
            isset($gameState['penalty_target']) && $gameState['penalty_target'] === $playerIndex) {
            
            // Player must play a penalty card or draw penalty cards
            $cardToPlay = $cardsToPlay[0];
            $actualCard = null;
            foreach ($player['hand'] as $handCard) {
                if ($handCard['id'] === $cardToPlay['id']) {
                    $actualCard = $handCard;
                    break;
                }
            }

            if (!$actualCard) {
                return ['success' => false, 'message' => 'Card not found in your hand'];
            }

            // Check if they can counter with a penalty card
            if ($this->crazyService->isPenaltyChainCard($actualCard)) {
                // Check if this penalty card can actually counter the current penalty chain
                $lastPenaltyCard = end($gameState['discard_pile']);
                if ($this->crazyService->canCounterPenaltyCard($actualCard, $lastPenaltyCard)) {
                    // Can counter - but CRITICAL: Ace of Spades must ALWAYS process through normal logic
                    // to ensure its penalty is applied correctly. Only handle 2s here for simple countering.
                    
                    if ($actualCard['number'] === 'A' && $actualCard['suit'] === 'spades') {
                        // ACE OF SPADES: Reset penalty chain and let normal logic handle it
                        // This ensures A♠ ALWAYS applies its 5-card penalty regardless of circumstances
                        $gameState['penalty_chain'] = 0;
                        $gameState['penalty_target'] = null;
                        
                        \Log::info('Ace of Spades detected - resetting penalty chain to process through normal logic', [
                            'player_index' => $playerIndex,
                            'old_penalty_chain' => $gameState['penalty_chain'],
                            'will_be_processed_normally' => true
                        ]);
                        
                        // FALL THROUGH to normal card processing logic
                        // Do NOT return early - let processSpecialCardEffects handle A♠
                    } else {
                        // Regular penalty card (2) - handle normally
                        $gameState['penalty_chain'] += $this->crazyService->getPenaltyChainValue($actualCard);
                        $gameState['penalty_target'] = $this->crazyService->getNextPlayer(
                            $playerIndex, count($gameState['players']), !$gameState['direction']
                        );
                        
                        // Remove card and add to discard pile
                        $gameState['players'][$playerIndex]['hand'] = array_filter(
                            $gameState['players'][$playerIndex]['hand'],
                            function($card) use ($actualCard) {
                                return $card['id'] !== $actualCard['id'];
                            }
                        );
                        $gameState['players'][$playerIndex]['hand'] = array_values($gameState['players'][$playerIndex]['hand']);
                        $gameState['discard_pile'][] = $actualCard;
                        
                        // Update current suit to the penalty card's suit
                        $gameState['current_suit'] = $actualCard['suit'];
                        
                        // Check for win condition
                        if (empty($gameState['players'][$playerIndex]['hand'])) {
                            $gameState['phase'] = 'finished';
                            $gameState['winner'] = $playerIndex;
                            $gameState['winner_name'] = $gameState['players'][$playerIndex]['username'];
                            
                            return [
                                'success' => true,
                                'message' => 'Game won with penalty card!',
                                'game_state' => $gameState
                            ];
                        }
                        
                        // Move to next player
                        $gameState['current_player'] = $gameState['penalty_target'];
                        
                        return [
                            'success' => true,
                            'message' => 'Penalty passed to next player (+' . $this->crazyService->getPenaltyChainValue($actualCard) . ')',
                            'game_state' => $gameState
                        ];
                    }
                } else {
                    // Cannot counter with this penalty card - treat as invalid play
                    // Refill deck if empty
                    $this->refillDeckIfEmpty($gameState);
                    
                    // Draw penalty cards for invalid penalty counter
                    for ($i = 0; $i < 2; $i++) { // 2 cards for invalid penalty attempt
                        if (!empty($gameState['deck'])) {
                            $gameState['players'][$playerIndex]['hand'][] = array_shift($gameState['deck']);
                        }
                    }
                    
                    $gameState['players'][$playerIndex]['mistakes']++;
                    $gameState['players'][$playerIndex]['penalties'] += 2;
                    
                    return [
                        'success' => false,
                        'message' => 'Invalid penalty card. Drew 2 penalty cards. (Cannot counter ' . $lastPenaltyCard['number'] . $lastPenaltyCard['suit'] . ' with ' . $actualCard['number'] . $actualCard['suit'] . ')',
                        'game_state' => $gameState
                    ];
                }
            } else {
                // Cannot counter - must draw penalty cards
                for ($i = 0; $i < $gameState['penalty_chain']; $i++) {
                    if (!empty($gameState['deck'])) {
                        $gameState['players'][$playerIndex]['hand'][] = array_shift($gameState['deck']);
                    }
                }
                
                $gameState['players'][$playerIndex]['penalties'] += $gameState['penalty_chain'];
                $penaltyCount = $gameState['penalty_chain'];
                
                // Reset penalty chain
                $gameState['penalty_chain'] = 0;
                $gameState['penalty_target'] = null;
                
                // Player can still play a valid card after drawing penalty
                // Continue with normal play logic but don't move to next player yet
                return [
                    'success' => true,
                    'message' => 'Drew ' . $penaltyCount . ' penalty cards. You can now play a card.',
                    'game_state' => $gameState
                ];
            }
        }

        // Handle multiple card plays (7 combos or identical cards)
        if (count($cardsToPlay) > 1) {
            return $this->processMultiCardPlay($gameState, $playerIndex, $cardsToPlay);
        }

        $cardToPlay = $cardsToPlay[0];
        
        // Find the actual card in player's hand
        $actualCard = null;
        foreach ($player['hand'] as $handCard) {
            if ($handCard['id'] === $cardToPlay['id']) {
                $actualCard = $handCard;
                break;
            }
        }

        if (!$actualCard) {
            return ['success' => false, 'message' => 'Card not found in your hand'];
        }

        // Check if card can be played (first card can always be played)
        $canPlay = empty($gameState['discard_pile']) || $this->crazyService->canPlayCard($actualCard, $currentCard, $gameState['current_suit']);
        
        // Debug logging (temporarily enabled)
        \Log::info('Card validation debug', [
            'played_card' => $actualCard,
            'current_card' => $currentCard,
            'current_suit' => $gameState['current_suit'],
            'can_play' => $canPlay,
            'is_first_card' => empty($gameState['discard_pile']),
            'validation_logic' => $currentCard ? [
                'is_8_or_J' => in_array($actualCard['number'], ['8', 'J']),
                'same_suit' => $actualCard['suit'] === $gameState['current_suit'],
                'same_number' => $actualCard['number'] === $currentCard['number'],
                'suit_comparison' => $actualCard['suit'] . ' vs ' . $gameState['current_suit'],
                'number_comparison' => $actualCard['number'] . ' vs ' . $currentCard['number']
            ] : ['first_card' => 'any card allowed']
        ]);
        
        if (!$canPlay) {
            // Invalid play - apply penalty
            $penalty = $this->crazyService->calculatePenalty($actualCard, $currentCard, 'normal_play');
            
            // Refill deck if empty
            $this->refillDeckIfEmpty($gameState);
            
            // Draw penalty cards
            for ($i = 0; $i < $penalty['cards_to_draw']; $i++) {
                if (!empty($gameState['deck'])) {
                    $gameState['players'][$playerIndex]['hand'][] = array_shift($gameState['deck']);
                }
            }
            
            $gameState['players'][$playerIndex]['mistakes']++;
            $gameState['players'][$playerIndex]['penalties'] += $penalty['cards_to_draw'];
            
            $currentCardInfo = $currentCard ? $currentCard['number'] . $currentCard['suit'] : 'empty pile';
            return [
                'success' => false, 
                'message' => 'Invalid card play. Drew ' . $penalty['cards_to_draw'] . ' penalty cards. (Played: ' . $actualCard['number'] . $actualCard['suit'] . ' on ' . $currentCardInfo . ', Current suit: ' . ($gameState['current_suit'] ?? 'none') . ')',
                'game_state' => $gameState  // Return the modified game state with penalty cards
            ];
        }

        // Valid play - remove card from hand
        $gameState['players'][$playerIndex]['hand'] = array_filter(
            $gameState['players'][$playerIndex]['hand'],
            function($card) use ($actualCard) {
                return $card['id'] !== $actualCard['id'];
            }
        );
        $gameState['players'][$playerIndex]['hand'] = array_values($gameState['players'][$playerIndex]['hand']); // Reindex

        // Add to discard pile
        $gameState['discard_pile'][] = $actualCard;

        // CRITICAL: Clear suit change restriction when non-8/J card is successfully played
        // Rule: After any successful non-8/J card play following a suit change, the 8/J restriction should be lifted
        // For 8/J cards, we handle restriction clearing in processSpecialCardEffects after checking if change is allowed
        if (isset($gameState['last_suit_change']) && !in_array($actualCard['number'], ['8', 'J'])) {
            $lastChange = $gameState['last_suit_change'];
            $changeType = $lastChange['change_type'] ?? 'unknown';
            
            // Only clear if the previous change was caused by 8/J (preserve normal card suit changes)
            if ($changeType === '8_or_J' || $changeType === 'unknown') {
                unset($gameState['last_suit_change']);
                \Log::info('Suit change restriction cleared - non-8/J card successfully played after 8/J suit change', [
                    'player_index' => $playerIndex,
                    'player_name' => $gameState['players'][$playerIndex]['username'],
                    'card_played' => $actualCard['number'] . $actualCard['suit'],
                    'previous_suit_changer' => $lastChange['player_index'],
                    'rule' => 'Any successful non-8/J card play after 8/J suit change lifts the restriction'
                ]);
            }
        }

        // CRITICAL: Check if this is Ace of Spades before processing
        if ($actualCard['number'] === 'A' && $actualCard['suit'] === 'spades') {
            \Log::info('CRITICAL: Ace of Spades detected in main flow', [
                'player_index' => $playerIndex,
                'player_name' => $gameState['players'][$playerIndex]['username'],
                'current_penalty_chain' => $gameState['penalty_chain'] ?? 0,
                'about_to_process_effects' => true
            ]);
        }

        // Process special card effects FIRST (including penalties from A♠, 2s, etc.)
        // This ensures penalties are ALWAYS applied regardless of win condition
        $gameState = $this->processSpecialCardEffects($gameState, $actualCard, $playerIndex, $newSuit);
        
        // COMPREHENSIVE RULE VALIDATION - Ensure ALL rules are enforced
        $ruleValidation = $this->crazyService->validateGameRules($gameState, $actualCard, $playerIndex);
        
        // Clean up temporary tracking variables
        unset($gameState['_last_suit_change_occurred']);
        
        if (!$ruleValidation['valid']) {
            \Log::critical('GAME RULE VIOLATIONS DETECTED', [
                'violations' => $ruleValidation['violations'],
                'warnings' => $ruleValidation['warnings'],
                'card_played' => $actualCard,
                'player_index' => $playerIndex,
                'game_state_penalty' => $gameState['penalty_chain'] ?? 0,
                'game_state_target' => $gameState['penalty_target'] ?? null
            ]);
            
            // For production, we should fix these violations automatically
            // For now, log them for debugging
        }
        
        // VERIFY: Check if A♠ penalty was applied
        if ($actualCard['number'] === 'A' && $actualCard['suit'] === 'spades') {
            \Log::info('VERIFICATION: Ace of Spades after processing', [
                'player_index' => $playerIndex,
                'penalty_chain_after' => $gameState['penalty_chain'] ?? 0,
                'penalty_target' => $gameState['penalty_target'] ?? null,
                'target_player_name' => isset($gameState['penalty_target']) ? $gameState['players'][$gameState['penalty_target']]['username'] : 'none',
                'effects_applied' => $this->crazyService->isSpecialCard($actualCard),
                'rule_validation' => $ruleValidation
            ]);
        }

        // Check for win condition AFTER processing special effects
        if (empty($gameState['players'][$playerIndex]['hand'])) {
            $gameState['phase'] = 'finished';
            $gameState['winner'] = $playerIndex;
            $gameState['winner_name'] = $gameState['players'][$playerIndex]['username'];
            
            // For 8/J as last card, set suit to card's own suit (no selection needed)
            if (in_array($actualCard['number'], ['8', 'J'])) {
                $gameState['current_suit'] = $actualCard['suit'];
            }
            
            // Special message if won with penalty card
            $effects = $this->crazyService->isSpecialCard($actualCard);
            $wonWithPenalty = in_array('penalty_2', $effects) || in_array('penalty_5', $effects);
            $message = $wonWithPenalty ? 'Game won with penalty card! Next player still faces penalty.' : 'Game won!';
            
            return [
                'success' => true,
                'message' => $message,
                'game_state' => $gameState
            ];
        }

        // Check if player is down to 1 card (notify others and enable Qeregn/Crazy)
        if (count($gameState['players'][$playerIndex]['hand']) === 1) {
            $playerName = $gameState['players'][$playerIndex]['username'];
            $gameState['last_notification'] = [
                'type' => 'one_card',
                'message' => "{$playerName} has only 1 card left!",
                'player_index' => $playerIndex,
                'timestamp' => now()->toISOString()
            ];
            
            // Reset Qeregn status for this player (they can declare again)
            $gameState['players'][$playerIndex]['said_qeregn'] = false;
        }

        // Reset has_drawn flag since player played a card
        $gameState['players'][$playerIndex]['has_drawn'] = false;
        
        // Increment turn count for suit change tracking
        $gameState['turn_count'] = ($gameState['turn_count'] ?? 0) + 1;
        
        // Note: cards_played_since tracking removed - immediate next player rule is permanent

        // Turn advancement is handled by processSpecialCardEffects for all cards
        // No additional turn advancement needed here

        return [
            'success' => true,
            'message' => 'Card played successfully',
            'game_state' => $gameState
        ];
    }

    /**
     * Process multiple card play (7 combos or identical pairs)
     */
    private function processMultiCardPlay(array $gameState, int $playerIndex, array $cardsToPlay): array
    {
        // Check if it's a valid 7 combo
        $first = $cardsToPlay[0];
        if ($first['number'] === '7') {
            $result = $this->crazyService->process7Combo($cardsToPlay, $gameState['players'][$playerIndex]['hand']);
            if (!$result['valid']) {
                return ['success' => false, 'message' => $result['reason']];
            }
        } else {
            // Check if all cards are identical (same number and suit)
            foreach ($cardsToPlay as $card) {
                if ($card['number'] !== $first['number'] || $card['suit'] !== $first['suit']) {
                    return ['success' => false, 'message' => 'All cards must be identical for multiple card play'];
                }
            }
        }

        // Remove all cards from hand
        foreach ($cardsToPlay as $cardToPlay) {
            $gameState['players'][$playerIndex]['hand'] = array_filter(
                $gameState['players'][$playerIndex]['hand'],
                function($card) use ($cardToPlay) {
                    return $card['id'] !== $cardToPlay['id'];
                }
            );
        }
        $gameState['players'][$playerIndex]['hand'] = array_values($gameState['players'][$playerIndex]['hand']);

        // Add all cards to discard pile
        foreach ($cardsToPlay as $card) {
            $gameState['discard_pile'][] = $card;
        }

        // CRITICAL: Clear suit change restriction when non-8/J cards are successfully played
        // Rule: After any successful non-8/J card play following a suit change, the 8/J restriction should be lifted
        // For 8/J cards, we handle restriction clearing in processSpecialCardEffects after checking if change is allowed
        $lastCard = end($cardsToPlay);
        if (isset($gameState['last_suit_change']) && !in_array($lastCard['number'], ['8', 'J'])) {
            $lastChange = $gameState['last_suit_change'];
            $changeType = $lastChange['change_type'] ?? 'unknown';
            
            // Only clear if the previous change was caused by 8/J (preserve normal card suit changes)
            if ($changeType === '8_or_J' || $changeType === 'unknown') {
                unset($gameState['last_suit_change']);
                \Log::info('Suit change restriction cleared - non-8/J multiple cards successfully played after 8/J suit change', [
                    'player_index' => $playerIndex,
                    'player_name' => $gameState['players'][$playerIndex]['username'],
                    'cards_played_count' => count($cardsToPlay),
                    'cards_played' => array_map(function($c) { return $c['number'] . $c['suit']; }, $cardsToPlay),
                    'previous_suit_changer' => $lastChange['player_index'],
                    'rule' => 'Any successful non-8/J card play after 8/J suit change lifts the restriction'
                ]);
            }
        }

        // Reset has_drawn flag since player played cards
        $gameState['players'][$playerIndex]['has_drawn'] = false;
        
        // Process effects of the last played card FIRST (including penalties)
        $lastCard = end($cardsToPlay);
        $gameState = $this->processSpecialCardEffects($gameState, $lastCard, $playerIndex);

        // Check for win condition AFTER processing special effects
        if (empty($gameState['players'][$playerIndex]['hand'])) {
            $gameState['phase'] = 'finished';
            $gameState['winner'] = $playerIndex;
            $gameState['winner_name'] = $gameState['players'][$playerIndex]['username'];
            
            // Special message if won with penalty card
            $effects = $this->crazyService->isSpecialCard($lastCard);
            $wonWithPenalty = in_array('penalty_2', $effects) || in_array('penalty_5', $effects);
            $message = $wonWithPenalty ? 'Game won with penalty card! Next player still faces penalty.' : 'Game won!';
            
            return [
                'success' => true,
                'message' => $message,
                'game_state' => $gameState
            ];
        }
        
        // Increment turn count for suit change tracking
        $gameState['turn_count'] = ($gameState['turn_count'] ?? 0) + 1;
        
        // Note: cards_played_since tracking removed - immediate next player rule is permanent

        return [
            'success' => true,
            'message' => count($cardsToPlay) . ' cards played successfully',
            'game_state' => $gameState
        ];
    }

    /**
     * Process special card effects
     */
    private function processSpecialCardEffects(array $gameState, array $card, int $playerIndex, ?string $newSuit = null): array
    {
        $playerCount = count($gameState['players']);
        $cardNumber = $card['number'];
        
        // Track last card played for two-player mode suit change logic
        $gameState['last_card_played'] = $card;
        
        // Process special card effects
        $effects = $this->crazyService->isSpecialCard($card);
        $turnAdvanced = false;  // Flag to track if turn has been advanced
        
        foreach ($effects as $effect) {
            switch ($effect) {
                case 'penalty_2':
                    $gameState['penalty_chain'] += 2;
                    $gameState['penalty_target'] = $this->crazyService->getNextPlayer(
                        $playerIndex, $playerCount, !$gameState['direction']
                    );
                    $gameState['current_player'] = $gameState['penalty_target'];
                    $turnAdvanced = true;
                    break;

                case 'penalty_5':
                    // Ace of Spades ALWAYS applies 5 card penalty under any circumstances
                    $gameState['penalty_chain'] += 5;
                    $gameState['penalty_target'] = $this->crazyService->getNextPlayer(
                        $playerIndex, $playerCount, !$gameState['direction']
                    );
                    $gameState['current_player'] = $gameState['penalty_target'];
                    $turnAdvanced = true;
                    
                    // Log for debugging
                    \Log::info('Ace of Spades penalty applied', [
                        'player' => $gameState['players'][$playerIndex]['username'],
                        'penalty_chain' => $gameState['penalty_chain'],
                        'penalty_target' => $gameState['penalty_target'],
                        'target_player' => $gameState['players'][$gameState['penalty_target']]['username']
                    ]);
                    break;

                case 'change_suit':
                    $originalSuit = $gameState['current_suit'] ?? null;
                    $suitChangeOccurred = false;
                    
                    if ($this->crazyService->canChangeSuit($gameState, $playerIndex)) {
                        if ($newSuit && in_array($newSuit, ['hearts', 'diamonds', 'clubs', 'spades'])) {
                            // Player selected a valid new suit and is allowed to change
                            $gameState['current_suit'] = $newSuit;
                            $gameState['last_suit_change'] = [
                                'player_index' => $playerIndex,
                                'turn_count' => $gameState['turn_count'] ?? 0,
                                'cards_played_since' => 0,
                                'change_type' => '8_or_J' // Track that this was caused by 8/J
                            ];
                            
                            \Log::info('Suit changed due to 8/J card with selected suit', [
                                'player_index' => $playerIndex,
                                'old_suit' => $originalSuit,
                                'new_suit' => $newSuit,
                                'card_played' => $card['number'] . $card['suit'],
                                'change_type' => '8_or_J',
                                'rule' => 'This type of suit change WILL block immediate next player from using 8/J'
                            ]);
                            
                            $suitChangeOccurred = true;
                        } else {
                            // No valid suit selected (or null for "keep current"), use card's suit as default
                            // This happens when player is allowed to change suit but didn't select one
                            $gameState['current_suit'] = $card['suit'];
                            $gameState['last_suit_change'] = [
                                'player_index' => $playerIndex,
                                'turn_count' => $gameState['turn_count'] ?? 0,
                                'cards_played_since' => 0,
                                'change_type' => '8_or_J' // Track that this was caused by 8/J
                            ];
                            
                            \Log::info('No suit selected, defaulting to card suit', [
                                'player_index' => $playerIndex,
                                'old_suit' => $originalSuit,
                                'new_suit' => $card['suit'],
                                'card_played' => $card['number'] . $card['suit'],
                                'change_type' => '8_or_J',
                                'rule' => 'Default suit change when allowed but no selection made'
                            ]);
                            
                            $suitChangeOccurred = true;
                        }
                    } else {
                        // CRITICAL: When suit change is blocked, current suit must remain unchanged!
                        // Do NOT set current_suit here - leave it as is
                        $suitChangeOccurred = false;
                        
                        // IMPORTANT: When blocked player attempts 8/J suit change, clear the restriction
                        // This allows subsequent players to change suits with their 8/J cards
                        if (isset($gameState['last_suit_change'])) {
                            $lastChange = $gameState['last_suit_change'];
                            $changeType = $lastChange['change_type'] ?? 'unknown';
                            
                            // Only clear if this was a blocked attempt after an 8/J suit change
                            if ($changeType === '8_or_J' || $changeType === 'unknown') {
                                $playerCount = count($gameState['players']);
                                $nextPlayerAfterSuitChange = $this->crazyService->getNextPlayer(
                                    $lastChange['player_index'], 
                                    $playerCount, 
                                    !$gameState['direction']
                                );
                                
                                // If the blocked player attempted to change suits, clear restriction for others
                                if ($playerIndex === $nextPlayerAfterSuitChange) {
                                    unset($gameState['last_suit_change']);
                                    \Log::info('Suit change restriction cleared - blocked player attempted 8/J suit change', [
                                        'player_index' => $playerIndex,
                                        'player_name' => $gameState['players'][$playerIndex]['username'],
                                        'card_played' => $card['number'] . $card['suit'],
                                        'previous_suit_changer' => $lastChange['player_index'],
                                        'rule' => 'When blocked player attempts 8/J suit change, restriction is lifted for subsequent players'
                                    ]);
                                }
                            }
                        }
                        
                        \Log::info('8/J suit change blocked - current suit remains unchanged', [
                            'player_index' => $playerIndex,
                            'player_name' => $gameState['players'][$playerIndex]['username'],
                            'current_suit_stays' => $gameState['current_suit'],
                            'card_played' => $card['number'] . $card['suit'],
                            'attempted_new_suit' => $newSuit ?? 'none',
                            'suit_change_occurred' => false,
                            'rule' => 'Immediate next player after 8/J suit change cannot change suits'
                        ]);
                    }
                    
                    // Track if suit change occurred for validation
                    $gameState['_last_suit_change_occurred'] = $suitChangeOccurred;
                    
                    if (!$turnAdvanced) {
                        $gameState['current_player'] = $this->crazyService->getNextPlayer(
                            $playerIndex, $playerCount, !$gameState['direction']
                        );
                        $turnAdvanced = true;
                    }
                    break;

                case 'reverse_direction':
                    $gameState['direction'] = !$gameState['direction'];
                    $newDirection = $gameState['direction'] ? 'clockwise' : 'counter-clockwise';
                    $gameState['current_suit'] = $card['suit'];
                    
                    if ($playerCount === 2) {
                        // Two players: 5 brings turn back to same player
                        $gameState['current_player'] = $playerIndex;
                        
                        $playerName = $gameState['players'][$playerIndex]['username'];
                        $gameState['last_notification'] = [
                            'type' => 'turn_rotation',
                            'message' => "{$playerName} played 5! Turn rotated back to {$playerName}.",
                            'player_index' => $playerIndex,
                            'timestamp' => now()->toISOString()
                        ];
                    } else {
                        // Three or more players: reverse direction and go to previous player
                        $gameState['current_player'] = $this->crazyService->getNextPlayer(
                            $playerIndex, $playerCount, $gameState['direction']
                        );
                        
                        $playerName = $gameState['players'][$playerIndex]['username'];
                        $nextPlayerName = $gameState['players'][$gameState['current_player']]['username'];
                        $gameState['last_notification'] = [
                            'type' => 'direction_change',
                            'message' => "{$playerName} played 5! Direction is now {$newDirection}, turn to {$nextPlayerName}.",
                            'player_index' => $playerIndex,
                            'direction' => $gameState['direction'],
                            'timestamp' => now()->toISOString()
                        ];
                    }
                    $turnAdvanced = true;
                    break;

                case 'skip_next':
                    if ($playerCount === 2) {
                        // Two players: 7 brings turn back to same player
                        $gameState['current_player'] = $playerIndex;
                        $gameState['current_suit'] = $card['suit'];
                        
                        $playerName = $gameState['players'][$playerIndex]['username'];
                        $skippedPlayerName = $gameState['players'][$this->crazyService->getNextPlayer(
                            $playerIndex, $playerCount, !$gameState['direction']
                        )]['username'];
                        
                        $gameState['last_notification'] = [
                            'type' => 'turn_skip',
                            'message' => "{$playerName} played 7! Turn skipped for {$skippedPlayerName}. Turn back to {$playerName}.",
                            'player_index' => $playerIndex,
                            'skipped_player' => $this->crazyService->getNextPlayer(
                                $playerIndex, $playerCount, !$gameState['direction']
                            ),
                            'timestamp' => now()->toISOString()
                        ];
                    } else {
                        // Three or more players: skip next player
                        $gameState['current_player'] = $this->crazyService->getNextPlayer(
                            $playerIndex, $playerCount, !$gameState['direction'], 1
                        );
                        $gameState['current_suit'] = $card['suit'];
                        
                        $playerName = $gameState['players'][$playerIndex]['username'];
                        $skippedPlayerName = $gameState['players'][$this->crazyService->getNextPlayer(
                            $playerIndex, $playerCount, !$gameState['direction']
                        )]['username'];
                        $nextPlayerName = $gameState['players'][$gameState['current_player']]['username'];
                        
                        $gameState['last_notification'] = [
                            'type' => 'turn_skip',
                            'message' => "{$playerName} played 7! {$skippedPlayerName} is skipped, turn to {$nextPlayerName}.",
                            'player_index' => $playerIndex,
                            'skipped_player' => $this->crazyService->getNextPlayer(
                                $playerIndex, $playerCount, !$gameState['direction']
                            ),
                            'timestamp' => now()->toISOString()
                        ];
                    }
                    $turnAdvanced = true;
                    break;
            }
        }

        // Update current suit if not changed by special effect
        if (!in_array('change_suit', $effects)) {
            $oldSuit = $gameState['current_suit'] ?? null;
            $gameState['current_suit'] = $card['suit'];
            
            // Track suit change if it occurred due to normal card play (same number/suit)
            if ($oldSuit && $oldSuit !== $card['suit']) {
                $gameState['last_suit_change'] = [
                    'player_index' => $playerIndex,
                    'turn_count' => $gameState['turn_count'] ?? 0,
                    'cards_played_since' => 0,
                    'change_type' => 'normal_card' // Track that this was caused by normal card play
                ];
                
                \Log::info('Suit changed due to normal card play (same number/suit)', [
                    'player_index' => $playerIndex,
                    'old_suit' => $oldSuit,
                    'new_suit' => $card['suit'],
                    'card_played' => $card['number'] . $card['suit'],
                    'change_type' => 'normal_card',
                    'rule' => 'This type of suit change should NOT block next player from using 8/J'
                ]);
            }
        }

        // Move to next player ONLY if no special effect has advanced the turn
        if (!$turnAdvanced) {
            $gameState['current_player'] = $this->crazyService->getNextPlayer(
                $playerIndex, $playerCount, !$gameState['direction']
            );
        }

        return $gameState;
    }

    /**
     * Refill deck from discard pile if empty
     */
    private function refillDeckIfEmpty(&$gameState): void
    {
        if (empty($gameState['deck'])) {
            // Create new shuffled deck (keeping current discard pile top card)
            $topCard = array_pop($gameState['discard_pile']);
            
            // Use remaining discard pile as new deck
            $gameState['deck'] = $gameState['discard_pile'];
            shuffle($gameState['deck']);
            
            // Reset discard pile with just the top card
            $gameState['discard_pile'] = [$topCard];
            
            // If still empty, create a fresh deck
            if (empty($gameState['deck'])) {
                $gameState['deck'] = $this->crazyService->generateDeck();
                shuffle($gameState['deck']);
            }
        }
    }
} 
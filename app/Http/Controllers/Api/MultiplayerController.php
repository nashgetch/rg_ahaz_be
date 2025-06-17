<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\MultiplayerRoom;
use App\Models\MultiplayerParticipant;
use App\Models\User;
use App\Services\StrictBettingService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class MultiplayerController extends Controller
{
    protected StrictBettingService $bettingService;

    public function __construct(StrictBettingService $bettingService)
    {
        $this->bettingService = $bettingService;
    }

    /**
     * Get list of available multiplayer rooms
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        $showAll = $request->get('show_all', false);

        if ($showAll) {
            // Show all public joinable rooms
            $query = MultiplayerRoom::with(['host:id,name', 'game:id,title,slug', 'participants.user:id,name'])
                ->public()
                ->joinable()
                ->orderBy('created_at', 'desc');
        } else {
            // Show only rooms user is in or invited to
            $query = MultiplayerRoom::with(['host:id,name', 'game:id,title,slug', 'participants.user:id,name'])
                ->where(function ($q) use ($user) {
                    $q->whereHas('participants', function ($participantQuery) use ($user) {
                        $participantQuery->where('user_id', $user->id);
                    })
                    ->orWhereHas('invitations', function ($inviteQuery) use ($user) {
                        $inviteQuery->where('user_id', $user->id);
                    });
                })
                ->orderBy('created_at', 'desc');
        }

        if ($request->has('game_id')) {
            $query->where('game_id', $request->game_id);
        }

        $rooms = $query->paginate(20);

        // Add user participation status to each room
        $roomsData = $rooms->items();
        foreach ($roomsData as $room) {
            $userParticipant = $room->participants->where('user_id', $user->id)->first();
            $room->user_status = $userParticipant ? 'joined' : 'not_joined';
            $room->is_user_host = $userParticipant ? $userParticipant->is_host : false;
        }

        return response()->json([
            'success' => true,
            'data' => $roomsData,
            'pagination' => [
                'current_page' => $rooms->currentPage(),
                'total_pages' => $rooms->lastPage(),
                'total_items' => $rooms->total(),
                'per_page' => $rooms->perPage()
            ]
        ]);
    }

    /**
     * Get user's active room if they're in one
     */
    public function getMyActiveRoom(): JsonResponse
    {
        $user = Auth::user();
        
        $activeParticipant = MultiplayerParticipant::with(['room.game:id,title,slug', 'room.host:id,name'])
            ->where('user_id', $user->id)
            ->whereHas('room', function ($query) {
                $query->whereIn('status', ['waiting', 'starting', 'in_progress']);
            })
            ->first();

        if (!$activeParticipant) {
            return response()->json([
                'success' => true,
                'data' => null
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'room_code' => $activeParticipant->room->room_code,
                'room_name' => $activeParticipant->room->room_name,
                'game' => $activeParticipant->room->game,
                'status' => $activeParticipant->room->status,
                'is_host' => $activeParticipant->is_host
            ]
        ]);
    }

    /**
     * Create a new multiplayer room
     */
    public function create(Request $request): JsonResponse
    {
        // Convert empty password to null for proper validation
        if ($request->password === '') {
            $request->merge(['password' => null]);
        }

        $validator = Validator::make($request->all(), [
            'game_id' => 'required|exists:games,id',
            'room_name' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
            'max_players' => 'required|integer|min:2|max:8',
            'game_duration' => 'required|in:rush,normal',
            'is_private' => 'boolean',
            'password' => 'nullable|string|min:4|max:20'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = Auth::user();
        $game = Game::find($request->game_id);

        if (!$game) {
            return response()->json([
                'success' => false,
                'message' => 'Game not found'
            ], 404);
        }

        // Check if user can start a new game (strict betting enforcement)
        $canPlay = $this->bettingService->canUserStartNewGame($user);
        if (!$canPlay['can_play']) {
            return response()->json([
                'success' => false,
                'message' => $canPlay['message'],
                'reason' => $canPlay['reason'],
                'data' => $canPlay
            ], 400);
        }

        // Check if user has enough tokens
        \Log::info('User tokens balance: ' . $user->tokens_balance);
        \Log::info('Game token cost: ' . $game->token_cost);
        $tokenCost = (int) $game->token_cost;   
        if (!$user->canAffordWithLocked($tokenCost)) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient available tokens to create room (some tokens may be locked in other games)'
            ], 400);
        }

        // Create room
        $durationMinutes = $request->game_duration === 'rush' ? 3 : 5;
        $room = MultiplayerRoom::create([
            'host_user_id' => $user->id,
            'game_id' => $request->game_id,
            'room_name' => $request->room_name,
            'description' => $request->description,
            'max_players' => $request->max_players,
            'current_players' => 0,
            'status' => 'waiting',
            'game_duration' => $request->game_duration,
            'duration_minutes' => $durationMinutes,
            'is_private' => $request->is_private ?? false,
            'password' => $request->password ? Hash::make($request->password) : null,
            'game_config' => $game->config ?? []
        ]);

        // Add host as first participant
        $room->addParticipant($user, true);

        return response()->json([
            'success' => true,
            'message' => 'Room created successfully',
            'data' => [
                'room' => $room->load(['host:id,name', 'game:id,title,slug,mechanic', 'participants.user:id,name']),
                'share_url' => $room->getShareUrl()
            ]
        ]);
    }

    /**
     * Get room details
     */
    public function show(string $roomCode): JsonResponse
    {
        $room = MultiplayerRoom::with(['host:id,name', 'game:id,title,slug,mechanic', 'participants.user:id,name'])
            ->where('room_code', $roomCode)
            ->first();

        if (!$room) {
            return response()->json([
                'success' => false,
                'message' => 'Room not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $room
        ]);
    }

    /**
     * Join a multiplayer room
     */
    public function join(Request $request, string $roomCode): JsonResponse
    {
        $room = MultiplayerRoom::with('game')->where('room_code', $roomCode)->first();

        if (!$room) {
            return response()->json([
                'success' => false,
                'message' => 'Room not found'
            ], 404);
        }

        $user = Auth::user();

        // Check if user can start a new game (strict betting enforcement)
        $canPlay = $this->bettingService->canUserStartNewGame($user);
        if (!$canPlay['can_play']) {
            return response()->json([
                'success' => false,
                'message' => $canPlay['message'],
                'reason' => $canPlay['reason'],
                'data' => $canPlay
            ], 400);
        }

        if (!$room->canJoin($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot join this room'
            ], 400);
        }

        // Check password for private rooms
        if ($room->is_private && $room->password) {
            $validator = Validator::make($request->all(), [
                'password' => 'required|string'
            ]);

            if ($validator->fails() || !Hash::check($request->password, $room->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid room password'
                ], 401);
            }
        }

        // Check if user has enough tokens
        $tokenCost = (int) $room->game->token_cost;
        if (!$user->canAffordWithLocked($tokenCost)) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient available tokens to join room (some tokens may be locked in other games)'
            ], 400);
        }

        // Add participant (tokens will be deducted when they mark as ready)
        $room->addParticipant($user);

        return response()->json([
            'success' => true,
            'message' => 'Successfully joined room. Mark yourself as ready to pay entry fee and start playing.',
            'data' => $room->load(['host:id,name', 'game:id,title,slug,mechanic', 'participants.user:id,name'])
        ]);
    }

    /**
     * Leave a multiplayer room
     */
    public function leave(string $roomCode): JsonResponse
    {
        $room = MultiplayerRoom::where('room_code', $roomCode)->first();

        if (!$room) {
            return response()->json([
                'success' => false,
                'message' => 'Room not found'
            ], 404);
        }

        $user = Auth::user();
        $participant = $room->participants()->where('user_id', $user->id)->first();
        
        if (!$participant) {
            return response()->json([
                'success' => false,
                'message' => 'You are not in this room'
            ], 400);
        }

        // Handle abandonment penalties if game is in progress and there are active bets
        if ($room->status === 'in_progress' && $room->has_active_bets) {
            $abandonmentResult = $this->bettingService->handlePlayerAbandonment($room, $participant, 'left_room');
            
            $message = 'Left room';
            if ($abandonmentResult['penalty_applied']) {
                $message .= sprintf(' (Penalty: %.2f tokens for abandoning active bet)', $abandonmentResult['penalty_amount']);
            }
        } else {
            $message = 'Successfully left room';
        }
        
        if (!$room->removeParticipant($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove participant'
            ], 400);
        }

        return response()->json([
            'success' => true,
            'message' => $message
        ]);
    }

    /**
     * Mark participant as ready
     */
    public function ready(string $roomCode): JsonResponse
    {
        $room = MultiplayerRoom::where('room_code', $roomCode)->first();

        if (!$room) {
            return response()->json([
                'success' => false,
                'message' => 'Room not found'
            ], 404);
        }

        $participant = $room->participants()->where('user_id', Auth::id())->first();

        if (!$participant) {
            return response()->json([
                'success' => false,
                'message' => 'You are not in this room'
            ], 400);
        }

        // Check if already ready to prevent double token deduction
        if ($participant->status === 'ready') {
            return response()->json([
                'success' => true,
                'message' => 'Already marked as ready',
                'data' => $room->load(['participants.user:id,name'])
            ]);
        }

        $user = Auth::user();
        $tokenCost = (int) $room->game->token_cost;

        // Check if user has enough tokens
        if ($user->tokens_balance < $tokenCost) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient tokens to ready up. You need ' . $tokenCost . ' tokens to play.'
            ], 400);
        }

        // Deduct entry tokens when marking as ready
        $user->spendTokens($tokenCost, "Multiplayer {$room->game->title} - Entry Fee");

        // Mark as ready
        $participant->markReady();

        return response()->json([
            'success' => true,
            'message' => 'Marked as ready. Entry fee of ' . $tokenCost . ' tokens deducted.',
            'data' => $room->load(['participants.user:id,name'])
        ]);
    }

    /**
     * Place a bet for the multiplayer game
     */
    public function placeBet(Request $request, string $roomCode): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'bet_amount' => 'required|integer|min:5|max:50|in:5,10,15,20,30,40,50'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid bet amount',
                'errors' => $validator->errors()
            ], 422);
        }

        $room = MultiplayerRoom::where('room_code', $roomCode)->first();

        if (!$room) {
            return response()->json([
                'success' => false,
                'message' => 'Room not found'
            ], 404);
        }

        $participant = $room->participants()->where('user_id', Auth::id())->first();

        if (!$participant) {
            return response()->json([
                'success' => false,
                'message' => 'You are not in this room'
            ], 400);
        }

        if ($room->status !== 'waiting') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot place bets after game has started'
            ], 400);
        }

        $user = Auth::user();
        $betAmount = $request->bet_amount;

        // Check if user has enough XP
        if ($user->experience < $betAmount) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient XP to place this bet'
            ], 400);
        }

        // Check if user already placed a bet
        if ($participant->bet_amount > 0) {
            return response()->json([
                'success' => false,
                'message' => 'You have already placed a bet'
            ], 400);
        }

        // Deduct XP and place bet
        $user->decrement('experience', $betAmount);
        $participant->update(['bet_amount' => $betAmount]);

        // Calculate total pot
        $totalPot = $room->participants()->sum('bet_amount');

        return response()->json([
            'success' => true,
            'message' => "Bet of {$betAmount} tokens placed successfully",
            'data' => [
                'bet_amount' => $betAmount,
                'total_pot' => $totalPot,
                'remaining_tokens' => $user->fresh()->tokens_balance
            ]
        ]);
    }

    /**
     * Propose a bet for all players in the room
     */
    public function proposeBet(Request $request, string $roomCode): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'bet_amount' => 'required|integer|min:5|max:50|in:5,10,15,20,30,40,50'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid bet amount',
                'errors' => $validator->errors()
            ], 422);
        }

        $room = MultiplayerRoom::where('room_code', $roomCode)->first();

        if (!$room) {
            return response()->json([
                'success' => false,
                'message' => 'Room not found'
            ], 404);
        }

        $participant = $room->participants()->where('user_id', Auth::id())->first();

        if (!$participant) {
            return response()->json([
                'success' => false,
                'message' => 'You are not in this room'
            ], 400);
        }

        if ($room->status !== 'waiting') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot propose bets after game preparation has started'
            ], 400);
        }

        $betAmount = $request->bet_amount;
        $proposer = Auth::user();

        // Check if proposer has enough tokens
        if ($proposer->tokens_balance < $betAmount) {
            return response()->json([
                'success' => false,
                'message' => 'You don\'t have enough tokens to propose this bet'
            ], 400);
        }

        // Check if there's already an active bet proposal
        $gameState = $room->game_state ?? [];
        if (isset($gameState['bet_proposal']) && $gameState['bet_proposal']['status'] === 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'There is already an active bet proposal'
            ], 400);
        }

        // Check if all players have enough XP for this bet
        $insufficientPlayers = [];
        foreach ($room->participants as $p) {
            if ($p->user->tokens_balance < $betAmount) {
                $insufficientPlayers[] = $p->user->name;
            }
        }

        if (!empty($insufficientPlayers)) {
            return response()->json([
                'success' => false,
                'message' => 'Some players don\'t have enough tokens: ' . implode(', ', $insufficientPlayers)
            ], 400);
        }

        // Create bet proposal
        $betProposal = [
            'amount' => $betAmount,
            'proposer_id' => $proposer->id,
            'proposer_name' => $proposer->name,
            'status' => 'pending',
            'responses' => [],
            'created_at' => now()->toISOString(),
            'expires_at' => now()->addMinutes(2)->toISOString() // 2 minute timeout
        ];

        // Auto-accept for proposer
        $betProposal['responses'][$proposer->id] = [
            'user_id' => $proposer->id,
            'username' => $proposer->name,
            'response' => 'accepted',
            'responded_at' => now()->toISOString()
        ];

        $room->update([
            'game_state' => array_merge($gameState, [
                'bet_proposal' => $betProposal
            ])
        ]);

        return response()->json([
            'success' => true,
            'message' => "Bet proposal of {$betAmount} XP sent to all players",
            'data' => [
                'bet_proposal' => $betProposal,
                'total_participants' => $room->participants()->count()
            ]
        ]);
    }

    /**
     * Respond to a bet proposal
     */
    public function respondToBetProposal(Request $request, string $roomCode): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'response' => 'required|in:accept,decline'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid response',
                'errors' => $validator->errors()
            ], 422);
        }

        $room = MultiplayerRoom::where('room_code', $roomCode)->first();

        if (!$room) {
            return response()->json([
                'success' => false,
                'message' => 'Room not found'
            ], 404);
        }

        $participant = $room->participants()->where('user_id', Auth::id())->first();

        if (!$participant) {
            return response()->json([
                'success' => false,
                'message' => 'You are not in this room'
            ], 400);
        }

        $gameState = $room->game_state ?? [];
        $betProposal = $gameState['bet_proposal'] ?? null;

        if (!$betProposal || $betProposal['status'] !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'No active bet proposal found'
            ], 400);
        }

        // Check if proposal has expired
        if (now()->isAfter($betProposal['expires_at'])) {
            // Mark as expired
            $betProposal['status'] = 'expired';
            $room->update([
                'game_state' => array_merge($gameState, [
                    'bet_proposal' => $betProposal
                ])
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Bet proposal has expired'
            ], 400);
        }

        $user = Auth::user();
        $response = $request->response === 'accept' ? 'accepted' : 'declined';

        // Check if user already responded
        if (isset($betProposal['responses'][$user->id])) {
            return response()->json([
                'success' => false,
                'message' => 'You have already responded to this bet proposal'
            ], 400);
        }

        // Add user's response
        $betProposal['responses'][$user->id] = [
            'user_id' => $user->id,
            'username' => $user->name,
            'response' => $response,
            'responded_at' => now()->toISOString()
        ];

        $totalParticipants = $room->participants()->count();
        $totalResponses = count($betProposal['responses']);
        $acceptedResponses = collect($betProposal['responses'])->where('response', 'accepted')->count();

        // Check if everyone has responded or someone declined
        if ($response === 'declined' || $acceptedResponses < $totalResponses) {
            if ($response === 'declined' || $totalResponses >= $totalParticipants) {
                // Bet proposal rejected
                $betProposal['status'] = 'rejected';
                $room->update([
                    'game_state' => array_merge($gameState, [
                        'bet_proposal' => $betProposal
                    ])
                ]);

                return response()->json([
                    'success' => true,
                    'message' => $response === 'declined' 
                        ? 'Bet proposal declined. Game will start without betting.'
                        : 'Not all players accepted the bet. Game will start without betting.',
                    'data' => [
                        'bet_proposal' => $betProposal,
                        'final_status' => 'rejected'
                    ]
                ]);
            }
        } elseif ($acceptedResponses >= $totalParticipants) {
            // All players accepted - implement the bet with strict enforcement
            $betProposal['status'] = 'accepted';
            
            // Use strict betting service to lock tokens and set up enforcement
            $this->bettingService->lockBetTokens($room, $betProposal['amount']);
            
            // Update participant bet amounts
            foreach ($room->participants as $p) {
                $p->update(['bet_amount' => $betProposal['amount']]);
            }

            $totalPot = $room->total_bet_pool;

            $room->update([
                'game_state' => array_merge($gameState, [
                    'bet_proposal' => $betProposal
                ])
            ]);

            return response()->json([
                'success' => true,
                'message' => "All players accepted! Bet of {$betProposal['amount']} Tokens is now active with strict enforcement.",
                'data' => [
                    'bet_proposal' => $betProposal,
                    'final_status' => 'accepted',
                    'total_pot' => $totalPot
                ]
            ]);
        }

        // Still waiting for more responses
        $room->update([
            'game_state' => array_merge($gameState, [
                'bet_proposal' => $betProposal
            ])
        ]);

        return response()->json([
            'success' => true,
            'message' => "Response recorded. Waiting for " . ($totalParticipants - $totalResponses) . " more player(s).",
            'data' => [
                'bet_proposal' => $betProposal,
                'responses_needed' => $totalParticipants - $totalResponses
            ]
        ]);
    }

    /**
     * Start the multiplayer game (host only)
     */
    public function start(string $roomCode): JsonResponse
    {
        $room = MultiplayerRoom::with(['game', 'participants.user'])->where('room_code', $roomCode)->first();

        if (!$room) {
            return response()->json([
                'success' => false,
                'message' => 'Room not found'
            ], 404);
        }

        $participant = $room->participants()->where('user_id', Auth::id())->first();

        if (!$participant || !$participant->canStartGame()) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot start this game'
            ], 403);
        }

        if (!$room->startGame()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot start game at this time'
            ], 400);
        }

        // Entry tokens are now deducted when players mark themselves as ready
        // No need to deduct tokens here anymore

        // Calculate total pot from participants' bet amounts
        $totalPot = $room->participants()->sum('bet_amount');

        return response()->json([
            'success' => true,
            'message' => 'Game started successfully',
            'data' => [
                'participants' => $room->load(['participants.user:id,name'])->participants,
                'total_pot' => $totalPot,
                'room_code' => $room->room_code,
                'status' => $room->status
            ]
        ]);
    }

    /**
     * Update game progress for a participant
     */
    public function updateProgress(Request $request, string $roomCode): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'progress' => 'required|array',
            'score' => 'nullable|integer'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $room = MultiplayerRoom::where('room_code', $roomCode)->first();

        if (!$room) {
            return response()->json([
                'success' => false,
                'message' => 'Room not found'
            ], 404);
        }

        $participant = $room->participants()->where('user_id', Auth::id())->first();

        if (!$participant) {
            return response()->json([
                'success' => false,
                'message' => 'You are not in this room'
            ], 400);
        }

        $participant->updateProgress($request->progress);
        
        if ($request->has('score')) {
            $participant->update(['score' => $request->score]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Progress updated',
            'data' => $participant->fresh()
        ]);
    }

    /**
     * Finish game for a participant
     */
    public function finish(Request $request, string $roomCode): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'final_score' => 'required|integer'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $room = MultiplayerRoom::where('room_code', $roomCode)->first();

        if (!$room) {
            return response()->json([
                'success' => false,
                'message' => 'Room not found'
            ], 404);
        }

        $participant = $room->participants()->where('user_id', Auth::id())->first();

        if (!$participant) {
            return response()->json([
                'success' => false,
                'message' => 'You are not in this room'
            ], 400);
        }

        // Use strict betting service to handle completion with enforcement
        $completionResult = $this->bettingService->handleGameCompletion($room, $participant, $request->final_score);
        
        // If game is not completed yet, just mark as finished
        if (!$completionResult['completed']) {
            $participant->markFinished($request->final_score);
        }
        
        // Award regular game tokens to winner (separate from betting)
        if ($completionResult['completed'] && $completionResult['winner']) {
            $winner = $completionResult['winner'];
            $tokensAwarded = min(5, $winner->score / 100); // Cap at 5 tokens
            if ($tokensAwarded > 0) {
                $winner->user->awardTokens($tokensAwarded, 'prize', "Multiplayer {$room->game->title} Winner");
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Game finished',
            'data' => [
                'room' => $room->fresh()->load(['participants.user:id,name', 'winner:id,name']),
                'your_rank' => $participant->fresh()->final_rank,
                'completion_result' => $completionResult
            ]
        ]);
    }

    /**
     * Invite a user to the room by username
     */
    public function invite(Request $request, string $roomCode): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required|string|exists:users,name'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
                'errors' => $validator->errors()
            ], 422);
        }

        $room = MultiplayerRoom::where('room_code', $roomCode)->first();

        if (!$room) {
            return response()->json([
                'success' => false,
                'message' => 'Room not found'
            ], 404);
        }

        // Check if requester is host
        $participant = $room->participants()->where('user_id', Auth::id())->first();
        if (!$participant || !$participant->is_host) {
            return response()->json([
                'success' => false,
                'message' => 'Only host can invite players'
            ], 403);
        }

        $invitedUser = User::where('name', $request->username)->first();

        if (!$room->canJoin($invitedUser)) {
            return response()->json([
                'success' => false,
                'message' => 'User cannot join this room'
            ], 400);
        }

        // Create invited participant entry
        $participant = MultiplayerParticipant::create([
            'room_id' => $room->id,
            'user_id' => $invitedUser->id,
            'status' => 'invited',
            'joined_at' => now(),
        ]);

        // Don't increment current_players until invitation is accepted

        // In a real app, you'd send a notification to the invited user
        // For now, we'll just return success

        return response()->json([
            'success' => true,
            'message' => "Invitation sent to {$invitedUser->name}",
            'data' => [
                'invited_user' => $invitedUser->only(['id', 'name']),
                'share_url' => $room->getShareUrl()
            ]
        ]);
    }

    /**
     * Get user's multiplayer history
     */
    public function history(): JsonResponse
    {
        $user = Auth::user();
        
        $rooms = MultiplayerRoom::with([
            'game:id,title,slug', 
            'winner:id,name',
            'participants' => function ($query) {
                $query->select('id', 'room_id', 'user_id', 'bet_amount', 'locked_tokens', 'penalty_amount', 'reimbursement_amount', 'score', 'final_rank')
                      ->with('user:id,name');
            }
        ])
        ->whereHas('participants', function ($query) use ($user) {
            $query->where('user_id', $user->id);
        })
        ->where('status', 'completed')
        ->orderBy('completed_at', 'desc')
        ->paginate(20);

        // Add detailed betting and statistics information to each room
        $roomsData = $rooms->items();
        foreach ($roomsData as $room) {
            $userParticipant = $room->participants->where('user_id', $user->id)->first();
            
            // Calculate pot and betting info from final_scores if available
            $totalPot = 0;
            $userTokensChange = 0;
            $userBetAmount = 0;
            $hadBets = false;
            
            if ($room->final_scores && is_array($room->final_scores)) {
                $totalPot = collect($room->final_scores)->sum('bet_amount');
                $userScore = collect($room->final_scores)->where('user_id', $user->id)->first();
                if ($userScore) {
                    $userTokensChange = $userScore['tokens_change'] ?? 0;
                    $userBetAmount = $userScore['bet_amount'] ?? 0;
                }
                $hadBets = $totalPot > 0;
            } else {
                // Fallback to participant data
                $userBetAmount = $userParticipant ? $userParticipant->bet_amount : 0;
                $totalPot = $room->participants->sum('bet_amount');
                $hadBets = $totalPot > 0;
                
                if ($hadBets && $room->winner_user_id === $user->id) {
                    $userTokensChange = $totalPot;
                } elseif ($hadBets) {
                    $userTokensChange = -$userBetAmount;
                }
            }
            
            // Add comprehensive room data
            $room->betting_info = [
                'had_bets' => $hadBets,
                'total_pot' => $totalPot,
                'user_bet_amount' => $userBetAmount,
                'user_tokens_change' => $userTokensChange,
                'user_was_winner' => $room->winner_user_id === $user->id,
                'user_penalty' => $userParticipant ? $userParticipant->penalty_amount : 0,
                'user_reimbursement' => $userParticipant ? $userParticipant->reimbursement_amount : 0
            ];
            
            // Add game statistics
            $room->game_stats = [
                'user_score' => $userParticipant ? $userParticipant->score : 0,
                'user_rank' => $userParticipant ? $userParticipant->final_rank : null,
                'total_participants' => $room->participants->count(),
                'completed_at' => $room->completed_at,
                'duration' => $room->started_at && $room->completed_at 
                    ? $room->started_at->diffInMinutes($room->completed_at) . ' minutes'
                    : null
            ];
            
            // Format final scores for display
            if ($room->final_scores && is_array($room->final_scores)) {
                $room->leaderboard = collect($room->final_scores)->map(function ($score) {
                    return [
                        'username' => $score['username'],
                        'score' => $score['score'],
                        'rank' => $score['rank'],
                        'attempts' => $score['attempts'] ?? null,
                        'is_solved' => $score['is_solved'] ?? null,
                        'time_taken' => $score['time_taken'] ?? null,
                        'tokens_change' => $score['tokens_change'] ?? 0
                    ];
                })->sortBy('rank')->values();
            }
        }

        return response()->json([
            'success' => true,
            'data' => $roomsData,
            'pagination' => [
                'current_page' => $rooms->currentPage(),
                'total_pages' => $rooms->lastPage(),
                'total_items' => $rooms->total(),
                'per_page' => $rooms->perPage()
            ]
        ]);
    }

    /**
     * Get user's pending invitations
     */
    public function invitations(): JsonResponse
    {
        $user = Auth::user();
        
        $invitations = MultiplayerParticipant::with([
            'room' => function ($query) {
                $query->select('id', 'room_code', 'room_name', 'description', 'host_user_id', 'game_id', 'current_players', 'max_players', 'status')
                      ->with(['host:id,name', 'game:id,title,slug']);
            }
        ])
        ->where('user_id', $user->id)
        ->where('status', 'invited')
        ->whereHas('room', function ($query) {
            $query->where('status', 'waiting');
        })
        ->orderBy('created_at', 'desc')
        ->get();

        return response()->json([
            'success' => true,
            'data' => $invitations->map(function ($invitation) {
                return [
                    'invitation_id' => $invitation->id,
                    'room' => $invitation->room,
                    'invited_at' => $invitation->created_at,
                    'share_url' => $invitation->room->getShareUrl(),
                    'is_replay' => $invitation->room->final_scores !== null // If final_scores exist, it's a replay
                ];
            })
        ]);
    }

    /**
     * Accept or decline an invitation
     */
    public function respondToInvitation(Request $request, int $invitationId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'response' => 'required|in:accept,decline'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid response. Must be accept or decline.',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = Auth::user();
        $invitation = MultiplayerParticipant::with('room')->where('id', $invitationId)
            ->where('user_id', $user->id)
            ->where('status', 'invited')
            ->first();

        if (!$invitation) {
            return response()->json([
                'success' => false,
                'message' => 'Invitation not found or already responded to'
            ], 404);
        }

        if ($invitation->room->status !== 'waiting') {
            return response()->json([
                'success' => false,
                'message' => 'Room is no longer accepting players'
            ], 400);
        }

        if ($request->response === 'accept') {
            // Check if room is still available
            if ($invitation->room->current_players >= $invitation->room->max_players) {
                return response()->json([
                    'success' => false,
                    'message' => 'Room is now full'
                ], 400);
            }

            // Check if user has enough tokens (will be deducted when they mark as ready)
            $tokenCost = (int) $invitation->room->game->token_cost;
            if ($user->tokens_balance < $tokenCost) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient tokens to join room. You need ' . $tokenCost . ' tokens to play.'
                ], 400);
            }

            // Accept invitation (tokens will be deducted when marking as ready)
            $invitation->update(['status' => 'joined']);
            
            // Now increment current_players count
            $invitation->room->increment('current_players');
            
            return response()->json([
                'success' => true,
                'message' => 'Invitation accepted successfully. Mark yourself as ready to pay entry fee and start playing.',
                'data' => [
                    'room' => $invitation->room->load(['host:id,name', 'game:id,title,slug', 'participants.user:id,name'])
                ]
            ]);
        } else {
            // Decline invitation
            $invitation->delete();
            $invitation->room->decrement('current_players');
            
            return response()->json([
                'success' => true,
                'message' => 'Invitation declined'
            ]);
        }
    }

    /**
     * Initiate a replay of the completed game (host only)
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

        // Check if host has enough tokens for replay entry fee (will be deducted when marking as ready)
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
            'total_bet_pool' => 0, // Clear betting pool
            'current_players' => 1 // Reset to host only
        ]);

        // Remove all non-host participants
        $room->participants()->where('user_id', '!=', Auth::id())->delete();

        // Reset host participant (they need to mark as ready again to pay entry fee)
        $room->participants()->where('user_id', Auth::id())->update([
            'status' => 'joined', // Host needs to mark as ready again
            'score' => 0,
            'bet_amount' => 0, // Clear bet amount
            'locked_tokens' => 0, // Clear locked tokens
            'game_progress' => null,
            'ready_at' => null,
            'finished_at' => null,
            'final_rank' => null
        ]);

        // Create new invitation records for all previous participants
        foreach ($participants as $participant) {
            MultiplayerParticipant::create([
                'room_id' => $room->id,
                'user_id' => $participant->user_id,
                'status' => 'invited',
                'joined_at' => now(),
                'is_host' => false,
                'bet_amount' => 0, // Clean betting data
                'locked_tokens' => 0, // Clean locked tokens
                'score' => 0 // Clean score
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
     * Respond to a replay invitation (uses the same system as regular invitations)
     */
    public function respondToReplay(string $roomCode): JsonResponse
    {
        $validator = Validator::make(request()->all(), [
            'accept' => 'required|boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid response format',
                'errors' => $validator->errors()
            ], 422);
        }

        $room = MultiplayerRoom::where('room_code', $roomCode)->first();

        if (!$room) {
            return response()->json([
                'success' => false,
                'message' => 'Room not found'
            ], 404);
        }

        // Find the invitation record for this user
        $invitation = $room->participants()->where('user_id', Auth::id())
            ->where('status', 'invited')
            ->first();

        if (!$invitation) {
            return response()->json([
                'success' => false,
                'message' => 'No replay invitation found'
            ], 400);
        }

        $accept = request()->boolean('accept');

        if ($accept) {
            // Check if user has enough tokens (will be deducted when they mark as ready)
            $user = Auth::user();
            $tokenCost = (int) $room->game->token_cost;
            
            if ($user->tokens_balance < $tokenCost) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient tokens to join replay. You need ' . $tokenCost . ' tokens to play.'
                ], 400);
            }

            // Accept the replay (tokens will be deducted when marking as ready)
            $invitation->update(['status' => 'joined']);
            $room->increment('current_players');

            return response()->json([
                'success' => true,
                'message' => 'Replay invitation accepted. Mark yourself as ready to pay entry fee and start playing.',
                'data' => [
                    'room' => $room->load(['host:id,name', 'game:id,title,slug', 'participants.user:id,name'])
                ]
            ]);
        } else {
            // Decline the replay - remove the invitation
            $invitation->delete();

            return response()->json([
                'success' => true,
                'message' => 'Replay invitation declined'
            ]);
        }
    }
}
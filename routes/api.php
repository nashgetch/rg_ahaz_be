<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Broadcast;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\GameController;
use App\Http\Controllers\Api\LeaderboardController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\CodeBreakerController;
use App\Http\Controllers\Api\WordValidationController;
use App\Http\Controllers\Api\PlayerStatsController;
use App\Http\Controllers\Api\MinesController;
use App\Http\Controllers\Api\SumChaserController;
use App\Http\Controllers\Api\MultiplayerController;
use App\Http\Controllers\Api\MultiplayerCodeBreakerController;
use App\Http\Controllers\Api\MultiplayerCrazyController;
use App\Http\Controllers\Api\HangmanController;
use App\Http\Controllers\GeoQuestionController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Public routes
Route::prefix('v1')->group(function () {
    // Authentication
    Route::post('/auth/send-otp', [AuthController::class, 'sendOtp']);
    Route::post('/auth/verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('/auth/refresh', [AuthController::class, 'refresh']);
    
    // Public game info
    Route::get('/games', [GameController::class, 'index']);
    Route::get('/games/{game}', [GameController::class, 'show']);
    
    // Public leaderboards (available without authentication)
    Route::get('/leaderboards', [LeaderboardController::class, 'index']);
    Route::get('/leaderboards/games/{game}', [LeaderboardController::class, 'gameLeaderboard']);
    Route::get('/leaderboards/games/{game}/periods', [LeaderboardController::class, 'gamePeriods']);
    Route::get('/leaderboards/top-players', [LeaderboardController::class, 'topPlayers']);
    Route::get('/stats/share/{userId}', [LeaderboardController::class, 'shareStats']);
    
    // Word validation (public for Letter Leap and word games)
    Route::post('/words/validate', [WordValidationController::class, 'validateWord']);
    Route::get('/words/stats', [WordValidationController::class, 'getWordStats']);
    
    // GeoSprint questions (public for easy access)
    Route::get('/geo-questions', [GeoQuestionController::class, 'getGameQuestions']);
    Route::get('/geo-questions/category/{category}', [GeoQuestionController::class, 'getQuestionsByCategory']);
    Route::get('/geo-questions/stats', [GeoQuestionController::class, 'getStatistics']);
});

// Broadcasting authentication route (must be outside the v1 prefix)
Route::post('/broadcasting/auth', function (Request $request) {
    // Enhanced debug logging
    \Log::info('Broadcasting auth request:', [
        'method' => $request->method(),
        'url' => $request->fullUrl(),
        'headers' => [
            'authorization' => $request->header('Authorization'),
            'accept' => $request->header('Accept'),
            'content-type' => $request->header('Content-Type'),
            'x-requested-with' => $request->header('X-Requested-With'),
        ],
        'body' => $request->all(),
        'raw_input' => $request->getContent(),
        'channel_name' => $request->input('channel_name'),
        'socket_id' => $request->input('socket_id'),
        'has_auth_header' => $request->hasHeader('Authorization'),
        'bearer_token' => $request->bearerToken(),
        'user_id' => $request->user()?->id,
        'user_authenticated' => !!$request->user(),
    ]);
    
    // Check if user is authenticated
    if (!$request->user()) {
        \Log::warning('Broadcasting auth failed: No authenticated user');
        return response()->json(['message' => 'Unauthenticated'], 401);
    }
    
    // Check required parameters
    if (!$request->input('channel_name') || !$request->input('socket_id')) {
        \Log::warning('Broadcasting auth failed: Missing required parameters', [
            'channel_name' => $request->input('channel_name'),
            'socket_id' => $request->input('socket_id'),
            'all_input' => $request->all(),
            'content_type' => $request->header('Content-Type'),
        ]);
        return response()->json(['message' => 'Missing channel_name or socket_id'], 422);
    }
    
    try {
        $result = Broadcast::auth($request);
        \Log::info('Broadcasting auth success:', [
            'user_id' => $request->user()->id,
            'channel_name' => $request->input('channel_name'),
            'result_type' => gettype($result),
        ]);
        return $result;
    } catch (\Exception $e) {
        \Log::error('Broadcasting auth failed:', [
            'error' => $e->getMessage(),
            'user_id' => $request->user()?->id,
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'channel_name' => $request->input('channel_name'),
            'socket_id' => $request->input('socket_id'),
        ]);
        return response()->json(['message' => $e->getMessage()], 403);
    }
})->middleware('auth:sanctum');

// Temporary test route for WebSocket without auth (for debugging)
Route::post('/broadcasting/auth-test', function (Request $request) {
    // Return a mock auth response for testing
    return response()->json([
        'auth' => 'test-auth-signature',
        'channel_data' => '{"user_id":1,"user_info":{"name":"Test User"}}'
    ]);
});

// Debug auth test route
Route::post('/debug/auth-test', function (Request $request) {
    return response()->json([
        'authenticated' => !!$request->user(),
        'user' => $request->user(),
        'token_preview' => $request->bearerToken() ? substr($request->bearerToken(), 0, 20) . '...' : null,
        'headers' => [
            'authorization' => $request->header('Authorization'),
            'accept' => $request->header('Accept'),
            'x-requested-with' => $request->header('X-Requested-With'),
        ]
    ]);
})->middleware('auth:sanctum');

// Debug route to test WebSocket broadcasting
Route::post('/debug/broadcast-test', function (Request $request) {
    $roomCode = $request->input('room_code', 'TEST123');
    
    // Broadcast a test event
    broadcast(new \App\Events\CrazyGameUpdated(
        $roomCode,
        ['test' => 'data', 'timestamp' => now()->toISOString()],
        'test_event',
        null,
        [],
        ['type' => 'test', 'message' => 'WebSocket test broadcast from backend']
    ));
    
    return response()->json([
        'success' => true,
        'message' => 'Test broadcast sent',
        'room_code' => $roomCode
    ]);
})->middleware('auth:sanctum');

// Public debug route to test WebSocket server without auth
Route::post('/debug/ping-websocket', function (Request $request) {
    try {
        // Create a simple event for testing public channels
        $testEvent = new class implements \Illuminate\Contracts\Broadcasting\ShouldBroadcastNow {
            use \Illuminate\Broadcasting\InteractsWithSockets;
            use \Illuminate\Foundation\Events\Dispatchable;
            use \Illuminate\Queue\SerializesModels;
            
            public function broadcastOn() {
                return new \Illuminate\Broadcasting\Channel('test.public');
            }
            
            public function broadcastAs() {
                return 'ping.test';
            }
            
            public function broadcastWith() {
                return [
                    'message' => 'WebSocket server is working!',
                    'timestamp' => now()->toISOString(),
                    'test' => true
                ];
            }
        };
        
        broadcast($testEvent);
        
        return response()->json([
            'success' => true,
            'message' => 'WebSocket ping sent successfully to public channel',
            'config' => [
                'broadcast_driver' => config('broadcasting.default'),
                'reverb_app_id' => env('REVERB_APP_ID'),
                'reverb_app_key' => env('REVERB_APP_KEY'),
                'reverb_host' => env('REVERB_HOST', 'localhost'),
                'reverb_port' => env('REVERB_PORT', 6001),
            ]
        ]);
    } catch (Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'WebSocket ping failed: ' . $e->getMessage(),
            'error' => $e->getMessage()
        ]);
    }
});

// Protected routes
Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    // User management
    Route::get('/user', [UserController::class, 'profile']);
    Route::put('/user', [UserController::class, 'updateProfile']);
    Route::post('/user/claim-daily-bonus', [UserController::class, 'claimDailyBonus']);
    Route::get('/user/badge', [UserController::class, 'badge']);
    Route::delete('/auth/logout', [AuthController::class, 'logout']);
    
    // Profile management
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::post('/profile/username', [ProfileController::class, 'updateUsername']);
    Route::post('/profile/avatar', [ProfileController::class, 'updateAvatar']);
    Route::post('/profile/check-username', [ProfileController::class, 'checkUsername']);
    
    // Mines specific routes (must come before generic game routes)
    Route::post('/games/mines/start', [MinesController::class, 'start']);
    Route::post('/games/mines/{round}/reveal', [MinesController::class, 'reveal']);
    Route::post('/games/mines/{round}/cashout', [MinesController::class, 'cashOut']);
    Route::post('/games/mines/{round}/flag', [MinesController::class, 'useFlag']);
    Route::get('/games/mines/history', [MinesController::class, 'history']);
    
    // Sum Chaser specific routes
    Route::post('/games/sum-chaser/start', [SumChaserController::class, 'start']);
    Route::post('/games/sum-chaser/{round}/predict', [SumChaserController::class, 'predict']);
    Route::post('/games/sum-chaser/{round}/cashout', [SumChaserController::class, 'cashOut']);
    Route::get('/games/sum-chaser/history', [SumChaserController::class, 'history']);

    // Multiplayer routes
    Route::prefix('multiplayer')->group(function () {
        Route::get('/rooms', [MultiplayerController::class, 'index']);
        Route::get('/my-active-room', [MultiplayerController::class, 'getMyActiveRoom']);
        Route::post('/rooms', [MultiplayerController::class, 'create']);
        Route::get('/rooms/{roomCode}', [MultiplayerController::class, 'show']);
        Route::post('/rooms/{roomCode}/join', [MultiplayerController::class, 'join']);
        Route::post('/rooms/{roomCode}/leave', [MultiplayerController::class, 'leave']);
        Route::post('/rooms/{roomCode}/ready', [MultiplayerController::class, 'ready']);
        Route::post('/rooms/{roomCode}/shuffle-players', [MultiplayerController::class, 'shufflePlayers']);
        Route::post('/rooms/{roomCode}/bet', [MultiplayerController::class, 'placeBet']);
        Route::post('/rooms/{roomCode}/propose-bet', [MultiplayerController::class, 'proposeBet']);
        Route::post('/rooms/{roomCode}/respond-bet', [MultiplayerController::class, 'respondToBetProposal']);
        Route::post('/rooms/{roomCode}/start', [MultiplayerController::class, 'start']);
        Route::post('/rooms/{roomCode}/progress', [MultiplayerController::class, 'updateProgress']);
        Route::post('/rooms/{roomCode}/finish', [MultiplayerController::class, 'finish']);
        Route::post('/rooms/{roomCode}/invite', [MultiplayerController::class, 'invite']);
        Route::post('/rooms/{roomCode}/replay', [MultiplayerController::class, 'initiateReplay']);
        Route::post('/rooms/{roomCode}/replay/respond', [MultiplayerController::class, 'respondToReplay']);
        Route::get('/invitations', [MultiplayerController::class, 'invitations']);
        Route::post('/invitations/{invitationId}/respond', [MultiplayerController::class, 'respondToInvitation']);
        Route::get('/history', [MultiplayerController::class, 'history']);
        
        // Multiplayer CodeBreaker specific routes
        Route::prefix('codebreaker')->group(function () {
            Route::post('/rooms/{roomCode}/start', [MultiplayerCodeBreakerController::class, 'start']);
            Route::post('/rooms/{roomCode}/guess', [MultiplayerCodeBreakerController::class, 'guess']);
            Route::post('/rooms/{roomCode}/hint', [MultiplayerCodeBreakerController::class, 'hint']);
            Route::post('/rooms/{roomCode}/forfeit', [MultiplayerCodeBreakerController::class, 'forfeit']);
            Route::get('/rooms/{roomCode}/status', [MultiplayerCodeBreakerController::class, 'status']);
        });

        // Multiplayer Crazy card game specific routes
        Route::prefix('crazy')->group(function () {
                    Route::post('/rooms/{roomCode}/start', [MultiplayerCrazyController::class, 'start']);
        Route::post('/rooms/{roomCode}/play-card', [MultiplayerCrazyController::class, 'playCard']);
        Route::post('/rooms/{roomCode}/draw-card', [MultiplayerCrazyController::class, 'drawCard']);
        Route::post('/rooms/{roomCode}/qeregn', [MultiplayerCrazyController::class, 'sayQeregn']);
        Route::post('/rooms/{roomCode}/yelegnm', [MultiplayerCrazyController::class, 'sayYelegnm']);
        Route::post('/rooms/{roomCode}/crazy', [MultiplayerCrazyController::class, 'sayCrazy']);
        Route::post('/rooms/{roomCode}/face-penalty', [MultiplayerCrazyController::class, 'facePenalty']);
        Route::post('/rooms/{roomCode}/replay', [MultiplayerCrazyController::class, 'initiateReplay']);
        Route::get('/rooms/{roomCode}/status', [MultiplayerCrazyController::class, 'status']);
        });
    });
    
    // Game management
    Route::post('/games/{game}/start', [GameController::class, 'startRound']);
    Route::post('/games/{game}/submit', [GameController::class, 'submitRound']);
    Route::get('/games/{game}/rounds', [GameController::class, 'userRounds']);
    
    // Code Breaker specific routes
    Route::post('/codebreaker/guess', [CodeBreakerController::class, 'makeGuess']);
    Route::post('/codebreaker/hint', [CodeBreakerController::class, 'getHint']);
    Route::post('/codebreaker/timeout', [CodeBreakerController::class, 'handleTimeout']);
    Route::get('/codebreaker/state/{round}', [CodeBreakerController::class, 'getGameState']);
    
    // Hangman specific routes
    Route::post('/hangman/start', [HangmanController::class, 'startRound']);
    Route::post('/hangman/guess', [HangmanController::class, 'processGuess']);
    Route::post('/hangman/submit', [HangmanController::class, 'submitRound']);
    Route::post('/hangman/hint', [HangmanController::class, 'getHint']);
    
    // GeoSprint streak tracking (requires authentication)
    Route::post('/geo-questions/update-streak', [GeoQuestionController::class, 'updateStreakData']);
    Route::get('/geo-questions/user-stats', [GeoQuestionController::class, 'getUserStats']);
    
    // Transactions
    Route::get('/transactions', [TransactionController::class, 'index']);
    Route::post('/transactions/purchase-tokens', [TransactionController::class, 'purchaseTokens']);
    
    // User-specific leaderboard and stats (require authentication)
    Route::get('/leaderboards/games/{game}/rank', [LeaderboardController::class, 'playerRank']);
    Route::get('/leaderboards/player/stats', [LeaderboardController::class, 'playerStats']);
    
    // Player stats by player ID
    Route::get('/stats/player/{playerId}', [PlayerStatsController::class, 'getPlayerStats']);
    
    // Admin leaderboard functions (in production, add admin middleware)
    Route::post('/leaderboards/clear-cache', [LeaderboardController::class, 'clearCache']);
});

// Debug route to test file uploads
Route::post('/debug/upload', function (Request $request) {
    \Log::info('Debug upload test:', [
        'has_file_avatar' => $request->hasFile('avatar'),
        'all_files' => $request->allFiles(),
        'all_input' => $request->all(),
        'content_type' => $request->header('Content-Type'),
        'method' => $request->method(),
        'raw_input' => $request->getContent()
    ]);
    
    return response()->json([
        'success' => true,
        'message' => 'Debug upload test',
        'data' => [
            'has_file_avatar' => $request->hasFile('avatar'),
            'all_files' => $request->allFiles(),
            'all_input' => $request->all(),
            'content_type' => $request->header('Content-Type'),
            'method' => $request->method()
        ]
    ]);
});

// Debug route to test invitation broadcasting
Route::post('/test/invitation/{userId}', function ($userId) {
    $testInvitation = [
        'invitation_id' => 999,
        'room' => [
            'id' => 1,
            'room_code' => 'TEST123',
            'room_name' => 'Test Room',
            'description' => 'WebSocket Test Room',
            'host_user_id' => 1,
            'game_id' => 1,
            'current_players' => 1,
            'max_players' => 4,
            'status' => 'waiting',
            'host' => ['id' => 1, 'name' => 'Test Host'],
            'game' => ['id' => 1, 'title' => 'Test Game', 'slug' => 'test']
        ],
        'invited_at' => now(),
        'share_url' => 'http://localhost:3000/test',
        'inviter' => ['id' => 1, 'name' => 'Test Inviter'],
        'is_replay' => false
    ];
    
    broadcast(new \App\Events\InvitationReceived($userId, $testInvitation));
    
    return response()->json([
        'success' => true,
        'message' => "Test invitation sent to user {$userId}",
        'data' => $testInvitation
    ]);
})->middleware('auth:sanctum');

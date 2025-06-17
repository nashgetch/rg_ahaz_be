<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
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

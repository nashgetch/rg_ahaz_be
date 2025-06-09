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
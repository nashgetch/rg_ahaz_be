<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use App\Services\BadgeService;

class UserController extends Controller
{
    /**
     * Get user profile
     */
    public function profile(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'language' => $user->locale,
                'tokens' => $user->tokens_balance,
                'level' => $user->level ?? 1,
                'experience' => $user->experience ?? 0,
                'experience_to_next_level' => $user->experienceToNextLevel(),
                'total_games_played' => $user->rounds()->where('status', 'completed')->count(),
                'total_tokens_earned' => $user->transactions()->where('type', 'prize')->where('amount', '>', 0)->sum('amount'),
                'can_claim_daily_bonus' => $user->canClaimDailyBonus(),
                'last_daily_bonus' => $user->daily_bonus_claimed_at,
                'created_at' => $user->created_at,
                'avatar' => $user->avatar ?? null
            ]
        ]);
    }

    /**
     * Update user profile
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'language' => 'sometimes|string|in:en,am,or',
            'avatar' => 'sometimes|string|max:255'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();
        
        // Map language to locale field
        $data = $request->only(['name', 'avatar']);
        if ($request->has('language')) {
            $data['locale'] = $request->input('language');
        }
        
        $user->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'language' => $user->locale,
                'avatar' => $user->avatar
            ]
        ]);
    }

    /**
     * Claim daily bonus
     */
    public function claimDailyBonus(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->canClaimDailyBonus()) {
            return response()->json([
                'success' => false,
                'message' => 'Daily bonus already claimed today'
            ], 422);
        }

        $bonusTokens = $user->claimDailyBonus();

        return response()->json([
            'success' => true,
            'message' => 'Daily bonus claimed successfully',
            'data' => [
                'tokens_earned' => $bonusTokens,
                'user_tokens' => $user->fresh()->tokens_balance,
                'next_bonus_available' => now()->addDay()->startOfDay()
            ]
        ]);
    }

    /**
     * Get the current user's badge
     */
    public function badge(Request $request): JsonResponse
    {
        $user = $request->user();
        $gameSlug = $request->get('game');
        
        $badgeService = app(BadgeService::class);
        $badge = $badgeService->getUserBadge($user, $gameSlug);
        
        return response()->json([
            'success' => true,
            'data' => [
                'badge' => $badge
            ]
        ]);
    }
} 
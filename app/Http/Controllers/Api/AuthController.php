<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\OTP;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function __construct(private readonly SmsService $smsService)
    {
    }

    /**
     * Send OTP to phone number
     */
    public function sendOtp(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|min:10|max:13',
            'language' => 'sometimes|string|in:en,am,or'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid phone number format',
                'errors' => $validator->errors()
            ], 422);
        }

        // Additional phone validation
        $phone = $this->normalizePhone($request->phone);
        if (!$this->isValidEthiopianPhone($phone)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid Ethiopian phone number format'
            ], 422);
        }

        $language = $request->language ?? 'en';
        $user = User::where('phone', $phone)->first();
        $hasActiveSubscription = $user?->hasActiveSubscription() ?? false;

        if (!$hasActiveSubscription) {
            return response()->json([
                'success' => false,
                'message' => 'Subscription is required before sending OTP',
                'data' => [
                    'requires_subscription' => true,
                    'subscription_status' => 'inactive',
                    'sms_short_code' => '6294',
                    'sms_body' => 'OK',
                    'sms_link' => 'sms:6294?body=' . urlencode('OK'),
                ],
            ], 403);
        }

        $otpResult = $this->issueOtp($phone, $language);

        if (!$otpResult['success']) {
            return response()->json([
                'success' => false,
                'message' => $otpResult['message'],
            ], 429);
        }

        return response()->json([
            'success' => true,
            'message' => 'OTP sent successfully',
            'data' => [
                'phone' => $phone,
                'expires_in' => 300
            ]
        ]);
    }

    /**
     * Check subscription and optionally auto-send OTP when eligible.
     */
    public function subscriptionStatus(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|min:10|max:13',
            'language' => 'sometimes|string|in:en,am,or',
            'send_otp' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid phone number format',
                'errors' => $validator->errors(),
            ], 422);
        }

        $phone = $this->normalizePhone($request->phone);
        if (!$this->isValidEthiopianPhone($phone)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid Ethiopian phone number format',
            ], 422);
        }

        $user = User::where('phone', $phone)->first();
        $hasActiveSubscription = $user?->hasActiveSubscription() ?? false;
        $latestSubscription = $user?->subscriptions()->latest('updated_at')->first();
        $subscriptionStatus = $latestSubscription?->status ?? 'inactive';
        $otpSent = false;

        if ($hasActiveSubscription && $request->boolean('send_otp', false)) {
            $result = $this->issueOtp($phone, $request->input('language', 'en'));
            $otpSent = $result['success'];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'phone' => $phone,
                'subscription_status' => $subscriptionStatus,
                'has_active_subscription' => $hasActiveSubscription,
                'can_play' => $hasActiveSubscription,
                'otp_sent' => $otpSent,
                'sms_short_code' => '6294',
                'sms_body' => 'OK',
                'sms_link' => 'sms:6294?body=' . urlencode('OK'),
            ],
        ]);
    }

    /**
     * Verify OTP and authenticate user
     */
    public function verifyOtp(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string',
            'otp' => 'required|string|size:6',
            'name' => 'sometimes|string|max:255',
            'language' => 'sometimes|string|in:en,am,or'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $phone = $this->normalizePhone($request->phone);
        $otpCode = $request->otp;

        // Find valid OTP
        $otp = OTP::where('phone', $phone)
            ->where('expires_at', '>', now())
            ->whereNull('consumed_at')
            ->where('attempts', '<', 3)
            ->latest()
            ->first();

        if (!$otp) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired OTP'
            ], 422);
        }

        // Increment attempts
        $otp->increment('attempts');

        // Verify OTP
        // TEMPORARY: Allow last 6 digits of phone number as OTP for testing
        $lastSixDigits = substr(preg_replace('/[^\d]/', '', $phone), -6);
        $isValidOtp = Hash::check($otpCode, $otp->code) || $otpCode === $lastSixDigits;
        
        if (!$isValidOtp) {
            if ($otp->attempts >= 3) {
                $otp->update(['consumed_at' => now()]); // Block further attempts
            }
            
            return response()->json([
                'success' => false,
                'message' => 'Invalid OTP',
                'attempts_remaining' => max(0, 3 - $otp->attempts)
            ], 422);
        }

        // Mark OTP as consumed
        $otp->update(['consumed_at' => now()]);

        // Find or create user
        $user = User::where('phone', $phone)->first();
        $isNewUser = false;

        if (!$user) {
            $user = User::create([
                'phone' => $phone,
                'name' => $this->generateUniqueUsername(),
                'password' => Hash::make(Str::random(32)), // Generate random password for phone-based auth
                'locale' => $request->language ?? 'en',
                'tokens_balance' => 100, // Welcome bonus
                'daily_bonus_claimed_at' => null
            ]);
            $isNewUser = true;
        }

        // Generate token
        $token = $user->createToken('GameHub-ET')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => $isNewUser ? 'Account created successfully' : 'Login successful',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'avatar' => $user->avatar,
                    'phone' => $user->phone,
                    'language' => $user->locale,
                    'tokens' => $user->tokens_balance,
                    'earned_tokens' => $user->earned_tokens_balance,
                    'has_active_subscription' => $user->hasActiveSubscription(),
                    'level' => $user->level,
                    'experience' => $user->experience,
                    'can_claim_daily_bonus' => $user->canClaimDailyBonus()
                ],
                'token' => $token,
                'is_new_user' => $isNewUser
            ]
        ]);
    }

    /**
     * Refresh user token
     */
    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        
        // Revoke current token
        $request->user()->currentAccessToken()->delete();
        
        // Create new token
        $token = $user->createToken('GameHub-ET')->plainTextToken;

        return response()->json([
            'success' => true,
            'data' => [
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'avatar' => $user->avatar,
                    'phone' => $user->phone,
                    'language' => $user->locale,
                    'tokens' => $user->tokens_balance,
                    'earned_tokens' => $user->earned_tokens_balance,
                    'has_active_subscription' => $user->hasActiveSubscription(),
                    'level' => $user->level,
                    'experience' => $user->experience,
                    'can_claim_daily_bonus' => $user->canClaimDailyBonus()
                ]
            ]
        ]);
    }

    /**
     * Logout user
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully'
        ]);
    }

    /**
     * Normalize Ethiopian phone number
     */
    private function normalizePhone(string $phone): string
    {
        // Remove spaces and special characters
        $phone = preg_replace('/[^\d+]/', '', $phone);
        
        // Convert to international format
        if (str_starts_with($phone, '0')) {
            $phone = '+251' . substr($phone, 1);
        } elseif (str_starts_with($phone, '251')) {
            $phone = '+' . $phone;
        } elseif (!str_starts_with($phone, '+251')) {
            $phone = '+251' . $phone;
        }

        return $phone;
    }

    /**
     * Send SMS (integrate with SMS provider)
     */
    private function sendSms(string $phone, string $otp, string $language): bool
    {
        $messages = [
            'en' => "Your Ahaz one time code is: {$otp}. Valid for 5 minutes.",
            'am' => "የአሃዝ ማረጋገጫ ኮድዎ: {$otp}። ለ5 ደቂቃ ይቆያል።",
            'or' => "Koodii mirkaneessaa Ahaaz keessan: {$otp}. Daqiiqaa shaniif ni tura."
        ];

        $message = $messages[$language] ?? $messages['en'];

        $sendResult = $this->smsService->sendOtp($phone, $message);

        if (!$sendResult['success']) {
            Log::warning('OTP SMS send did not complete successfully', [
                'phone' => $phone,
                'result' => $sendResult,
            ]);
            return false;
        }
        
        return true;
    }

    /**
     * Create and send OTP with lightweight resend throttling.
     *
     * @return array{success: bool, message: string}
     */
    private function issueOtp(string $phone, string $language): array
    {
        $recentOtp = OTP::where('phone', $phone)
            ->where('created_at', '>=', now()->subSeconds(45))
            ->latest()
            ->first();

        if ($recentOtp) {
            return [
                'success' => false,
                'message' => 'Please wait a few seconds before requesting another code.',
            ];
        }

        $otpCode = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

        OTP::create([
            'phone' => $phone,
            'code' => Hash::make($otpCode),
            'type' => 'login',
            'expires_at' => now()->addMinutes(5),
            'attempts' => 0,
        ]);

        Log::info("OTP for {$phone}: {$otpCode}");
        $smsSuccess = $this->sendSms($phone, $otpCode, $language);

        if (!$smsSuccess) {
            return [
                'success' => false,
                'message' => 'Failed to deliver SMS. Please try again.',
            ];
        }

        return [
            'success' => true,
            'message' => 'OTP sent successfully',
        ];
    }

    /**
     * Validate Ethiopian phone number format
     */
     private function isValidEthiopianPhone(string $phone): bool
    {
        // Ethiopian phone numbers: +251[79]XXXXXXXX (total 13 chars with +251)
        // Normalized phone should be in format +251[79]XXXXXXXX
        return preg_match('/^\+251[79]\d{8}$/', $phone);
    }

    /**
     * Generate a unique username for new users
     */
    private function generateUniqueUsername(): string
    {
        $adjectives = [
            'Swift', 'Brave', 'Smart', 'Quick', 'Mighty', 'Sharp', 'Epic', 'Bold', 
            'Strong', 'Fast', 'Cool', 'Elite', 'Pro', 'Super', 'Fire', 'Thunder',
            'Royal', 'Golden', 'Silver', 'Diamond', 'Flash', 'Storm', 'Phoenix', 'Eagle'
        ];
        
        $nouns = [
            'Lion', 'Tiger', 'Wolf', 'Bear', 'Fox', 'Hawk', 'Dragon', 'Warrior',
            'Knight', 'Hunter', 'Ninja', 'Ranger', 'Champion', 'Master', 'Legend',
            'Hero', 'King', 'Queen', 'Prince', 'Star', 'Comet', 'Thunder', 'Storm'
        ];

        $maxAttempts = 50;
        $attempt = 0;

        do {
            $adjective = $adjectives[array_rand($adjectives)];
            $noun = $nouns[array_rand($nouns)];
            $number = rand(10, 999);
            $username = $adjective . $noun . $number;
            
            $attempt++;
            
            // Check if username exists
            $exists = User::where('name', $username)->exists();
            
            if (!$exists) {
                return $username;
            }
        } while ($attempt < $maxAttempts);

        // Fallback if all attempts failed (very unlikely)
        return 'Player' . time() . rand(10, 99);
    }
} 
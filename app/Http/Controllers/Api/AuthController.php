<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\EthiopianPhone;
use App\Models\OTP;
use App\Models\Subscription;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    private const OTP_TTL_SECONDS = 60;

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
        $phone = EthiopianPhone::normalize($request->phone);
        if (!EthiopianPhone::isValid($phone)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid Ethiopian phone number format'
            ], 422);
        }

        $language = $request->language ?? 'en';
        $user = User::where('phone', $phone)->first();
        $hasActiveSubscription = $user?->hasActiveSubscription() ?? false;
        $hasSubscriptionHistory = $this->hasSubscriptionHistoryForPhone($phone);

        if (!$hasActiveSubscription && !$hasSubscriptionHistory) {
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
                'expires_in' => self::OTP_TTL_SECONDS
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

        $phone = EthiopianPhone::normalize($request->phone);
        if (!EthiopianPhone::isValid($phone)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid Ethiopian phone number format',
            ], 422);
        }

        $user = User::where('phone', $phone)->first();
        $hasActiveSubscription = $user?->hasActiveSubscription() ?? false;
        $latestSubscription = $this->latestSubscriptionForPhone($phone, $user);
        $subscriptionStatus = $latestSubscription?->status ?? 'inactive';
        $hasSubscriptionHistory = (bool) $latestSubscription;
        $canLoginWithOtp = $hasActiveSubscription || $hasSubscriptionHistory;
        $otpSent = false;

        if ($canLoginWithOtp && $request->boolean('send_otp', false)) {
            $result = $this->issueOtp($phone, $request->input('language', 'en'));
            $otpSent = $result['success'];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'phone' => $phone,
                'subscription_status' => $subscriptionStatus,
                'has_active_subscription' => $hasActiveSubscription,
                'has_subscription_history' => $hasSubscriptionHistory,
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
            'language' => 'sometimes|string|in:en,am,or',
            'device_name' => 'sometimes|string|max:120',
            'replace_existing_session' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $phone = EthiopianPhone::normalize($request->phone);
        $otpCode = $request->otp;
        $deviceName = trim((string) $request->input('device_name', 'Unknown device'));
        $currentIp = (string) ($request->ip() ?? '');
        if ($deviceName === '') {
            $deviceName = 'Unknown device';
        }

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

        // Verify OTP
        // TEMPORARY: Allow last 6 digits of phone number as OTP for testing
        $lastSixDigits = substr(preg_replace('/[^\d]/', '', $phone), -6);
        $isValidOtp = Hash::check($otpCode, $otp->code) || $otpCode === $lastSixDigits;
        
        if (!$isValidOtp) {
            // Increment attempts only for invalid OTP submissions.
            $otp->increment('attempts');
            $otp->refresh();

            if ($otp->attempts >= 3) {
                $otp->update(['consumed_at' => now()]); // Block further attempts
            }
            
            return response()->json([
                'success' => false,
                'message' => 'Invalid OTP',
                'attempts_remaining' => max(0, 3 - $otp->attempts)
            ], 422);
        }

        // Find or create user
        $user = User::where('phone', $phone)->first();
        $isNewUser = false;

        if (!$user) {
            $usernameValidation = Validator::make($request->all(), [
                'name' => 'required|string|min:3|max:20|regex:/^[a-zA-Z0-9_]+$/',
            ]);

            if ($usernameValidation->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Username is required for new users and must be 3-20 characters (letters, numbers, underscores).',
                    'data' => [
                        'requires_username' => true,
                    ],
                    'errors' => $usernameValidation->errors(),
                ], 422);
            }

            $requestedName = trim((string) $request->name);
            $usernameTaken = User::whereRaw('LOWER(name) = ?', [Str::lower($requestedName)])
                ->exists();

            if ($usernameTaken) {
                return response()->json([
                    'success' => false,
                    'message' => 'Username is already taken.',
                    'data' => [
                        'requires_username' => true,
                    ],
                ], 422);
            }

            $user = User::create([
                'phone' => $phone,
                'name' => $requestedName,
                'password' => Hash::make(Str::random(32)), // Generate random password for phone-based auth
                'locale' => $request->language ?? 'en',
                'tokens_balance' => 100, // Welcome bonus
                'daily_bonus_claimed_at' => null
            ]);
            $isNewUser = true;
        }

        $replaceExistingSession = $request->boolean('replace_existing_session', false);
        $activeToken = $this->resolveLatestActiveToken($user);
        if ($activeToken && !$replaceExistingSession) {
            return response()->json([
                'success' => false,
                'message' => 'You already have an active session on another device.',
                'data' => [
                    'requires_session_replace' => true,
                    'active_device' => [
                        'name' => $user->active_device_name ?: $activeToken->name,
                        'ip' => $user->active_device_ip,
                        'last_seen_at' => optional($activeToken->last_used_at ?? $activeToken->created_at)?->toISOString(),
                    ],
                ],
            ], 409);
        }

        if ($activeToken && $replaceExistingSession) {
            $user->tokens()->delete();
        }

        // Mark OTP as consumed only after all validations and user creation succeed.
        $otp->update(['consumed_at' => now()]);

        // Generate token pair (short-lived access + long-lived refresh)
        [$plainAccess, $plainRefresh, $accessModel] = $this->issueTokenPair($user, $deviceName);
        $user->forceFill([
            'last_login_at' => now(),
            'active_device_name' => $deviceName,
            'active_device_ip' => $currentIp !== '' ? $currentIp : null,
            'active_device_token_id' => $accessModel->id,
            'active_device_last_seen_at' => now(),
        ])->save();

        $accessTtlSeconds = config('auth.access_token_ttl_minutes', 15) * 60;

        return $this->withRefreshCookie(response()->json([
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
                'token' => $plainAccess,
                'expires_in' => $accessTtlSeconds,
                'is_new_user' => $isNewUser
            ]
        ]), $plainRefresh);
    }

    /**
     * Refresh access token using a valid Bearer access token (rotates refresh as well).
     */
    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentToken = $user->currentAccessToken();
        $tokenName = $currentToken?->name ?: ($user->active_device_name ?: 'Current device');

        if ($currentToken) {
            $this->deletePairedRefreshToken($user, (int) $currentToken->id);
            $currentToken->delete();
        }

        [$plainAccess, $plainRefresh, $accessModel] = $this->issueTokenPair($user, $tokenName);
        $user->forceFill([
            'active_device_name' => $tokenName,
            'active_device_ip' => (string) ($request->ip() ?? ''),
            'active_device_token_id' => $accessModel->id,
            'active_device_last_seen_at' => now(),
        ])->save();

        $accessTtlSeconds = config('auth.access_token_ttl_minutes', 15) * 60;

        return $this->withRefreshCookie(response()->json([
            'success' => true,
            'data' => [
                'token' => $plainAccess,
                'expires_in' => $accessTtlSeconds,
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
        ]), $plainRefresh);
    }

    /**
     * Exchange a long-lived refresh token for a new access + refresh pair (no Bearer access required).
     * Refresh token is read from an HttpOnly cookie when present, otherwise from the request body (legacy).
     */
    public function refreshWithRefreshToken(Request $request): JsonResponse
    {
        $cookieName = (string) config('auth.refresh_cookie_name', 'ahaz_refresh');
        $incoming = $request->cookie($cookieName) ?? $request->input('refresh_token');

        if (!is_string($incoming) || trim($incoming) === '') {
            return response()->json([
                'success' => false,
                'message' => 'Missing refresh token',
            ], 401);
        }

        $pat = PersonalAccessToken::findToken($incoming);

        if (!$pat || !$pat->tokenable instanceof User) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid refresh token',
            ], 401);
        }

        if (!$pat->can('refresh')) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid refresh token',
            ], 401);
        }

        if ($pat->expires_at && $pat->expires_at->isPast()) {
            return response()->json([
                'success' => false,
                'message' => 'Refresh token expired',
            ], 401);
        }

        if (!preg_match('/^refresh-for:(\d+)$/', $pat->name, $matches)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid refresh token',
            ], 401);
        }

        /** @var User $user */
        $user = $pat->tokenable;
        $oldAccessId = (int) $matches[1];

        PersonalAccessToken::query()
            ->where('id', $oldAccessId)
            ->where('tokenable_id', $user->id)
            ->where('tokenable_type', $user->getMorphClass())
            ->delete();
        $pat->delete();

        $deviceName = $user->active_device_name ?: 'Unknown device';
        [$plainAccess, $plainRefresh, $accessModel] = $this->issueTokenPair($user, $deviceName);
        $user->forceFill([
            'active_device_token_id' => $accessModel->id,
            'active_device_last_seen_at' => now(),
        ])->save();

        $accessTtlSeconds = config('auth.access_token_ttl_minutes', 15) * 60;

        return $this->withRefreshCookie(response()->json([
            'success' => true,
            'data' => [
                'token' => $plainAccess,
                'expires_in' => $accessTtlSeconds,
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
                    'can_claim_daily_bonus' => $user->canClaimDailyBonus(),
                ],
            ],
        ]), $plainRefresh);
    }

    /**
     * Logout user
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentToken = $user->currentAccessToken();

        if ($currentToken) {
            $this->deletePairedRefreshToken($user, (int) $currentToken->id);
            $currentToken->delete();
        }

        $hasRemainingTokens = $user->tokens()->exists();
        if (!$hasRemainingTokens) {
            $user->forceFill([
                'active_device_name' => null,
                'active_device_ip' => null,
                'active_device_token_id' => null,
                'active_device_last_seen_at' => null,
            ])->save();
        }

        return $this->withRefreshCookie(response()->json([
            'success' => true,
            'message' => 'Logged out successfully'
        ]), null);
    }

    private function toBillingPhone(string $phone): string
    {
        return str_starts_with($phone, '+') ? substr($phone, 1) : $phone;
    }

    private function latestSubscriptionForPhone(string $normalizedPhone, ?User $user): ?Subscription
    {
        if ($user) {
            $sub = $user->subscriptions()->latest('updated_at')->first();
            if ($sub) {
                return $sub;
            }
        }

        $billingPhone = $this->toBillingPhone($normalizedPhone);

        return Subscription::query()
            ->where('phone', $billingPhone)
            ->orWhere('phone', $normalizedPhone)
            ->latest('updated_at')
            ->first();
    }

    private function hasSubscriptionHistoryForPhone(string $normalizedPhone): bool
    {
        return $this->latestSubscriptionForPhone($normalizedPhone, null) !== null;
    }

    /**
     * Send SMS (integrate with SMS provider)
     */
    private function sendSms(string $phone, string $otp, string $language): bool
    {
        $messages = [
            'en' => "Your Ahaz one-time code is: {$otp}. It expires in 1 minute.",
            'am' => "የአሃዝ የአንድ-ጊዜ ኮድዎ: {$otp}። በ1 ደቂቃ ውስጥ ያበቃል።",
            'or' => "Koodiin yeroo tokkoo Ahaz keessanii: {$otp}. Daqiiqaa 1 keessatti xumurama."
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

        $otpRecord = OTP::create([
            'phone' => $phone,
            'code' => Hash::make($otpCode),
            'type' => 'login',
            'expires_at' => now()->addSeconds(self::OTP_TTL_SECONDS),
            'attempts' => 0,
        ]);

        Log::info("OTP for {$phone}: {$otpCode}");
        $smsSuccess = $this->sendSms($phone, $otpCode, $language);

        if (!$smsSuccess) {
            // Important: remove freshly-created OTP when SMS delivery fails.
            // Otherwise retry attempts get blocked by throttling without hitting SMS API.
            $otpRecord->delete();

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

    private function resolveLatestActiveToken(User $user): ?PersonalAccessToken
    {
        return $user->tokens()
            ->orderByDesc('last_used_at')
            ->orderByDesc('created_at')
            ->get()
            ->first(function (PersonalAccessToken $t) {
                $abilities = $t->abilities ?? [];

                return in_array('*', $abilities, true) || in_array('access', $abilities, true);
            });
    }

    /**
     * @return array{0: string, 1: string, 2: PersonalAccessToken}
     */
    private function issueTokenPair(User $user, string $deviceName): array
    {
        $accessMinutes = max(1, (int) config('auth.access_token_ttl_minutes', 15));
        $refreshDays = max(1, (int) config('auth.refresh_token_ttl_days', 60));

        $access = $user->createToken($deviceName, ['access'], now()->addMinutes($accessMinutes));
        $accessModel = $access->accessToken;
        $refresh = $user->createToken('refresh-for:' . $accessModel->id, ['refresh'], now()->addDays($refreshDays));

        return [$access->plainTextToken, $refresh->plainTextToken, $accessModel];
    }

    private function deletePairedRefreshToken(User $user, int $accessTokenId): void
    {
        PersonalAccessToken::query()
            ->where('tokenable_id', $user->id)
            ->where('tokenable_type', $user->getMorphClass())
            ->where('name', 'refresh-for:' . $accessTokenId)
            ->delete();
    }

    private function withRefreshCookie(JsonResponse $response, ?string $plainRefresh): JsonResponse
    {
        $name = (string) config('auth.refresh_cookie_name', 'ahaz_refresh');
        $path = (string) config('auth.refresh_cookie_path', '/');
        $domain = config('auth.refresh_cookie_domain');
        $domainStr = is_string($domain) && $domain !== '' ? $domain : null;
        $secure = (bool) config('auth.refresh_cookie_secure', true);
        $sameSite = strtolower((string) config('auth.refresh_cookie_same_site', 'lax'));

        if ($plainRefresh === null) {
            return $response->withoutCookie($name, $path, $domainStr);
        }

        $minutes = max(1, (int) config('auth.refresh_token_ttl_days', 60)) * 24 * 60;

        return $response->withCookie(cookie(
            $name,
            $plainRefresh,
            $minutes,
            $path,
            $domainStr,
            $secure,
            true,
            false,
            $sameSite,
        ));
    }

} 
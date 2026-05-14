<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Database\QueryException;

class PaymentWebhookController extends Controller
{
    public function pay(Request $request): JsonResponse
    {
        Log::info('Billing webhook /api/pay received', [
            'headers' => [
                'content_type' => $request->header('Content-Type'),
                'x_billing_secret_present' => $request->hasHeader('X-Billing-Secret'),
            ],
            'payload' => $request->all(),
            'raw_body' => $request->getContent(),
        ]);

        $expectedSecret = (string) env('BILLING_WEBHOOK_SECRET', '');
        $providedSecret = (string) $request->header('X-Billing-Secret', '');

        if ($expectedSecret !== '' && !hash_equals($expectedSecret, $providedSecret)) {
            $response = [
                'success' => false,
                'message' => 'Unauthorized webhook request',
            ];
            Log::warning('Billing webhook auth failed', [
                'reason' => 'secret_mismatch',
                'response' => $response,
            ]);
            return response()->json($response, 401);
        }

        $validator = Validator::make($request->all(), [
            'type' => 'required|string|in:new_subscription,update_subscription',
            'data' => 'required|array',
            'data.id' => 'required|string|max:191',
            'data.status' => 'required|string|in:active,pending,inactive,cancelled,trial,expired',
            'data.phone' => 'required|max:20',
            'data.trial_end' => 'nullable|date',
            'data.activation_date' => 'nullable|date',
            'data.expires_at' => 'nullable|date',
            'data.package_id' => 'nullable|string|max:191',
            'data.method_id' => 'nullable|string|max:191',
            'data.inserted_at' => 'nullable|date',
            'data.updated_at' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            $response = [
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ];
            Log::warning('Billing webhook validation failed', [
                'payload' => $request->all(),
                'errors' => $validator->errors()->toArray(),
                'response' => $response,
            ]);
            return response()->json($response, 422);
        }

        try {
            $subscriptionData = (array) $request->input('data', []);
            $rawPhone = (string) ($subscriptionData['phone'] ?? '');
            $phone = $this->normalizePhone($rawPhone); // 2519XXXXXXXX
            $userPhone = '+' . $phone; // canonical users table format
            $phoneCandidates = $this->buildPhoneCandidates($phone);
            $user = User::whereIn('phone', $phoneCandidates)->first();
            if (!$user) {
                try {
                    $user = User::create([
                        'phone' => $userPhone,
                        'name' => 'Subscriber' . substr(preg_replace('/\D/', '', $phone), -6),
                        'password' => Hash::make(Str::random(32)),
                        'locale' => 'en',
                        'tokens_balance' => 0,
                        'daily_bonus_claimed_at' => null,
                    ]);
                } catch (QueryException $e) {
                    // Concurrent webhooks can race on the same phone: load the row the other request inserted.
                    if ($this->isDuplicateKeyIntegrityViolation($e)) {
                        $user = User::whereIn('phone', $phoneCandidates)->first();
                    }
                    if (!$user) {
                        throw $e;
                    }
                }
            }

            $subscriptionExternalId = (string) ($subscriptionData['id'] ?? '');
            $status = (string) ($subscriptionData['status'] ?? 'pending');
            $activationDate = !empty($subscriptionData['activation_date']) ? Carbon::parse((string) $subscriptionData['activation_date']) : null;
            $trialEnd = !empty($subscriptionData['trial_end']) ? Carbon::parse((string) $subscriptionData['trial_end']) : null;
            $expiresAt = !empty($subscriptionData['expires_at']) ? Carbon::parse((string) $subscriptionData['expires_at']) : null;
            $insertedAt = !empty($subscriptionData['inserted_at']) ? Carbon::parse((string) $subscriptionData['inserted_at']) : now();
            $updatedAt = !empty($subscriptionData['updated_at']) ? Carbon::parse((string) $subscriptionData['updated_at']) : now();
            $activeOnDate = strtolower($status) === 'active'
                ? ($activationDate ?? $updatedAt)->toDateString()
                : ($activationDate ?? $insertedAt)->toDateString();
            $tokensToAward = (int) env('SUBSCRIPTION_DAILY_TOKENS', 100);

            DB::transaction(function () use ($request, $user, $phone, $subscriptionExternalId, $status, $activationDate, $trialEnd, $expiresAt, $insertedAt, $updatedAt, $activeOnDate, $tokensToAward): void {
                $subscriptionValues = [
                    'user_id' => $user->id,
                    'subscription_id' => $subscriptionExternalId,
                    'provider_subscription_id' => $subscriptionExternalId,
                    'event_type' => (string) $request->input('type'),
                    'phone' => $phone,
                    'status' => $status,
                    'package_id' => $request->input('data.package_id'),
                    'method_id' => $request->input('data.method_id'),
                    'trial_end' => $trialEnd,
                    'activation_date' => $activationDate,
                    'expires_at' => $expiresAt,
                    'active_on_date' => $activeOnDate,
                    'provider_reference' => $subscriptionExternalId,
                    'amount_etb' => (float) env('SUBSCRIPTION_DAILY_PRICE_ETB', 2),
                    'starts_at' => $activationDate ?? $insertedAt,
                    'ends_at' => $expiresAt ?? $trialEnd ?? now(),
                    'paid_at' => $activationDate,
                    'raw_payload' => $request->all(),
                    'updated_at' => $updatedAt,
                ];

                // Race-safe + idempotent persistence:
                // 1) lock any existing row for this provider reference
                // 2) create if missing (with duplicate-key fallback for concurrent inserts)
                // 3) apply incoming state only if it's newer or equal-but-stronger
                $subscription = $this->findSubscriptionByProviderReference($subscriptionExternalId, true);

                if (!$subscription) {
                    try {
                        $subscription = Subscription::query()->create([
                            ...$subscriptionValues,
                            'created_at' => $insertedAt,
                        ]);
                    } catch (QueryException $e) {
                        // Concurrent request inserted first; load and continue as update.
                        if (!$this->isDuplicateKeyIntegrityViolation($e)) {
                            throw $e;
                        }

                        $subscription = $this->findSubscriptionByProviderReference($subscriptionExternalId, true);

                        if (!$subscription) {
                            throw $e;
                        }
                    }
                }

                if ($subscription) {
                    $incomingIsPreferred = $this->shouldApplyIncomingState(
                        (string) $subscription->status,
                        $subscription->updated_at,
                        $status,
                        $updatedAt
                    );

                    if ($incomingIsPreferred) {
                        $subscription->fill($subscriptionValues);
                        $subscription->save();
                    }
                }

                $subscription->refresh();
                $effectiveStatus = strtolower((string) $subscription->status);
                $hasAccess = $this->subscriptionHasCurrentAccess($subscription);
                $shouldAward = $hasAccess && in_array($effectiveStatus, ['active', 'trial', 'pending'], true);
                $awardReference = $this->subscriptionAwardReference($subscriptionExternalId, $effectiveStatus, $activeOnDate);

                if ($shouldAward) {
                    $alreadyAwarded = $user->transactions()
                        ->where('type', 'purchase')
                        ->where('reference', $awardReference)
                        ->exists();

                    if (!$alreadyAwarded) {
                        $user->increment('tokens_balance', $tokensToAward);
                        $user->transactions()->create([
                            'amount' => $tokensToAward,
                            'type' => 'purchase',
                            'description' => 'Subscription activation reward',
                            'meta' => [
                                'subscription_id' => $subscriptionExternalId,
                                'status' => $effectiveStatus,
                                'active_on_date' => $activeOnDate,
                            ],
                            'status' => 'completed',
                            'reference' => $awardReference,
                        ]);

                        $subscription->update(['tokens_awarded' => (int) $subscription->tokens_awarded + $tokensToAward]);
                    }
                } else {
                    $alreadyAwarded = $user->transactions()
                        ->where('type', 'purchase')
                        ->where('reference', $awardReference)
                        ->exists();

                    if ($alreadyAwarded) {
                        $subscription->update(['tokens_awarded' => max((int) $subscription->tokens_awarded, $tokensToAward)]);
                    }
                }
            });

            $persistedSubscription = $this->findSubscriptionByProviderReference($subscriptionExternalId);
            $responseStatus = $persistedSubscription?->status ?? $status;

            $response = [
                'success' => true,
                'message' => 'Subscription callback processed successfully',
                'data' => [
                    'phone' => $phone,
                    'subscription_id' => $subscriptionExternalId,
                    'status' => $responseStatus,
                    'has_active_subscription' => $user->fresh()->hasActiveSubscription(),
                ],
            ];
            Log::info('Billing webhook processed successfully', [
                'response' => $response,
            ]);
            return response()->json($response);
        } catch (\Throwable $e) {
            $response = [
                'success' => false,
                'message' => 'Failed to process subscription callback',
                'error' => $e->getMessage(),
            ];
            Log::error('Billing webhook processing failed', [
                'payload' => $request->all(),
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'response' => $response,
            ]);

            return response()->json($response, 500);
        }
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        if (str_starts_with($digits, '251')) {
            return $digits;
        }

        if (str_starts_with($digits, '0')) {
            return '251' . substr($digits, 1);
        }

        return '251' . ltrim($digits, '0');
    }

    /**
     * Build possible phone representations used historically in user records.
     *
     * @return array<int, string>
     */
    private function buildPhoneCandidates(string $normalized251): array
    {
        $candidates = [
            '+' . $normalized251, // +2519XXXXXXXX
            $normalized251,       // 2519XXXXXXXX
        ];

        if (str_starts_with($normalized251, '251')) {
            $local = substr($normalized251, 3); // 9XXXXXXXX
            if ($local !== '') {
                $candidates[] = $local;         // 9XXXXXXXX
                $candidates[] = '0' . $local;   // 09XXXXXXXX
            }
        }

        return array_values(array_unique($candidates));
    }

    private function shouldApplyIncomingState(
        string $existingStatus,
        ?Carbon $existingUpdatedAt,
        string $incomingStatus,
        Carbon $incomingUpdatedAt
    ): bool {
        // Never downgrade trial/active → pending when a later webhook echoes pending.
        $existingStatus = strtolower($existingStatus);
        $incomingStatus = strtolower($incomingStatus);
        if (in_array($existingStatus, ['trial', 'active'], true) && $incomingStatus === 'pending') {
            return false;
        }

        if (!$existingUpdatedAt) {
            return true;
        }

        if ($incomingUpdatedAt->gt($existingUpdatedAt)) {
            return true;
        }

        if ($incomingUpdatedAt->lt($existingUpdatedAt)) {
            return false;
        }

        // Same timestamp: keep strongest status to avoid trial/active being downgraded by pending.
        return $this->statusPriority($incomingStatus) >= $this->statusPriority($existingStatus);
    }

    private function statusPriority(string $status): int
    {
        return match ($status) {
            'active' => 4,
            'trial' => 3,
            'pending' => 2,
            'inactive', 'expired', 'cancelled' => 1,
            default => 0,
        };
    }

    private function subscriptionHasCurrentAccess(Subscription $subscription): bool
    {
        $status = strtolower((string) $subscription->status);

        if ($status === 'active') {
            return !$subscription->expires_at || $subscription->expires_at->gte(now());
        }

        if (in_array($status, ['trial', 'pending'], true)) {
            return !$subscription->trial_end || $subscription->trial_end->gte(now());
        }

        return false;
    }

    private function subscriptionAwardReference(string $subscriptionExternalId, string $status, string $activeOnDate): string
    {
        if ($status === 'active') {
            return 'sub:' . $subscriptionExternalId . ':active:' . $activeOnDate;
        }

        // Trial and pending keep their existing one-time welcome award behavior.
        return 'sub:' . $subscriptionExternalId;
    }

    private function findSubscriptionByProviderReference(string $providerReference, bool $lockForUpdate = false): ?Subscription
    {
        $query = Subscription::query()
            ->where(function ($query) use ($providerReference): void {
                $query->where('provider_reference', $providerReference)
                    ->orWhere('subscription_id', $providerReference)
                    ->orWhere('provider_subscription_id', $providerReference);
            });

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function isDuplicateKeyIntegrityViolation(QueryException $e): bool
    {
        $sqlState = isset($e->errorInfo[0]) ? (string) $e->errorInfo[0] : (string) $e->getCode();
        $driverCode = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : null;

        return $sqlState === '23000'
            || $driverCode === 1062
            || str_contains($e->getMessage(), 'Duplicate entry');
    }
}

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
            'data.phone' => 'required|string|max:20',
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
            $phone = $this->normalizePhone((string) ($subscriptionData['phone'] ?? ''));
            $user = User::where('phone', $phone)->first();
            if (!$user) {
                $user = User::create([
                    'phone' => $phone,
                    'name' => 'Subscriber' . substr(preg_replace('/\D/', '', $phone), -6),
                    'password' => Hash::make(Str::random(32)),
                    'locale' => 'en',
                    'tokens_balance' => 0,
                    'daily_bonus_claimed_at' => null,
                ]);
            }

            $subscriptionExternalId = (string) ($subscriptionData['id'] ?? '');
            $status = (string) ($subscriptionData['status'] ?? 'pending');
            $activationDate = !empty($subscriptionData['activation_date']) ? Carbon::parse((string) $subscriptionData['activation_date']) : null;
            $trialEnd = !empty($subscriptionData['trial_end']) ? Carbon::parse((string) $subscriptionData['trial_end']) : null;
            $expiresAt = !empty($subscriptionData['expires_at']) ? Carbon::parse((string) $subscriptionData['expires_at']) : null;
            $insertedAt = !empty($subscriptionData['inserted_at']) ? Carbon::parse((string) $subscriptionData['inserted_at']) : now();
            $updatedAt = !empty($subscriptionData['updated_at']) ? Carbon::parse((string) $subscriptionData['updated_at']) : now();
            $tokensToAward = (int) env('SUBSCRIPTION_DAILY_TOKENS', 20);

            DB::transaction(function () use ($request, $user, $phone, $subscriptionExternalId, $status, $activationDate, $trialEnd, $expiresAt, $insertedAt, $updatedAt, $tokensToAward): void {
                $subscription = Subscription::query()->updateOrCreate(
                    ['subscription_id' => $subscriptionExternalId],
                    [
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
                        'active_on_date' => ($activationDate ?? $insertedAt)->toDateString(),
                        'provider_reference' => $subscriptionExternalId,
                        'amount_etb' => (float) env('SUBSCRIPTION_DAILY_PRICE_ETB', 2),
                        'tokens_awarded' => 0,
                        'starts_at' => $activationDate ?? $insertedAt,
                        'ends_at' => $expiresAt ?? $trialEnd ?? now(),
                        'paid_at' => $activationDate,
                        'raw_payload' => $request->all(),
                        'created_at' => $insertedAt,
                        'updated_at' => $updatedAt,
                    ]
                );

                $isEligible = in_array($status, ['active', 'trial'], true);
                $shouldAward = $isEligible && ($request->input('type') === 'new_subscription');

                if ($shouldAward) {
                    $alreadyAwarded = $user->transactions()
                        ->where('type', 'purchase')
                        ->where('reference', 'sub:' . $subscriptionExternalId)
                        ->exists();

                    if (!$alreadyAwarded) {
                        $user->increment('tokens_balance', $tokensToAward);
                        $user->transactions()->create([
                            'amount' => $tokensToAward,
                            'type' => 'purchase',
                            'description' => 'Subscription activation reward',
                            'meta' => [
                                'subscription_id' => $subscriptionExternalId,
                                'status' => $status,
                            ],
                            'status' => 'completed',
                            'reference' => 'sub:' . $subscriptionExternalId,
                        ]);

                        $subscription->update(['tokens_awarded' => $tokensToAward]);
                    }
                } else {
                    $alreadyAwarded = $user->transactions()
                        ->where('type', 'purchase')
                        ->where('reference', 'sub:' . $subscriptionExternalId)
                        ->exists();

                    if ($alreadyAwarded) {
                        $subscription->update(['tokens_awarded' => $tokensToAward]);
                    } else {
                        $subscription->update(['tokens_awarded' => 0]);
                    }
                }
            });

            $response = [
                'success' => true,
                'message' => 'Subscription callback processed successfully',
                'data' => [
                    'phone' => $phone,
                    'subscription_id' => $subscriptionExternalId,
                    'status' => $status,
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
        $phone = preg_replace('/[^\d+]/', '', $phone);
        if (str_starts_with($phone, '0')) {
            return '+251' . substr($phone, 1);
        }
        if (str_starts_with($phone, '251')) {
            return '+' . $phone;
        }
        if (!str_starts_with($phone, '+251')) {
            return '+251' . ltrim($phone, '+');
        }

        return $phone;
    }
}

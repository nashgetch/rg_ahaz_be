<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PaymentWebhookController extends Controller
{
    public function pay(Request $request): JsonResponse
    {
        $expectedSecret = (string) env('BILLING_WEBHOOK_SECRET', '');
        $providedSecret = (string) $request->header('X-Billing-Secret', '');

        if ($expectedSecret !== '' && !hash_equals($expectedSecret, $providedSecret)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized webhook request',
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'phone' => 'required|string',
            'status' => 'required|string|in:paid,success',
            'provider_reference' => 'required|string|max:191',
            'external_reference' => 'nullable|string|max:191',
            'amount_etb' => 'nullable|numeric|min:0',
            'paid_at' => 'nullable|date',
            'tokens' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $phone = $this->normalizePhone((string) $request->input('phone'));
        $user = User::where('phone', $phone)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found for provided phone',
            ], 404);
        }

        $providerReference = (string) $request->input('provider_reference');
        $alreadyProcessed = Subscription::where('provider_reference', $providerReference)->exists();
        if ($alreadyProcessed) {
            return response()->json([
                'success' => true,
                'message' => 'Payment already processed',
            ]);
        }

        $tokensToAward = (int) ($request->integer('tokens') ?: env('SUBSCRIPTION_DAILY_TOKENS', 20));
        $amountEtb = (float) ($request->input('amount_etb') ?? env('SUBSCRIPTION_DAILY_PRICE_ETB', 2));
        $paidAt = $request->filled('paid_at') ? Carbon::parse((string) $request->input('paid_at')) : now();

        DB::transaction(function () use ($user, $phone, $request, $providerReference, $tokensToAward, $amountEtb, $paidAt): void {
            $currentActive = $user->subscriptions()
                ->where('status', 'active')
                ->where('ends_at', '>=', now())
                ->latest('ends_at')
                ->first();

            $startsAt = $currentActive ? $currentActive->ends_at->copy() : $paidAt->copy();
            $endsAt = $startsAt->copy()->addDay();

            Subscription::create([
                'user_id' => $user->id,
                'phone' => $phone,
                'status' => 'active',
                'provider_reference' => $providerReference,
                'external_reference' => $request->input('external_reference'),
                'amount_etb' => $amountEtb,
                'tokens_awarded' => $tokensToAward,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'paid_at' => $paidAt,
                'raw_payload' => $request->all(),
            ]);

            $user->awardTokens($tokensToAward, 'purchase', 'Daily subscription payment reward', [
                'provider_reference' => $providerReference,
                'amount_etb' => $amountEtb,
                'paid_at' => $paidAt->toIso8601String(),
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Payment processed and subscription saved',
            'data' => [
                'phone' => $phone,
                'tokens_awarded' => $tokensToAward,
                'has_active_subscription' => $user->fresh()->hasActiveSubscription(),
            ],
        ]);
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

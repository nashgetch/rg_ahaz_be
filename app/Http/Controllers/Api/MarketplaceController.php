<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceItem;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserMarketplacePurchase;
use App\Services\SmsService;
use App\Support\EthiopianPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class MarketplaceController extends Controller
{
    public function __construct(private readonly SmsService $smsService)
    {
    }

    public function items(): JsonResponse
    {
        $items = MarketplaceItem::query()
            ->where('is_active', true)
            ->orderBy('token_cost')
            ->get()
            ->map(function (MarketplaceItem $item) {
                return [
                    'id' => $item->id,
                    'code' => $item->code,
                    'name' => $item->name,
                    'category' => $item->category,
                    'description' => $item->description,
                    'token_cost' => $item->token_cost,
                    'etb_value' => (float) $item->etb_value,
                    'metadata' => $item->metadata ?? [],
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
    }

    public function purchases(Request $request): JsonResponse
    {
        $user = $request->user();
        $limit = min((int) $request->get('limit', 30), 100);

        $purchases = $user->marketplacePurchases()
            ->with('item:id,code,name,category')
            ->latest('purchased_at')
            ->limit($limit)
            ->get()
            ->map(function (UserMarketplacePurchase $purchase) {
                return [
                    'id' => $purchase->id,
                    'reference' => $purchase->reference,
                    'status' => $purchase->status,
                    'token_cost' => $purchase->token_cost,
                    'etb_value' => (float) $purchase->etb_value,
                    'purchased_at' => $purchase->purchased_at,
                    'redemption_phone' => $purchase->redemption_phone,
                    'item' => [
                        'id' => $purchase->item?->id,
                        'code' => $purchase->item?->code,
                        'name' => $purchase->item?->name,
                        'category' => $purchase->item?->category,
                    ],
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $purchases,
        ]);
    }

    public function purchase(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'item_id' => 'required|integer|exists:marketplace_items,id',
            'redemption_phone' => 'nullable|string|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $item = MarketplaceItem::query()
            ->where('id', $request->integer('item_id'))
            ->where('is_active', true)
            ->first();

        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => 'Marketplace item is unavailable',
            ], 422);
        }

        $redemptionPhone = null;
        if ($this->isAirtimeItem($item)) {
            $phoneRaw = $request->input('redemption_phone');
            if ($phoneRaw === null || trim((string) $phoneRaw) === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'Phone number is required for airtime rewards',
                    'errors' => ['redemption_phone' => ['Please enter the phone number that should receive the airtime.']],
                ], 422);
            }

            $normalized = EthiopianPhone::normalize((string) $phoneRaw);
            if (!EthiopianPhone::isValid($normalized)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid Ethiopian phone number for airtime delivery',
                    'errors' => ['redemption_phone' => ['Enter a valid Ethiopian mobile number (9XXXXXXXX).']],
                ], 422);
            }
            $redemptionPhone = $normalized;
        }

        $purchase = DB::transaction(function () use ($request, $item, $redemptionPhone) {
            /** @var User $user */
            $user = User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();

            if ($user->earned_tokens_balance < $item->token_cost) {
                throw new HttpResponseException(response()->json([
                    'success' => false,
                    'message' => 'Only gameplay-earned tokens can be used for rewards',
                ], 422));
            }

            $user->decrement('earned_tokens_balance', $item->token_cost);

            $purchase = UserMarketplacePurchase::create([
                'user_id' => $user->id,
                'marketplace_item_id' => $item->id,
                'token_cost' => $item->token_cost,
                'etb_value' => $item->etb_value,
                'status' => 'completed',
                'reference' => $this->generatePurchaseReference(),
                'redemption_phone' => $redemptionPhone,
                'metadata' => [
                    'item_code' => $item->code,
                    'category' => $item->category,
                ],
                'purchased_at' => now(),
            ]);

            Transaction::create([
                'user_id' => $user->id,
                'amount' => -$item->token_cost,
                'type' => 'powerup',
                'description' => "Marketplace redemption: {$item->name}",
                'meta' => array_filter([
                    'marketplace_purchase_id' => $purchase->id,
                    'marketplace_item_id' => $item->id,
                    'item_code' => $item->code,
                    'redemption_phone' => $redemptionPhone,
                ]),
                'status' => 'completed',
                'reference' => $purchase->reference,
            ]);

            return [$purchase->load('item'), $user->fresh()];
        });

        [$createdPurchase, $updatedUser] = $purchase;
        $this->sendMarketplaceRedemptionSms($updatedUser, $createdPurchase);

        return response()->json([
            'success' => true,
            'message' => 'Marketplace item redeemed successfully',
            'data' => [
                'purchase' => [
                    'id' => $createdPurchase->id,
                    'reference' => $createdPurchase->reference,
                    'status' => $createdPurchase->status,
                    'token_cost' => $createdPurchase->token_cost,
                    'etb_value' => (float) $createdPurchase->etb_value,
                    'purchased_at' => $createdPurchase->purchased_at,
                    'redemption_phone' => $createdPurchase->redemption_phone,
                    'item' => [
                        'id' => $createdPurchase->item?->id,
                        'code' => $createdPurchase->item?->code,
                        'name' => $createdPurchase->item?->name,
                        'category' => $createdPurchase->item?->category,
                    ],
                ],
                'balances' => [
                    'tokens' => (int) $updatedUser->tokens_balance,
                    'earned_tokens' => (int) $updatedUser->earned_tokens_balance,
                    'locked_tokens' => (float) $updatedUser->locked_bet_tokens,
                    'available_tokens' => (float) $updatedUser->available_tokens,
                ],
            ],
        ]);
    }

    private function isAirtimeItem(MarketplaceItem $item): bool
    {
        $category = strtolower((string) $item->category);

        return str_contains($category, 'airtime');
    }

    private function generatePurchaseReference(): string
    {
        return 'MKP-' . now()->format('YmdHis') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    }

    private function sendMarketplaceRedemptionSms(User $user, UserMarketplacePurchase $purchase): void
    {
        $destination = $purchase->redemption_phone ?: $user->phone;
        if (empty($destination) || empty($purchase->item?->name)) {
            return;
        }

        $isAmharic = ($user->locale ?? 'en') === 'am';
        $message = $isAmharic
            ? "እንኳን ደስ አለዎት! {$purchase->item->name} ሽልማትዎን በተሳካ ሁኔታ ተቀብለዋል። አመሰግናለን - AHAZ"
            : "Congratulations! You successfully redeemed {$purchase->item->name}. Thank you for playing on AHAZ.";

        $smsResult = $this->smsService->sendOtp($destination, $message);
        if (!($smsResult['success'] ?? false)) {
            Log::warning('Marketplace redemption SMS failed.', [
                'user_id' => $user->id,
                'phone' => $destination,
                'purchase_id' => $purchase->id,
                'reference' => $purchase->reference,
                'item_name' => $purchase->item?->name,
                'sms_result' => $smsResult,
            ]);
        }
    }
}

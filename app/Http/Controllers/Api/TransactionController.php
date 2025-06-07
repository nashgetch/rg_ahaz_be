<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TransactionController extends Controller
{
    /**
     * Get user's transaction history
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $limit = min($request->get('limit', 20), 100);
        $type = $request->get('type'); // purchase, reward, bonus

        $query = $user->transactions()->orderBy('created_at', 'desc');

        if ($type) {
            $query->where('type', $type);
        }

        $transactions = $query->limit($limit)->get()->map(function ($transaction) {
            return [
                'id' => $transaction->id,
                'type' => $transaction->type,
                'amount' => $transaction->amount,
                'tokens' => $transaction->tokens,
                'status' => $transaction->status,
                'description' => $transaction->description,
                'reference' => $transaction->reference,
                'created_at' => $transaction->created_at
            ];
        });

        // Get summary statistics
        $stats = [
            'total_purchased' => $user->transactions()
                ->where('type', 'purchase')
                ->where('status', 'completed')
                ->sum('amount'),
            'total_tokens_purchased' => $user->transactions()
                ->where('type', 'purchase')
                ->where('status', 'completed')
                ->sum('tokens'),
            'total_rewards_earned' => $user->transactions()
                ->where('type', 'reward')
                ->sum('tokens'),
            'total_bonuses_claimed' => $user->transactions()
                ->where('type', 'bonus')
                ->sum('tokens')
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'transactions' => $transactions,
                'stats' => $stats
            ]
        ]);
    }

    /**
     * Purchase tokens
     */
    public function purchaseTokens(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'package' => 'required|string|in:small,medium,large,mega',
            'payment_method' => 'required|string|in:telebirr,cbe_birr,mpesa,paypal',
            'phone' => 'sometimes|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();
        $package = $request->package;
        $paymentMethod = $request->payment_method;

        // Define token packages
        $packages = [
            'small' => ['tokens' => 100, 'price' => 10, 'currency' => 'ETB'],
            'medium' => ['tokens' => 250, 'price' => 20, 'currency' => 'ETB'],
            'large' => ['tokens' => 500, 'price' => 35, 'currency' => 'ETB'],
            'mega' => ['tokens' => 1000, 'price' => 60, 'currency' => 'ETB']
        ];

        if (!isset($packages[$package])) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid package'
            ], 422);
        }

        $packageData = $packages[$package];

        DB::beginTransaction();
        try {
            // Create transaction record
            $transaction = Transaction::create([
                'user_id' => $user->id,
                'type' => 'purchase',
                'amount' => $packageData['price'],
                'currency' => $packageData['currency'],
                'tokens' => $packageData['tokens'],
                'status' => 'pending',
                'payment_method' => $paymentMethod,
                'reference' => $this->generateReference(),
                'description' => "Purchase {$packageData['tokens']} tokens - {$package} package",
                'metadata' => [
                    'package' => $package,
                    'phone' => $request->phone
                ]
            ]);

            // Initiate payment with payment provider
            $paymentResult = $this->initiatePayment($transaction, $paymentMethod, $request->phone);

            if (!$paymentResult['success']) {
                $transaction->update(['status' => 'failed']);
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Payment initiation failed',
                    'error' => $paymentResult['error']
                ], 422);
            }

            $transaction->update([
                'payment_reference' => $paymentResult['reference'],
                'payment_url' => $paymentResult['payment_url'] ?? null
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Payment initiated successfully',
                'data' => [
                    'transaction_id' => $transaction->id,
                    'reference' => $transaction->reference,
                    'payment_reference' => $paymentResult['reference'],
                    'payment_url' => $paymentResult['payment_url'] ?? null,
                    'amount' => $packageData['price'],
                    'currency' => $packageData['currency'],
                    'tokens' => $packageData['tokens'],
                    'payment_method' => $paymentMethod
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Token purchase failed', [
                'user_id' => $user->id,
                'package' => $package,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Purchase failed'
            ], 500);
        }
    }

    /**
     * Generate unique transaction reference
     */
    private function generateReference(): string
    {
        return 'GH' . strtoupper(uniqid()) . rand(1000, 9999);
    }

    /**
     * Initiate payment with payment provider
     */
    private function initiatePayment(Transaction $transaction, string $paymentMethod, ?string $phone): array
    {
        // This is a mock implementation
        // In production, integrate with actual payment providers:
        // - Telebirr API
        // - CBE Birr API
        // - M-Pesa API
        // - PayPal API

        switch ($paymentMethod) {
            case 'telebirr':
                return $this->initiateTelebirrPayment($transaction, $phone);
            case 'cbe_birr':
                return $this->initiateCBEBirrPayment($transaction, $phone);
            case 'mpesa':
                return $this->initiateMpesaPayment($transaction, $phone);
            case 'paypal':
                return $this->initiatePayPalPayment($transaction);
            default:
                return ['success' => false, 'error' => 'Unsupported payment method'];
        }
    }

    /**
     * Mock Telebirr payment initiation
     */
    private function initiateTelebirrPayment(Transaction $transaction, ?string $phone): array
    {
        // Mock implementation - replace with actual Telebirr API integration
        Log::info('Telebirr payment initiated', [
            'transaction_id' => $transaction->id,
            'amount' => $transaction->amount,
            'phone' => $phone
        ]);

        return [
            'success' => true,
            'reference' => 'TB' . uniqid(),
            'payment_url' => null // Telebirr typically uses USSD
        ];
    }

    /**
     * Mock CBE Birr payment initiation
     */
    private function initiateCBEBirrPayment(Transaction $transaction, ?string $phone): array
    {
        // Mock implementation - replace with actual CBE Birr API integration
        Log::info('CBE Birr payment initiated', [
            'transaction_id' => $transaction->id,
            'amount' => $transaction->amount,
            'phone' => $phone
        ]);

        return [
            'success' => true,
            'reference' => 'CBE' . uniqid(),
            'payment_url' => null
        ];
    }

    /**
     * Mock M-Pesa payment initiation
     */
    private function initiateMpesaPayment(Transaction $transaction, ?string $phone): array
    {
        // Mock implementation - replace with actual M-Pesa API integration
        Log::info('M-Pesa payment initiated', [
            'transaction_id' => $transaction->id,
            'amount' => $transaction->amount,
            'phone' => $phone
        ]);

        return [
            'success' => true,
            'reference' => 'MP' . uniqid(),
            'payment_url' => null
        ];
    }

    /**
     * Mock PayPal payment initiation
     */
    private function initiatePayPalPayment(Transaction $transaction): array
    {
        // Mock implementation - replace with actual PayPal API integration
        Log::info('PayPal payment initiated', [
            'transaction_id' => $transaction->id,
            'amount' => $transaction->amount
        ]);

        return [
            'success' => true,
            'reference' => 'PP' . uniqid(),
            'payment_url' => 'https://paypal.com/checkout/' . uniqid()
        ];
    }
} 
<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsService
{
    public function sendOtp(string $phone, string $message): array
    {
        $baseUrl = (string) config('services.sms.base_url');
        $apiKey = (string) config('services.sms.api_key');

        if ($baseUrl === '') {
            Log::warning('SMS endpoint is missing, skipping SMS send.');
            return [
                'success' => false,
                'message' => 'SMS endpoint is not configured.',
            ];
        }

        $payload = [
            'phone' => ltrim($phone, '+'),
            'message' => $message,
        ];

        try {
            $request = Http::timeout(15)
                ->acceptJson()
                ->asJson();

            if ($apiKey !== '') {
                $request = $request->withHeaders([
                    'X-API-KEY' => $apiKey,
                ]);
            }

            $response = $request->post($baseUrl, $payload);

            $raw = $response->body();
            $decoded = json_decode($raw, true);

            if (!$response->successful()) {
                Log::error('SMS provider request failed.', [
                    'status' => $response->status(),
                    'phone' => $phone,
                    'response' => $raw,
                ]);

                return [
                    'success' => false,
                    'message' => 'SMS provider rejected request.',
                    'status_code' => $response->status(),
                    'provider_response' => $decoded ?? $raw,
                ];
            }

            return [
                'success' => true,
                'message' => 'SMS sent successfully.',
                'provider_response' => $decoded ?? $raw,
            ];
        } catch (\Throwable $e) {
            Log::error('Failed sending SMS OTP.', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'SMS send failed.',
            ];
        }
    }
}

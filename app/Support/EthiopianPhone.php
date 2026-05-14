<?php

declare(strict_types=1);

namespace App\Support;

final class EthiopianPhone
{
    public static function normalize(string $phone): string
    {
        $phone = preg_replace('/[^\d+]/', '', $phone) ?? '';

        if (str_starts_with($phone, '0')) {
            return '+251' . substr($phone, 1);
        }
        if (str_starts_with($phone, '251')) {
            return '+' . $phone;
        }
        if (!str_starts_with($phone, '+251')) {
            return '+251' . $phone;
        }

        return $phone;
    }

    public static function isValid(string $normalizedPhone): bool
    {
        return (bool) preg_match('/^\+251[789]\d{8}$/', $normalizedPhone);
    }
}

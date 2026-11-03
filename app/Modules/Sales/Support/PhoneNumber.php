<?php

namespace App\Modules\Sales\Support;

/** Vietnamese phone numbers: one canonical form (0xxxxxxxxx) so the same buyer is always found. */
final class PhoneNumber
{
    public static function normalize(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone) ?? '';
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($digits, '84') && strlen($digits) >= 11) {
            $digits = '0'.substr($digits, 2);
        }

        return $digits;
    }

    /** Keyed hash for exact lookups of an encrypted phone (blind index). */
    public static function hash(?string $normalized): ?string
    {
        return $normalized === null ? null : hash_hmac('sha256', $normalized, (string) config('app.key'));
    }

    public static function isValid(?string $normalized): bool
    {
        return $normalized !== null && preg_match('/^0\d{8,10}$/', $normalized) === 1;
    }
}
